<?php

namespace Tests\Feature\Remote;

use App\Domain\Projects\ProjectWizard;
use App\Domain\Remote\CheckRemoteDestination;
use App\Domain\Remote\RemoteAgent;
use App\Domain\Remote\RemoteConsolidationPreparation;
use App\Domain\Remote\RemoteConsolidationWorkflow;
use App\Domain\Remote\RemoteServerConnections;
use App\Enums\ConnectionStatus;
use App\Enums\ConnectionType;
use App\Enums\ExecutionStatus;
use App\Enums\ExecutionStepStatus;
use App\Enums\ProjectStatus;
use App\Enums\ServerRole;
use App\Enums\UserRole;
use App\Jobs\ReconcileRemoteAgentOperation;
use App\Models\Artifact;
use App\Models\Conflict;
use App\Models\Execution;
use App\Models\Project;
use App\Models\RemoteOperation;
use App\Models\Server;
use App\Models\SourcePackage;
use App\Models\ToolDistribution;
use App\Models\User;
use Database\Seeders\ToolCatalogSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Domain\DomainTestCase;
use Tests\Support\QueuedRemoteTransport;

/**
 * A CONSOLIDATE project in REMOTE_CONSOLIDATION mode runs the Consolidator's
 * assistant on its destination server, waits for the administrator when the
 * assistant asks (exit 22), resumes on Continue and reaches REVIEW only when
 * the assistant itself closed the consolidation.
 */
class RemoteConsolidationExecutionTest extends DomainTestCase
{
    private QueuedRemoteTransport $transport;

    private User $admin;

    private Project $project;

    private Server $server;

    /** @var list<SourcePackage> */
    private array $packages = [];

    private int $attempts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['toolkit.features.remote_agent.enabled' => true, 'toolkit.features.consolidador_800.enabled' => true,
            'toolkit.workspaces.root' => Storage::fake('remote-consolidation')->path('workspaces')]);
        $this->transport = QueuedRemoteTransport::bind($this->app);
        $this->seed(ToolCatalogSeeder::class);
        $this->admin = $this->user(UserRole::ADMIN);
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $this->post(route('projects.store'), ['name' => 'Consolidación remota', 'type' => 'CONSOLIDATE'])->assertRedirect();
        $this->project = Project::query()->where('type', 'CONSOLIDATE')->sole();
        $distribution = ToolDistribution::query()->with('toolVersion')->where('key', CheckRemoteDestination::DISTRIBUTION_KEY)->sole();
        $this->artisan('consolidator:enable-remote')->assertSuccessful();
        $collect = app(ProjectWizard::class)->create($this->admin, ['name' => 'Recolección', 'type' => 'COLLECT']);
        $this->packages = [$this->package($collect, 'pregrado'), $this->package($collect, 'posgrado')];
        $this->server = $this->project->servers()->create(['name' => 'Destino EC2', 'role' => ServerRole::DESTINATION, 'host' => 'campus.example.edu',
            'metadata' => ['remote_tools' => [CheckRemoteDestination::DISTRIBUTION_KEY => ['tree_sha256' => $distribution->distribution_sha256]],
                'remote_destinations' => ['campus' => ['ready' => true, 'report' => ['target_running' => true, 'destination_written' => false]]],
                'remote_destination_preparations' => ['campus' => ['state' => 'SUCCEEDED']],
                'remote_destination_copias' => ['campus' => ['write_locked' => false, 'packages' => $this->listing()]]]]);
        app(RemoteServerConnections::class)->configure($this->server, ConnectionType::AWS_SSM,
            ['instance_id' => 'i-0d69555bdfa275322', 'region' => 'us-east-2'], null, null)->update(['status' => ConnectionStatus::VALID]);
        $this->server->refresh();
    }

    public function test_configuration_accepts_a_destination_profile_and_distinct_current_packages(): void
    {
        $source = $this->project->servers()->create(['name' => 'Origen', 'role' => ServerRole::SOURCE, 'host' => 'origen.example.edu']);
        $url = route('projects.consolidation.remote-configuration', $this->project);
        $this->put($url, [...$this->input(), 'server_uuid' => $source->uuid])->assertSessionHasErrors('consolidation');
        $this->put($url, [...$this->input(), 'source_packages' => [$this->packages[0]->uuid]])->assertSessionHasErrors('source_packages');
        $this->put($url, [...$this->input(), 'path' => '/srv'])->assertStatus(422);
        $revoked = $this->package(app(ProjectWizard::class)->create($this->admin, ['name' => 'Otra', 'type' => 'COLLECT']), 'revocado');
        SourcePackage::query()->whereKey($revoked->getKey())->update(['availability' => 'REVOKED', 'revoked_at' => now()]);
        $this->put($url, [...$this->input(), 'source_packages' => [$this->packages[0]->uuid, $revoked->uuid]])->assertSessionHasErrors('consolidation');
        $twin = $this->package(app(ProjectWizard::class)->create($this->admin, ['name' => 'Gemela', 'type' => 'COLLECT']), 'pregrado');
        $this->put($url, [...$this->input(), 'source_packages' => [$this->packages[0]->uuid, $twin->uuid]])->assertSessionHasErrors('consolidation');

        $this->put($url, $this->input())->assertRedirect()->assertSessionHasNoErrors();
        $options = $this->project->fresh()->configuration->settings['options'];
        $this->assertSame(['REMOTE_CONSOLIDATION', 'consolidator-remote.v1', 'campus', 'auto'],
            [$options['mode'], $options['schema_version'], $options['destination_profile'], $options['workers']]);
        $this->assertEqualsCanonicalizing(collect($this->packages)->pluck('sha256')->all(), array_column($options['source_packages'], 'sha256'));
    }

    public function test_preflight_requires_copias_to_hold_exactly_the_configured_packages(): void
    {
        $this->setCopias([...$this->listing(), ['name' => 'otro.zip', 'size' => 10, 'sidecar_sha256' => str_repeat('d', 64)]]);
        $this->put(route('projects.consolidation.remote-configuration', $this->project), $this->input())->assertRedirect();
        $this->post(route('projects.wizard.preflight', $this->project))->assertRedirect();
        $checks = collect($this->project->fresh()->configuration->settings['preflight']['checks'])->keyBy('id');
        $this->assertSame('ERROR', $checks['destination.copias']['result']);
        $this->assertStringContainsString('otro.zip', $checks['destination.copias']['detail']);
        foreach (['remote.connection', 'consolidator.distribution', 'remote.destination', 'destination.prepared', 'destination.unwritten'] as $id) {
            $this->assertSame('SUCCESS', $checks[$id]['result'], $id);
        }
        $this->assertSame('WARNING', $checks['consolidation.oauth']['result']);
        $this->post(route('projects.wizard.confirm', $this->project), ['configuration_version' => 2, 'accepted_warning_ids' => ['consolidation.oauth']])
            ->assertSessionHasErrors();

        $this->setCopias($this->listing());
        $this->post(route('projects.wizard.preflight', $this->project))->assertRedirect();
        $this->post(route('projects.wizard.confirm', $this->project), ['configuration_version' => 2, 'accepted_warning_ids' => ['consolidation.oauth']])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(ProjectStatus::READY, $this->project->fresh()->status);
    }

    public function test_the_assistant_waits_for_the_administrator_resumes_on_continue_and_reaches_review(): void
    {
        [$execution, $run] = $this->startExecution();
        $this->assertSame(RemoteConsolidationPreparation::ADAPTER_KEY, $execution->toolBinding->adapter_key);
        $this->assertSame('consolidator-remote.v1', $execution->toolBinding->configuration_snapshot['schema_version']);
        $this->assertSame(['campus', 'auto'], [$run->evidence['parameters']['destination'], $run->evidence['parameters']['workers']]);
        $this->assertCount(21, $execution->steps);

        // The assistant imported the packages and moved on to OAuth2.
        $this->queueStatus(alive: true);
        $this->queueReport(['stage' => '02-oauth2', 'status' => 'running', 'message' => '2. Configurar y validar Google OAuth2']);
        dispatch_sync(new ReconcileRemoteAgentOperation($run->id));
        $execution->refresh();
        $this->assertSame(5, $execution->progress);
        $this->assertSame([ExecutionStepStatus::SUCCESS, ExecutionStepStatus::RUNNING],
            [$this->stepStatus($execution, '01-importar-paquetes'), $this->stepStatus($execution, '02-oauth2')]);

        // It stops for the manual OAuth2 configuration: exit 22.
        $this->queueStatus(alive: false, exit: ['exit_code' => 22, 'cancel_requested' => false, 'finished_at' => '2026-10-21T12:00:00Z']);
        $this->queueReport(['stage' => '02-oauth2', 'status' => 'waiting_manual',
            'message' => 'Google OAuth2 aún no está listo. MOODLE_DB_PASSWORD=supersecreto123'], ['reports\\oauth2-configuracion-manual.txt']);
        $this->queueIntervention('oauth2', [
            'oauth2_manual' => "1. Registre en Google Cloud la URI https://campus.example.edu/admin/oauth2callback.php\nclient_secret=no-debe-salir\n",
            'oauth2_validation' => (string) json_encode(['callback_uri' => 'https://campus.example.edu/admin/oauth2callback.php',
                'issues' => ['No existe un servicio Google habilitado.', ['message' => 'Falta mostrarlo en la página de acceso.']]]),
            'oauth2_live' => null,
        ]);
        dispatch_sync(new ReconcileRemoteAgentOperation($run->id));
        $execution->refresh();
        $this->assertSame(ExecutionStatus::WAITING_USER_ACTION, $execution->status);
        $this->assertSame(ExecutionStepStatus::WAITING_USER, $this->stepStatus($execution, '02-oauth2'));
        $this->assertStringNotContainsString('supersecreto123', (string) json_encode($run->fresh()->evidence));
        $conflict = $execution->conflicts()->sole();
        $this->assertSame(['OAUTH2_CONFIGURATION', ['CONFIRM_COMPLETED']], [$conflict->type, $conflict->details['allowed_decisions']]);
        $this->assertSame('02-oauth2', $conflict->step->step_key);
        $page = $this->getJson(route('projects.executions.events', [$this->project, $execution]))->assertOk();
        $page->assertJsonPath('execution.consolidation.stage', '02-oauth2')
            ->assertJsonPath('execution.consolidation.review_paths.0', 'reports/oauth2-configuracion-manual.txt')
            ->assertJsonPath('execution.conflicts.0.intervention.callback_uri', 'https://campus.example.edu/admin/oauth2callback.php')
            ->assertJsonPath('execution.conflicts.0.intervention.issues', ['No existe un servicio Google habilitado.', 'Falta mostrarlo en la página de acceso.']);
        $this->assertStringContainsString('Registre en Google Cloud', (string) $page->json('execution.conflicts.0.intervention.instructions'));
        $this->assertStringNotContainsString('no-debe-salir', (string) json_encode($conflict->details));
        // Waiting is a resting state: the scheduler does not call the server.
        $this->artisan('executions:reconcile-remote-operations')->assertSuccessful();
        $this->assertSame(0, $this->transport->pending());

        // The audited decision on the intervention launches the next run on the same Execution.
        $this->transport->ok('copias-list', ['write_locked' => false, 'packages' => $this->listing()]);
        $this->queueLaunch();
        $this->resolve($execution, $conflict, 'CONFIRM_COMPLETED', 'oauth-done')->assertSessionHasNoErrors();
        $execution->refresh();
        $this->assertSame(ExecutionStatus::RUNNING, $execution->status);
        $second = $execution->remoteOperations()->orderByDesc('id')->first();
        $this->assertSame(['consolidator-run-2', ExecutionStepStatus::RUNNING], [$second->idempotency_key, $this->stepStatus($execution, '02-oauth2')]);
        $this->assertSame('RESOLVED', $conflict->fresh()->status->value);
        // The same decision again changes nothing.
        $this->resolve($execution, $conflict, 'CONFIRM_COMPLETED', 'oauth-done');
        $this->assertSame(2, $execution->remoteOperations()->count());

        // The assistant closes: stage 16 done, closure consolidated and the site package sealed.
        $this->queueStatus(alive: false, exit: ['exit_code' => 0, 'cancel_requested' => false, 'finished_at' => '2026-10-21T13:00:00Z']);
        $this->queueReport(['stage' => 'completed', 'status' => 'completed', 'message' => 'Consolidación verificada y cerrada.'], [],
            ['closure_status' => 'evidence_consolidated', 'courses_verified' => 14, 'total_courses_verified' => 15, 'failed_courses' => 0,
                'sources' => [['id' => 'pregrado'], ['id' => 'posgrado']]], ['package_sha256' => str_repeat('e', 64)], true);
        dispatch_sync(new ReconcileRemoteAgentOperation($second->id));
        $execution->refresh();
        $this->assertSame(ExecutionStatus::REVIEW, $execution->status);
        $this->assertSame(ProjectStatus::REVIEW, $this->project->fresh()->status);
        foreach (array_keys(RemoteConsolidationPreparation::STAGES) as $key) {
            $this->assertSame(ExecutionStepStatus::SUCCESS, $this->stepStatus($execution, $key), $key);
        }
        $verification = $execution->verifications()->sole();
        $this->assertSame(['consolidator-remote-closure', 'evidence_consolidated'], [$verification->key, $verification->details['closure']['closure_status']]);
        $this->assertSame($execution->review_fingerprint, $verification->fingerprint);
        $this->getJson(route('projects.executions.events', [$this->project, $execution]))->assertOk()
            ->assertJsonPath('execution.consolidation.site_package_sha256', str_repeat('e', 64))
            ->assertJsonPath('execution.consolidation.closure.total_courses_verified', 15);
        $this->assertSame(0, $this->transport->pending());
    }

    public function test_review_is_confirmed_with_the_closing_reports_after_a_live_check(): void
    {
        $execution = $this->reviewedConsolidation();
        $this->queueLiveReports(str_repeat('e', 64));

        $this->post(route('projects.executions.finalize', [$this->project, $execution]), [], ['Idempotency-Key' => 'finalize-consolidation'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $execution->refresh();
        $this->assertSame(ExecutionStatus::COMPLETED, $execution->status);
        $this->assertSame(ProjectStatus::COMPLETED, $this->project->fresh()->status);
        $this->assertSame(RemoteConsolidationPreparation::ADAPTER_KEY, $execution->completion_summary['adapter']);
        $this->assertSame(4, $execution->artifacts()->count(), 'Los cuatro informes de cierre; la copia integral queda en el destino.');
        $this->assertSame(ExecutionStepStatus::SUCCESS, $this->stepStatus($execution, 'finalization'));
        $json = $execution->artifacts()->where('type', 'JSON_REPORT')->sole();
        $report = json_decode((string) Storage::disk($json->disk)->get($json->path), true);
        $this->assertSame(['REMOTE_CONSOLIDATION', 'campus', str_repeat('e', 64), 'DESTINATION'], [$report['mode'], $report['destination']['profile'],
            $report['site_package']['sha256'], $report['site_package']['location']]);
        $this->assertEqualsCanonicalizing(collect($this->packages)->pluck('sha256')->all(), array_column($report['source_packages'], 'sha256'));
        $this->assertSame('evidence_consolidated', $report['closure']['closure_status']);
        $this->assertSame(0, $this->transport->pending(), 'El destino se lee en vivo solo al iniciar y al cerrar la finalización.');
    }

    public function test_a_destination_that_changed_after_review_is_never_confirmed(): void
    {
        $execution = $this->reviewedConsolidation();
        $this->queueLiveReports(str_repeat('f', 64));

        $this->post(route('projects.executions.finalize', [$this->project, $execution]), [], ['Idempotency-Key' => 'finalize-changed']);

        $this->assertNotSame(ExecutionStatus::COMPLETED, $execution->fresh()->status);
        $this->assertSame(0, $execution->artifacts()->where('type', 'FINAL_SUMMARY')->count());
    }

    public function test_a_blocked_assistant_fails_the_execution_with_its_message(): void
    {
        [$execution, $run] = $this->startExecution();
        $this->queueStatus(alive: false, exit: ['exit_code' => 20, 'cancel_requested' => false, 'finished_at' => '2026-10-21T12:00:00Z']);
        $this->queueReport(['stage' => '01-importar-paquetes', 'status' => 'blocked', 'message' => 'El paquete pregrado.zip no declara package_status=sealed.']);
        dispatch_sync(new ReconcileRemoteAgentOperation($run->id));
        $execution->refresh();
        $this->assertSame(ExecutionStatus::FAILED, $execution->status);
        $this->assertSame(ExecutionStepStatus::FAILED, $this->stepStatus($execution, '01-importar-paquetes'));
        $this->assertStringContainsString('package_status=sealed', (string) $execution->events()->where('type', 'consolidator.failed')->value('message'));
        $this->assertSame(0, $this->transport->pending());
    }

    public function test_a_waiting_consolidation_is_cancelled_without_calling_the_server(): void
    {
        [$execution, $run] = $this->startExecution();
        $this->queueStatus(alive: false, exit: ['exit_code' => 22, 'cancel_requested' => false, 'finished_at' => '2026-10-21T12:00:00Z']);
        $this->queueReport(['stage' => '04-identidades', 'status' => 'waiting_manual', 'message' => 'V8_IDENTITY_REVIEW_REQUIRED']);
        dispatch_sync(new ReconcileRemoteAgentOperation($run->id));
        $this->assertSame(ExecutionStatus::WAITING_USER_ACTION, $execution->fresh()->status);
        $this->assertSame('CONSOLIDATOR_INTERVENTION', $execution->conflicts()->sole()->type);

        $this->post(route('projects.executions.cancel', [$this->project, $execution]), [], ['Idempotency-Key' => 'cancel-waiting'])->assertRedirect();
        $this->assertSame(ExecutionStatus::CANCELLED, $execution->fresh()->status);
        $this->assertSame(0, $this->transport->pending());
    }

    public function test_plugins_are_revalidated_or_approved_through_the_typed_intervention(): void
    {
        [$execution, $run] = $this->startExecution();
        $this->stopAtPlugins($run, 'PLUGIN_TECHNICAL_VALIDATION_FAILED');
        $conflict = $execution->conflicts()->sole();
        $this->assertSame(['PLUGIN_INTERVENTION', ['CONFIRM_COMPLETED']], [$conflict->type, $conflict->details['allowed_decisions']]);
        $this->assertSame([['component' => 'mod_vpl', 'display_name' => 'VPL', 'observed_state' => 'missing']], $conflict->details['needs']);
        // Accepting is not offered until the assistant is ready for approval.
        $this->resolve($execution, $conflict, 'ACCEPT', 'too-early')->assertSessionHasErrors('decision');

        // Revalidating relaunches the assistant, which stops again, now ready for approval.
        $this->transport->ok('copias-list', ['write_locked' => false, 'packages' => $this->listing()]);
        $this->queueLaunch();
        $this->resolve($execution, $conflict, 'CONFIRM_COMPLETED', 'revalidate');
        $second = $execution->remoteOperations()->orderByDesc('id')->first();
        $this->stopAtPlugins($second, 'READY_FOR_APPROVAL');
        $ready = $execution->conflicts()->where('status', 'OPEN')->sole();
        $this->assertSame(['ACCEPT', 'CONFIRM_COMPLETED'], $ready->details['allowed_decisions']);
        $this->assertSame(['mod_vpl'], $ready->details['new_plugins']);

        // Approving runs the sealed approval on the destination, then the next run.
        $this->transport->ok('copias-list', ['write_locked' => false, 'packages' => $this->listing()]);
        $this->queueLaunch(RemoteConsolidationWorkflow::APPROVE_COMMAND);
        $this->resolve($execution, $ready, 'ACCEPT', 'approve');
        $approval = $execution->remoteOperations()->where('command_key', RemoteConsolidationWorkflow::APPROVE_COMMAND)->sole();
        $this->assertSame(ExecutionStatus::RUNNING, $execution->fresh()->status);
        $this->assertSame(1, $execution->events()->where('type', 'consolidator.plugins_approval_requested')->count());

        $this->queueStatus(alive: false, exit: ['exit_code' => 0, 'cancel_requested' => false, 'finished_at' => '2026-10-21T12:30:00Z'],
            command: RemoteConsolidationWorkflow::APPROVE_COMMAND);
        $this->transport->ok('copias-list', ['write_locked' => false, 'packages' => $this->listing()]);
        $this->queueLaunch();
        dispatch_sync(new ReconcileRemoteAgentOperation($approval->id));
        $third = $execution->remoteOperations()->where('command_key', RemoteConsolidationWorkflow::COMMAND)->orderByDesc('id')->first();
        $this->assertSame(['consolidator-run-3', ExecutionStatus::RUNNING], [$third->idempotency_key, $execution->fresh()->status]);
        $this->assertSame(0, $this->transport->pending());
    }

    public function test_a_failed_plugin_approval_waits_again_with_a_new_intervention(): void
    {
        [$execution, $run] = $this->startExecution();
        $this->stopAtPlugins($run, 'READY_FOR_APPROVAL');
        $this->transport->ok('copias-list', ['write_locked' => false, 'packages' => $this->listing()]);
        $this->queueLaunch(RemoteConsolidationWorkflow::APPROVE_COMMAND);
        $this->resolve($execution, $execution->conflicts()->sole(), 'ACCEPT', 'approve');
        $approval = $execution->remoteOperations()->where('command_key', RemoteConsolidationWorkflow::APPROVE_COMMAND)->sole();

        $this->queueStatus(alive: false, exit: ['exit_code' => 61, 'cancel_requested' => false, 'finished_at' => '2026-10-21T12:30:00Z'],
            command: RemoteConsolidationWorkflow::APPROVE_COMMAND);
        $this->queueIntervention('plugins', $this->pluginDocuments('READY_FOR_APPROVAL'));
        dispatch_sync(new ReconcileRemoteAgentOperation($approval->id));
        $this->assertSame(ExecutionStatus::WAITING_USER_ACTION, $execution->fresh()->status);
        $open = $execution->conflicts()->where('status', 'OPEN')->sole();
        $this->assertSame('PLUGIN_INTERVENTION', $open->type);
        $this->assertStringContainsString('código 61', (string) $open->details['message']);
        $this->assertSame(0, $this->transport->pending());
    }

    /** A consolidation the assistant closed in a single run, now in REVIEW. */
    private function reviewedConsolidation(): Execution
    {
        [$execution, $run] = $this->startExecution();
        $this->queueStatus(alive: false, exit: ['exit_code' => 0, 'cancel_requested' => false, 'finished_at' => '2026-10-21T13:00:00Z']);
        $this->queueReport(...$this->closedReport(str_repeat('e', 64)));
        dispatch_sync(new ReconcileRemoteAgentOperation($run->id));
        $this->assertSame(ExecutionStatus::REVIEW, $execution->fresh()->status);

        return $execution->fresh();
    }

    /** The destination as read live while confirming: when finalization starts and before it closes. */
    private function queueLiveReports(string $site): void
    {
        for ($read = 0; $read < 2; $read++) {
            $this->queueReport(...$this->closedReport($site));
        }
    }

    /** @return array{0: array<string, mixed>, 1: list<string>, 2: array<string, mixed>, 3: array<string, mixed>, 4: bool} */
    private function closedReport(string $site): array
    {
        return [['stage' => 'completed', 'status' => 'completed', 'message' => 'Consolidación verificada y cerrada.'], [],
            ['closure_status' => 'evidence_consolidated', 'courses_verified' => 14, 'total_courses_verified' => 15, 'failed_courses' => 0,
                'sources' => [['id' => 'pregrado'], ['id' => 'posgrado']]], ['package_sha256' => $site], true];
    }

    private function stopAtPlugins(RemoteOperation $run, string $checkpoint): void
    {
        $this->queueStatus(alive: false, exit: ['exit_code' => 22, 'cancel_requested' => false, 'finished_at' => '2026-10-21T12:00:00Z']);
        $this->queueReport(['stage' => '03-plugins', 'status' => 'WAITING_USER_ACTION', 'message' => 'PLUGIN_INTERVENTION_PENDING: instalación y revisión de plugins.']);
        $this->queueIntervention('plugins', $this->pluginDocuments($checkpoint));
        dispatch_sync(new ReconcileRemoteAgentOperation($run->id));
        $this->assertSame(ExecutionStatus::WAITING_USER_ACTION, $run->execution->fresh()->status);
    }

    /** @return array<string, string|null> */
    private function pluginDocuments(string $checkpoint): array
    {
        return [
            'plugin_needs' => (string) json_encode(['original_compatibility_needs' => [['component' => 'mod_vpl', 'display_name' => 'VPL', 'observed_state' => 'missing']]]),
            'plugin_diff' => $checkpoint === 'READY_FOR_APPROVAL' ? (string) json_encode(['newly_detected_plugins' => [['component' => 'mod_vpl', 'name' => 'VPL']],
                'updated_plugins' => [], 'removed_plugins' => []]) : null,
            'plugin_technical' => $checkpoint === 'READY_FOR_APPROVAL' ? null : (string) json_encode(['issues' => ['mod_vpl no está instalado.']]),
            'plugin_checkpoint' => (string) json_encode(['status' => $checkpoint]),
        ];
    }

    /** @param array<string, string|null> $documents */
    private function queueIntervention(string $kind, array $documents): void
    {
        $this->transport->ok('consolidator-intervention', ['destination' => 'campus', 'kind' => $kind,
            'documents' => array_map(fn (?string $document): ?string => $document === null ? null : base64_encode($document), $documents)]);
    }

    private function resolve(Execution $execution, Conflict $conflict, string $decision, string $key): TestResponse
    {
        return $this->post(route('projects.executions.conflicts.resolve', [$this->project, $execution, $conflict->id]),
            ['decision' => $decision, 'conflict_version' => $conflict->fresh()->version], ['Idempotency-Key' => 'resolve-'.$key.'-'.md5($key)]);
    }

    public function test_the_first_run_is_never_launched_onto_a_copias_that_changed(): void
    {
        $this->confirm();
        $this->transport->ok('copias-list', ['write_locked' => false, 'packages' => array_slice($this->listing(), 0, 1)]);
        $this->postJson(route('projects.executions.store', $this->project), ['configuration_version' => 2], ['Idempotency-Key' => 'start-changed'])->assertCreated();
        $execution = $this->project->executions()->sole();
        $this->assertSame(ExecutionStatus::FAILED, $execution->status);
        $this->assertSame(0, RemoteOperation::query()->count());
        $this->assertStringContainsString('posgrado', (string) $execution->events()->where('type', 'consolidator.failed')->value('message'));
    }

    public function test_the_report_keeps_whitelisted_fields_only(): void
    {
        $report = app(RemoteConsolidationWorkflow::class)->report([
            'assistant_state_base64' => base64_encode("\xEF\xBB\xBF".json_encode(['stage' => '13-aplicar-lote', 'status' => 'running', 'message' => 'token=abc123',
                'review_paths' => ['exports\\phase6\\plan.csv', '../../etc/passwd'], 'secret' => 'x'])),
            'workers_status_base64' => base64_encode((string) json_encode(['completed' => 3, 'total' => 10, 'active' => [['worker' => 1, 'source' => 'pregrado',
                'course_key' => 'c1', 'shortname' => 'MAT101', 'elapsed_seconds' => 5]]])),
            'closure_summary_base64' => 'too_large', 'site_package_summary_base64' => null, 'write_locked' => true,
        ]);

        $this->assertSame(['stage' => '13-aplicar-lote', 'status' => 'running'], $report['state']);
        $this->assertStringNotContainsString('abc123', (string) $report['message']);
        $this->assertSame(['exports/phase6/plan.csv'], $report['review_paths']);
        $this->assertSame([['worker' => 1, 'source' => 'pregrado', 'course_key' => 'c1', 'elapsed_seconds' => 5]], $report['workers']['active']);
        $this->assertNull($report['closure']);
        $this->assertTrue($report['write_locked']);
    }

    /** @return array{Execution, RemoteOperation} */
    private function startExecution(): array
    {
        $this->confirm();
        $this->transport->ok('copias-list', ['write_locked' => false, 'packages' => $this->listing()]);
        $this->queueLaunch();
        $this->postJson(route('projects.executions.store', $this->project), ['configuration_version' => 2],
            ['Idempotency-Key' => 'consolidation-start'])->assertCreated();
        $execution = $this->project->executions()->sole();
        $this->assertSame(ExecutionStatus::RUNNING, $execution->status);

        return [$execution->fresh(), $execution->remoteOperations()->sole()];
    }

    private function confirm(): void
    {
        $this->put(route('projects.consolidation.remote-configuration', $this->project), $this->input())->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('projects.wizard.preflight', $this->project))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('projects.wizard.confirm', $this->project), ['configuration_version' => 2, 'accepted_warning_ids' => ['consolidation.oauth']])
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        return ['server_uuid' => $this->server->uuid, 'destination_profile' => 'campus', 'workers' => 'auto',
            'source_packages' => collect($this->packages)->pluck('uuid')->all()];
    }

    /** @return list<array{name: string, size: int, sidecar_sha256: string}> */
    private function listing(): array
    {
        return array_map(fn (SourcePackage $package): array => ['name' => $package->source_id.'.zip', 'size' => 4096, 'sidecar_sha256' => $package->sha256],
            $this->packages);
    }

    /** @param list<array<string, mixed>> $packages */
    private function setCopias(array $packages): void
    {
        $metadata = $this->server->refresh()->metadata;
        $metadata['remote_destination_copias']['campus'] = ['write_locked' => false, 'packages' => $packages];
        $this->server->update(['metadata' => $metadata]);
    }

    private function stepStatus(Execution $execution, string $key): ExecutionStepStatus
    {
        return $execution->steps()->where('step_key', $key)->sole()->status;
    }

    private function queueLaunch(string $command = RemoteConsolidationWorkflow::COMMAND): void
    {
        $this->transport->okWith('launch', fn (string $script): array => ['operation_uuid' => QueuedRemoteTransport::operationUuid($script),
            'created' => true, 'launch' => $this->launchEvidence(QueuedRemoteTransport::operationUuid($script), $command)]);
    }

    /** @param array<string, mixed>|null $exit */
    private function queueStatus(bool $alive, ?array $exit = null, string $command = RemoteConsolidationWorkflow::COMMAND): void
    {
        $this->transport->okWith('status', function (string $script) use ($alive, $exit, $command): array {
            $uuid = QueuedRemoteTransport::operationUuid($script);
            $now = now()->utc()->toIso8601String();

            return ['operation_uuid' => $uuid, 'launch' => $this->launchEvidence($uuid, $command), 'run' => ['pid' => 7, 'started_at' => $now],
                'exit' => $exit, 'cancel_requested' => ($exit['cancel_requested'] ?? false) === true, 'process_alive' => $alive, 'heartbeat_at' => $now,
                'stdout_bytes' => 0, 'stderr_bytes' => 0, 'observed_at' => $now];
        });
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<string>  $paths
     * @param  array<string, mixed>|null  $closure
     * @param  array<string, mixed>|null  $site
     */
    private function queueReport(array $state, array $paths = [], ?array $closure = null, ?array $site = null, bool $written = false): void
    {
        $encode = fn (?array $document): ?string => $document === null ? null : base64_encode((string) json_encode($document));
        $this->transport->ok('consolidator-report', ['destination' => 'campus', 'write_locked' => $written,
            'assistant_state_base64' => $encode(['schema_version' => '1.0', 'assistant_version' => '8.0.0-linux-rc12', ...$state, 'review_paths' => $paths]),
            'workers_status_base64' => null, 'closure_summary_base64' => $encode($closure), 'site_package_summary_base64' => $encode($site),
            'observed_at' => now()->utc()->toIso8601String()]);
    }

    /** @return array<string, mixed> */
    private function launchEvidence(string $uuid, string $command = RemoteConsolidationWorkflow::COMMAND): array
    {
        $distribution = app(RemoteAgent::class)->distribution();

        return ['operation_uuid' => $uuid, 'command_key' => $command, 'launcher' => 'systemd', 'unit' => 'mwt-op-'.$uuid,
            'agent_version' => $distribution['version'], 'agent_sha256' => $distribution['sha256'], 'argv_sha256' => str_repeat('b', 64),
            'launched_at' => now()->utc()->toIso8601String(), 'parameters' => []];
    }

    /** A sealed package registered in S3 by a remote collection of another project. */
    private function package(Project $project, string $sourceId): SourcePackage
    {
        $execution = $this->execution($project, ExecutionStatus::COMPLETED, ++$this->attempts);
        $sha = hash('sha256', $sourceId.Str::uuid());
        $key = 'mwt/'.$project->uuid.'/'.$sourceId.'/'.$sha.'.zip';
        $artifact = Artifact::query()->create(['execution_id' => $execution->getKey(), 'type' => 'source_package', 'category' => 'SOURCE_PACKAGE',
            'storage_mode' => 'REFERENCE', 'disk' => 's3', 'path' => 'kevin-moodle-backups-2026/'.$key, 'filename' => $sourceId.'.zip',
            'mime_type' => 'application/zip', 'size' => 4096, 'sha256' => $sha,
            'metadata' => ['s3' => ['region' => 'us-east-2', 'bucket' => 'kevin-moodle-backups-2026', 'key' => $key]]]);

        return SourcePackage::query()->forceCreate(['uuid' => (string) Str::uuid(), 'project_id' => $project->getKey(),
            'producer_execution_id' => $execution->getKey(), 'artifact_id' => $artifact->getKey(), 'producer_tool_version' => '7.4.2-linux',
            'schema_version' => '1.0', 'source_id' => $sourceId, 'name' => $sourceId, 'size_bytes' => 4096, 'sha256' => $sha,
            'validation_state' => 'VALID', 'validated_at' => now(), 'availability' => 'AVAILABLE', 'sensitivity' => 'CONFIDENTIAL']);
    }
}
