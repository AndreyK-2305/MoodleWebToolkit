<?php

namespace Tests\Feature\Remote;

use App\Domain\Projects\ProjectWizard;
use App\Domain\Remote\CheckRemoteDestination;
use App\Domain\Remote\RemoteAgent;
use App\Domain\Remote\RemoteConsolidationBackup;
use App\Domain\Remote\RemoteConsolidationInterventions;
use App\Domain\Remote\RemoteConsolidationPreparation;
use App\Domain\Remote\RemoteConsolidationWorkflow;
use App\Domain\Remote\RemotePackageObjects;
use App\Domain\Remote\RemoteServerConnections;
use App\Domain\Remote\S3ClientFactory;
use App\Enums\ConnectionStatus;
use App\Enums\ConnectionType;
use App\Enums\ExecutionStatus;
use App\Enums\ExecutionStepStatus;
use App\Enums\ProjectStatus;
use App\Enums\ServerRole;
use App\Enums\UserRole;
use App\Jobs\ReconcileRemoteAgentOperation;
use App\Models\Artifact;
use App\Models\AuditLog;
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

    /** @var list<string> */
    private array $verifiedObjects = [];

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
            'oauth2_manual' => "1. Registre en Google Cloud la URI https://campus.example.edu/admin/oauth2callback.php\nclient_secret=no-debe-salir\n\n"
                ."5. Regrese al asistente y use reintentar/continuar. Si la ejecución\nestaba en segundo plano, ejecute de nuevo\n./INICIAR-SEGUNDO-PLANO.sh.\n",
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
        // The console steps are for the server's administrator, not for the web user.
        $this->assertStringContainsString('5. Vuelva a esta página y confirme la intervención.', (string) $page->json('execution.conflicts.0.intervention.instructions'));
        $this->assertStringNotContainsString('INICIAR-SEGUNDO-PLANO', (string) $page->json('execution.conflicts.0.intervention.instructions'));
        $this->assertStringContainsString('./INICIAR-SEGUNDO-PLANO.sh', (string) $page->json('execution.conflicts.0.intervention.server_instructions'));
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

    public function test_the_site_package_is_backed_up_in_review_verified_and_closed_with_the_execution(): void
    {
        $this->fakeS3Verification();
        $execution = $this->reviewedConsolidation();
        $this->queueLaunch(RemoteConsolidationBackup::COMMAND);
        $this->post(route('projects.executions.consolidation.backup', [$this->project, $execution]))->assertRedirect();
        $upload = $execution->remoteOperations()->where('command_key', RemoteConsolidationBackup::COMMAND)->sole();
        $this->assertSame([str_repeat('e', 64), $execution->uuid], [$upload->evidence['parameters']['sha'], $upload->evidence['parameters']['execution']]);
        $this->getJson(route('projects.executions.events', [$this->project, $execution]))->assertOk()
            ->assertJsonPath('execution.consolidation.backup.state', 'RUNNING')->assertJsonPath('execution.consolidation.can_backup', false);
        // Confirming waits for the backup to end.
        $this->post(route('projects.executions.finalize', [$this->project, $execution]), [], ['Idempotency-Key' => 'finalize-during-backup']);
        $this->assertSame(ExecutionStatus::REVIEW, $execution->fresh()->status);

        $key = 'mwt/'.$this->project->uuid.'/consolidated/'.$execution->uuid.'/'.str_repeat('e', 64).'.zip';
        $this->queueStatus(alive: false, exit: ['exit_code' => 0, 'cancel_requested' => false, 'finished_at' => '2026-10-21T14:00:00Z'],
            command: RemoteConsolidationBackup::COMMAND);
        $this->transport->ok('site-upload-report', ['operation_uuid' => $upload->operation_uuid, 'result_base64' => base64_encode((string) json_encode(
            ['bucket' => 'kevin-moodle-backups-2026', 'region' => 'us-east-2', 'key' => $key, 'sha256' => str_repeat('e', 64), 'size' => 900_000_000]))]);
        $this->transport->ok('copias-release', ['destination' => 'campus', 'released' => 2, 'freed_bytes' => 8192]);
        dispatch_sync(new ReconcileRemoteAgentOperation($upload->id));

        $backup = $execution->artifacts()->where('category', 'FULL_BACKUP')->sole();
        $this->assertSame(['REFERENCE', 's3', 'kevin-moodle-backups-2026/'.$key, str_repeat('e', 64)], [$backup->storage_mode, $backup->disk, $backup->path, $backup->sha256]);
        $this->assertSame(['kevin-moodle-backups-2026/'.$key], $this->verifiedObjects);
        $this->getJson(route('projects.executions.events', [$this->project, $execution]))->assertOk()
            ->assertJsonPath('execution.consolidation.backup.state', 'SUCCEEDED')->assertJsonPath('execution.consolidation.backup.released', 2)
            ->assertJsonPath('execution.consolidation.can_backup', false);
        $this->assertSame(ExecutionStatus::REVIEW, $execution->fresh()->status);

        $this->queueLiveReports(str_repeat('e', 64));
        $this->post(route('projects.executions.finalize', [$this->project, $execution]), [], ['Idempotency-Key' => 'finalize-with-backup'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(ExecutionStatus::COMPLETED, $execution->fresh()->status);
        $this->assertSame(5, $execution->artifacts()->count(), 'La referencia S3 del respaldo y los cuatro informes de cierre.');
        $json = $execution->artifacts()->where('type', 'JSON_REPORT')->sole();
        $report = json_decode((string) Storage::disk($json->disk)->get($json->path), true);
        $this->assertSame([$key, 'ADMINISTRATOR'], [$report['site_package']['backup']['key'], $report['site_package']['backup']['retention']]);
        $this->assertSame(0, $this->transport->pending());
    }

    public function test_a_failed_backup_can_be_taken_again(): void
    {
        $this->fakeS3Verification();
        $execution = $this->reviewedConsolidation();
        $this->queueLaunch(RemoteConsolidationBackup::COMMAND);
        $this->post(route('projects.executions.consolidation.backup', [$this->project, $execution]))->assertRedirect();
        $upload = $execution->remoteOperations()->where('command_key', RemoteConsolidationBackup::COMMAND)->sole();
        $this->queueStatus(alive: false, exit: ['exit_code' => 74, 'cancel_requested' => false, 'finished_at' => '2026-10-21T14:00:00Z'],
            command: RemoteConsolidationBackup::COMMAND);
        dispatch_sync(new ReconcileRemoteAgentOperation($upload->id));

        $this->assertSame(0, $execution->artifacts()->count());
        $this->getJson(route('projects.executions.events', [$this->project, $execution]))->assertOk()
            ->assertJsonPath('execution.consolidation.backup.state', 'FAILED')->assertJsonPath('execution.consolidation.can_backup', true);
        $this->queueLaunch(RemoteConsolidationBackup::COMMAND);
        $this->post(route('projects.executions.consolidation.backup', [$this->project, $execution]))->assertRedirect();
        $this->assertSame('consolidator-site-upload-2', $execution->remoteOperations()->where('command_key', RemoteConsolidationBackup::COMMAND)
            ->orderByDesc('id')->first()->idempotency_key);
        $this->assertSame(0, $this->transport->pending());
    }

    public function test_a_destination_that_changed_after_review_is_never_confirmed(): void
    {
        $execution = $this->reviewedConsolidation();
        $this->queueLiveReports(str_repeat('f', 64));

        $this->post(route('projects.executions.finalize', [$this->project, $execution]), [], ['Idempotency-Key' => 'finalize-changed']);

        $this->assertNotSame(ExecutionStatus::COMPLETED, $execution->fresh()->status);
        $this->assertSame(0, $execution->artifacts()->where('type', 'FINAL_SUMMARY')->count());
    }

    public function test_a_blocked_stage_waits_for_the_administrator_and_continues_on_the_same_execution(): void
    {
        [$execution, $run] = $this->startExecution();
        // Blocked on a configuration decision (exit 20): the assistant keeps its checkpoints.
        $this->queueStatus(alive: false, exit: ['exit_code' => 20, 'cancel_requested' => false, 'finished_at' => '2026-10-21T12:00:00Z']);
        $this->queueReport(['stage' => '05b-readiness', 'status' => 'blocked',
            'message' => 'V8_READINESS_INPUT_MISSING: exports/phase4/plan_summary.json']);
        dispatch_sync(new ReconcileRemoteAgentOperation($run->id));
        $execution->refresh();
        $this->assertSame(ExecutionStatus::WAITING_USER_ACTION, $execution->status);
        $this->assertSame(ExecutionStepStatus::WAITING_USER, $this->stepStatus($execution, '05b-readiness'));
        $conflict = $execution->conflicts()->sole();
        $this->assertSame(['CONSOLIDATOR_INTERVENTION', ['CONFIRM_COMPLETED']], [$conflict->type, $conflict->details['allowed_decisions']]);

        // Once the administrator set the policy, Continue resumes the assistant.
        $this->transport->ok('copias-list', ['write_locked' => true, 'packages' => []]);
        $this->queueLaunch();
        $this->resolve($execution, $conflict, 'CONFIRM_COMPLETED', 'theme-set')->assertSessionHasNoErrors();
        $this->assertSame(ExecutionStatus::RUNNING, $execution->fresh()->status);
        $this->assertSame('consolidator-run-2', $execution->remoteOperations()->orderByDesc('id')->value('idempotency_key'));
        $this->assertSame(0, $this->transport->pending());
    }

    public function test_a_failed_consolidation_gets_a_new_attempt_that_continues_on_the_written_destination(): void
    {
        [$execution, $run] = $this->startExecution();
        $this->queueStatus(alive: false, exit: ['exit_code' => 74, 'cancel_requested' => false, 'finished_at' => '2026-10-21T12:00:00Z']);
        $this->queueReport(['stage' => '06-aplicar-usuarios', 'status' => 'failed', 'message' => 'Fallo inesperado del runtime.'], written: true);
        dispatch_sync(new ReconcileRemoteAgentOperation($run->id));
        $execution->refresh();
        $this->assertSame(ExecutionStatus::FAILED, $execution->status);
        $this->getJson(route('projects.executions.events', [$this->project, $execution]))->assertOk()
            ->assertJsonPath('execution.consolidation.can_retry', true);
        // The destination now holds this consolidation's writes.
        $metadata = $this->server->refresh()->metadata;
        $metadata['remote_destination_copias']['campus'] = ['write_locked' => true, 'packages' => []];
        $this->server->update(['metadata' => $metadata]);
        $url = route('projects.executions.consolidation.retry', [$this->project, $execution]);
        $this->postJson($url, ['configuration_version' => 1], ['Idempotency-Key' => 'consolidation-retry-stale-version'])->assertJsonValidationErrors('configuration_version');

        $this->transport->ok('copias-list', ['write_locked' => true, 'packages' => []]);
        $this->queueLaunch();
        $this->postJson($url, ['configuration_version' => 2], ['Idempotency-Key' => 'consolidation-retry-first-attempt'])->assertCreated();
        $retry = $this->project->executions()->where('id', '!=', $execution->id)->sole();
        $this->assertSame([$execution->id, ExecutionStatus::RUNNING], [$retry->retried_from_execution_id, $retry->status]);
        $this->assertSame(1, $retry->remoteOperations()->where('command_key', RemoteConsolidationWorkflow::COMMAND)->count());
        $this->assertSame(ExecutionStatus::FAILED, $execution->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'CONSOLIDATION_RETRY_APPROVED')->count());
        // The same request again, and an older attempt, add nothing.
        $this->postJson($url, ['configuration_version' => 2], ['Idempotency-Key' => 'consolidation-retry-first-attempt'])->assertOk();
        $this->postJson($url, ['configuration_version' => 2], ['Idempotency-Key' => 'consolidation-retry-second-attempt'])->assertJsonValidationErrors('execution');
        $this->assertSame(2, $this->project->executions()->count());
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

    public function test_a_lock_that_asks_for_no_plugin_continues_without_plugins_from_the_web(): void
    {
        [$execution, $run] = $this->startExecution();
        // Consolidator rc12: the pins no longer match the image although nothing is installed.
        $this->queueStatus(alive: false, exit: ['exit_code' => 22, 'cancel_requested' => false, 'finished_at' => '2026-10-21T12:00:00Z']);
        $this->queueReport(['stage' => '03-plugins', 'status' => 'WAITING_USER_ACTION', 'message' => 'PLUGIN_INTERVENTION_PENDING: instalación y revisión de plugins.']);
        $this->queueIntervention('plugins', [
            'plugin_needs' => (string) json_encode(['original_compatibility_needs' => []]),
            'plugin_diff' => null,
            'plugin_technical' => (string) json_encode(['issues' => ['PLUGIN_TECHNICAL_VALIDATION_FAILED: destino, URL o pins inconsistentes.']]),
            'plugin_checkpoint' => (string) json_encode(['status' => 'PLUGIN_INTERVENTION_APPROVED']),
            'plugin_lock' => (string) json_encode(['lock_status' => 'ARTIFACTS_VERIFIED_PENDING_STAGING', 'compatibility_needs_sha256' => str_repeat('d', 64),
                'plugins' => [], 'unknown_plugins' => []]),
        ]);
        dispatch_sync(new ReconcileRemoteAgentOperation($run->id));
        $conflict = $execution->conflicts()->sole();
        $this->assertSame(['CONTINUE_WITHOUT_PLUGINS', 'CONFIRM_COMPLETED'], $conflict->details['allowed_decisions']);
        $this->getJson(route('projects.executions.events', [$this->project, $execution]))->assertOk()
            ->assertJsonPath('execution.conflicts.0.intervention.plugins_none', true);

        // The destination seals the empty lock, then the next run continues.
        $this->transport->ok('copias-list', ['write_locked' => false, 'packages' => $this->listing()]);
        $this->queueLaunch(RemoteConsolidationWorkflow::NONE_COMMAND);
        $this->resolve($execution, $conflict, 'CONTINUE_WITHOUT_PLUGINS', 'no-plugins')->assertSessionHasNoErrors();
        $sealing = $execution->remoteOperations()->where('command_key', RemoteConsolidationWorkflow::NONE_COMMAND)->sole();
        $this->assertSame(1, AuditLog::query()->where('action', 'CONSOLIDATOR_PLUGINS_NONE_ACCEPTED')->count());
        $this->queueStatus(alive: false, exit: ['exit_code' => 0, 'cancel_requested' => false, 'finished_at' => '2026-10-21T12:30:00Z'],
            command: RemoteConsolidationWorkflow::NONE_COMMAND);
        $this->transport->ok('copias-list', ['write_locked' => false, 'packages' => $this->listing()]);
        $this->queueLaunch();
        dispatch_sync(new ReconcileRemoteAgentOperation($sealing->id));
        $this->assertSame(['consolidator-run-2', ExecutionStatus::RUNNING], [
            $execution->remoteOperations()->where('command_key', RemoteConsolidationWorkflow::COMMAND)->orderByDesc('id')->value('idempotency_key'),
            $execution->fresh()->status]);
        $this->assertSame(0, $this->transport->pending());
    }

    public function test_the_fuzzy_identity_review_is_decided_in_the_platform_and_applied_by_the_consolidator(): void
    {
        [$execution, $run] = $this->startExecution();
        $this->queueStatus(alive: false, exit: ['exit_code' => 20, 'cancel_requested' => false, 'finished_at' => '2026-10-21T12:00:00Z']);
        $this->queueReport(['stage' => '05b-readiness', 'status' => 'WAITING_USER_ACTION',
            'message' => 'V8_IDENTITY_REVIEW_REQUIRED: IDENTITY_REVIEW_PREPARED; WAITING_USER_ACTION.']);
        $account = fn (string $source, string $id, string $name, string $email): array => ['source' => $source, 'user_id' => $id,
            'canonical_id' => 'CAN-'.$source.'-'.$id, 'name' => $name, 'email' => $email, 'google' => 'none', 'members' => [$source.':'.$id],
            // Agent 1.14: each member's existing username; a malformed key never reaches the page.
            'accounts' => [['key' => $source.':'.$id, 'name' => $name, 'email' => $email, 'username' => 'u'.$id, 'document' => '…1234'],
                ['key' => '../etc:1', 'username' => 'root']]];
        $this->transport->ok('identity-candidates', ['total' => 2, 'offset' => 0, 'candidates' => [
            ['group' => 'FUZZ-0001', 'type' => 'POSSIBLE_IDENTITY_MATCH', 'confidence' => 'high', 'name_similarity' => '0.96', 'email_similarity' => '0.80',
                'a' => $account('pregrado', '12', 'Ana María Pérez', 'ana.perez@ufps.edu.co'), 'b' => $account('posgrado', '7', 'Ana Maria Perez', 'anaperez@gmail.com')],
            ['group' => 'FUZZ-0002', 'type' => 'POSSIBLE_IDENTITY_MATCH', 'confidence' => 'low',
                'a' => $account('pregrado', '30', 'Luis Gómez', 'lgomez@ufps.edu.co'), 'b' => $account('posgrado', '31', 'Luis Gomes', 'luisg@gmail.com')],
        ]]);
        dispatch_sync(new ReconcileRemoteAgentOperation($run->id));
        $conflict = $execution->conflicts()->sole();
        $this->assertSame(['IDENTITY_REVIEW', ['APPLY_ANSWERS', 'CONFIRM_COMPLETED']], [$conflict->type, $conflict->details['allowed_decisions']]);
        $this->assertSame(['FUZZ-0001', 'FUZZ-0002'], array_column($conflict->details['candidates'], 'group'));
        // jsonb stores keys by length, then bytes: document before username.
        $this->assertSame([['key' => 'pregrado:12', 'name' => 'Ana María Pérez', 'email' => 'ana.perez@ufps.edu.co', 'document' => '…1234', 'username' => 'u12']],
            $conflict->details['candidates'][0]['a']['accounts']);
        // The personal data reaches the administrator's review only, never the events.
        $this->assertStringNotContainsString('ana.perez@ufps.edu.co', (string) json_encode($execution->events()->get()));

        $url = route('projects.executions.conflicts.answers', [$this->project, $execution, $conflict->id]);
        $merge = ['group' => 'FUZZ-0001', 'resolution' => 'MERGE', 'justification' => 'Misma persona: cédula y nombre.',
            'canonical_target' => 'pregrado:12', 'canonical_email' => 'Ana.Perez@ufps.edu.co', 'canonical_username' => 'ana.perez', 'oauth_policy' => 'canonical_account'];
        $separate = ['group' => 'FUZZ-0002', 'resolution' => 'KEEP_SEPARATE', 'justification' => 'Personas distintas.'];
        $key = ['Idempotency-Key' => 'consolidation-identity-review-answers'];
        $this->postJson($url, ['conflict_version' => 1, 'decisions' => [$merge]], $key)->assertJsonValidationErrors('decisions');
        $this->postJson($url, ['conflict_version' => 1, 'decisions' => [[...$merge, 'canonical_target' => 'otro:99'], $separate]], $key)
            ->assertJsonValidationErrors('decisions.0.canonical_target');

        $this->transport->ok('identity-review-submit', ['destination' => 'campus', 'decided' => 2]);
        $this->transport->ok('copias-list', ['write_locked' => false, 'packages' => $this->listing()]);
        $this->queueLaunch(RemoteConsolidationWorkflow::IDENTITY_COMMAND);
        $this->postJson($url, ['conflict_version' => 1, 'decisions' => [$merge, $separate]], $key)->assertSuccessful();
        $submit = collect($this->transport->scripts)->first(fn (string $script): bool => str_contains($script, "'identity-review-submit'"));
        preg_match_all("/'([A-Za-z0-9_-]{1,160})'/", substr((string) $submit, (int) strpos((string) $submit, "'campus'") + 8), $chunks);
        $sent = json_decode((string) base64_decode(strtr(implode('', $chunks[1]), '-_', '+/')), true);
        $this->assertSame(['ana.perez@ufps.edu.co', 'KEEP_SEPARATE'], [$sent['decisions'][0]['canonical_email'], $sent['decisions'][1]['resolution']]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $sent['decision_timestamp_utc']);
        $this->assertSame('RESOLVED', $conflict->fresh()->status->value);
        $this->assertSame(2, count($conflict->fresh()->details['answers']['decisions']));
        // The page keeps confirming what was answered, without the personal data.
        $summary = RemoteConsolidationInterventions::present($conflict->fresh()->details)['answer_summary'];
        $this->assertSame([1, 1, 0], [$summary['merge'], $summary['keep_separate'], $summary['ignore']]);
        $this->assertStringNotContainsString('ana.perez', (string) json_encode($summary));
        $apply = $execution->remoteOperations()->where('command_key', RemoteConsolidationWorkflow::IDENTITY_COMMAND)->sole();

        $this->queueStatus(alive: false, exit: ['exit_code' => 0, 'cancel_requested' => false, 'finished_at' => '2026-10-21T12:30:00Z'],
            command: RemoteConsolidationWorkflow::IDENTITY_COMMAND);
        $this->transport->ok('copias-list', ['write_locked' => false, 'packages' => $this->listing()]);
        $this->queueLaunch();
        dispatch_sync(new ReconcileRemoteAgentOperation($apply->id));
        $this->assertSame(ExecutionStatus::RUNNING, $execution->fresh()->status);
        $this->assertSame('consolidator-run-2', $execution->remoteOperations()->where('command_key', RemoteConsolidationWorkflow::COMMAND)
            ->orderByDesc('id')->value('idempotency_key'));
        $this->assertSame(0, $this->transport->pending());
    }

    public function test_the_global_theme_is_chosen_in_the_platform_and_a_rejected_answer_opens_the_intervention_again(): void
    {
        [$execution, $run] = $this->startExecution();
        $this->queueStatus(alive: false, exit: ['exit_code' => 20, 'cancel_requested' => false, 'finished_at' => '2026-10-21T12:00:00Z']);
        $this->queueReport(['stage' => '05b-readiness', 'status' => 'blocked',
            'message' => 'V8_THEME_SELECTION_REQUIRED: defina global_theme_selection; selection_source se registrará como config.']);
        $this->transport->ok('theme-options', ['themes' => ['boost', 'classic'], 'selected' => '', 'default' => 'boost',
            'legacy_sources' => [], 'legacy_accepted' => false]);
        dispatch_sync(new ReconcileRemoteAgentOperation($run->id));
        $conflict = $execution->conflicts()->sole();
        $this->assertSame(['THEME_SELECTION', ['boost', 'classic'], 'boost'], [$conflict->type, $conflict->details['themes'], $conflict->details['theme_default']]);
        $url = route('projects.executions.conflicts.answers', [$this->project, $execution, $conflict->id]);
        $this->postJson($url, ['conflict_version' => 1, 'theme' => 'moove'], ['Idempotency-Key' => 'consolidation-theme-unknown'])
            ->assertJsonValidationErrors('theme');

        // The destination rejects it: the Execution keeps waiting with the same intervention open again.
        $this->transport->error('theme-policy-set', 'destination_already_written');
        $this->postJson($url, ['conflict_version' => 1, 'theme' => 'boost'], ['Idempotency-Key' => 'consolidation-theme-first'])->assertSuccessful();
        $this->assertSame(ExecutionStatus::WAITING_USER_ACTION, $execution->fresh()->status);
        $again = $execution->conflicts()->where('status', 'OPEN')->sole();
        $this->assertSame('THEME_SELECTION', $again->type);
        $this->assertStringContainsString('destination_already_written', (string) $again->details['message']);
        $this->assertArrayNotHasKey('answers', $again->details);

        $this->transport->ok('theme-policy-set', ['destination' => 'campus', 'theme' => 'classic', 'legacy_accepted' => false]);
        $this->transport->ok('copias-list', ['write_locked' => false, 'packages' => $this->listing()]);
        $this->queueLaunch();
        $this->postJson(route('projects.executions.conflicts.answers', [$this->project, $execution, $again->id]), ['conflict_version' => 1, 'theme' => 'classic'],
            ['Idempotency-Key' => 'consolidation-theme-second'])->assertSuccessful();
        $this->assertStringContainsString("'theme-policy-set' 'campus' 'classic' '0'", implode("\n", $this->transport->scripts));
        $this->assertSame(ExecutionStatus::RUNNING, $execution->fresh()->status);
        $this->assertSame(0, $this->transport->pending());
    }

    public function test_a_plugin_outside_the_catalog_is_inspected_and_added_from_the_platform(): void
    {
        [$execution, $run] = $this->startExecution();
        $this->queueStatus(alive: false, exit: ['exit_code' => 22, 'cancel_requested' => false, 'finished_at' => '2026-10-21T12:00:00Z']);
        $this->queueReport(['stage' => '03-plugins', 'status' => 'WAITING_USER_ACTION', 'message' => 'PLUGIN_INTERVENTION_PENDING: instalación y revisión de plugins.']);
        $this->queueIntervention('plugins', [...$this->pluginDocuments('PLUGIN_TECHNICAL_VALIDATION_FAILED'),
            'plugin_lock' => (string) json_encode(['lock_status' => 'DISCOVERY_REQUIRED', 'plugins' => [], 'unknown_plugins' => [['component' => 'mod_vpl']]])]);
        dispatch_sync(new ReconcileRemoteAgentOperation($run->id));
        $conflict = $execution->conflicts()->sole();
        $this->assertSame([['mod_vpl'], ['APPLY_ANSWERS', 'CONFIRM_COMPLETED']], [$conflict->details['unknown'], $conflict->details['allowed_decisions']]);

        $inspect = route('projects.executions.conflicts.plugin-inspect', [$this->project, $execution, $conflict->id]);
        $commit = str_repeat('a1', 20);
        $this->post($inspect, ['component' => 'mod_vpl', 'repository' => 'https://gitlab.com/x/vpl.git', 'commit' => $commit])->assertSessionHasErrors('repository');
        $this->post($inspect, ['component' => 'mod_quiz', 'repository' => 'https://github.com/jcrodriguez-dis/moodle-mod_vpl.git', 'commit' => $commit])
            ->assertSessionHasErrors('component');
        $this->transport->ok('copias-list', ['write_locked' => false, 'packages' => $this->listing()]);
        $this->queueLaunch(RemoteConsolidationWorkflow::INSPECT_COMMAND);
        $this->post($inspect, ['component' => 'mod_vpl', 'repository' => 'https://github.com/jcrodriguez-dis/moodle-mod_vpl.git', 'commit' => $commit])
            ->assertSessionHasNoErrors();
        $inspection = $execution->remoteOperations()->where('command_key', RemoteConsolidationWorkflow::INSPECT_COMMAND)->sole();
        $this->assertSame(ExecutionStatus::WAITING_USER_ACTION, $execution->fresh()->status, 'La inspección no mueve la Ejecución.');
        $this->assertSame('RUNNING', $conflict->fresh()->details['inspections']['mod_vpl']['state']);

        $this->queueStatus(alive: false, exit: ['exit_code' => 0, 'cancel_requested' => false, 'finished_at' => '2026-10-21T12:10:00Z'],
            command: RemoteConsolidationWorkflow::INSPECT_COMMAND);
        $entry = ['repository' => 'https://github.com/jcrodriguez-dis/moodle-mod_vpl.git', 'commit' => $commit, 'version' => 2026050100, 'requires' => 2024100700,
            'release' => '4.4.1', 'path' => 'public/mod/vpl', 'tree_sha256' => str_repeat('c', 64), 'dependencies' => []];
        $this->transport->ok('plugin-proposal', ['destination' => 'campus', 'component' => 'mod_vpl',
            'proposal_base64' => base64_encode((string) json_encode(['component' => 'mod_vpl', 'entry' => $entry]))]);
        dispatch_sync(new ReconcileRemoteAgentOperation($inspection->id));
        $details = $conflict->fresh()->details;
        $this->assertSame(['SUCCEEDED', 2026050100, 'public/mod/vpl'], [$details['inspections']['mod_vpl']['state'],
            $details['proposals']['mod_vpl']['version'], $details['proposals']['mod_vpl']['path']]);

        // Confirmed, the proposal enters the catalog on the destination and the plugin stage is revalidated.
        $answers = route('projects.executions.conflicts.answers', [$this->project, $execution, $conflict->id]);
        $this->postJson($answers, ['conflict_version' => 1, 'promote' => ['mod_vpl']], ['Idempotency-Key' => 'consolidation-plugin-promote-unconfirmed'])
            ->assertJsonValidationErrors('confirm');
        $this->transport->ok('copias-list', ['write_locked' => false, 'packages' => $this->listing()]);
        $this->queueLaunch(RemoteConsolidationWorkflow::PROMOTE_COMMAND);
        $this->postJson($answers, ['conflict_version' => 1, 'promote' => ['mod_vpl'], 'confirm' => true], ['Idempotency-Key' => 'consolidation-plugin-promote'])
            ->assertSuccessful();
        $promote = $execution->remoteOperations()->where('command_key', RemoteConsolidationWorkflow::PROMOTE_COMMAND)->sole();
        $this->assertStringContainsString("'components=mod_vpl'", (string) collect($this->transport->scripts)
            ->first(fn (string $script): bool => str_contains($script, RemoteConsolidationWorkflow::PROMOTE_COMMAND)));
        $this->queueStatus(alive: false, exit: ['exit_code' => 0, 'cancel_requested' => false, 'finished_at' => '2026-10-21T12:20:00Z'],
            command: RemoteConsolidationWorkflow::PROMOTE_COMMAND);
        $this->transport->ok('copias-list', ['write_locked' => false, 'packages' => $this->listing()]);
        $this->queueLaunch();
        dispatch_sync(new ReconcileRemoteAgentOperation($promote->id));
        $this->assertSame(['consolidator-run-2', ExecutionStatus::RUNNING], [
            $execution->remoteOperations()->where('command_key', RemoteConsolidationWorkflow::COMMAND)->orderByDesc('id')->value('idempotency_key'),
            $execution->fresh()->status]);
        $this->assertSame(0, $this->transport->pending());
    }

    public function test_plugins_needed_by_the_sources_never_offer_continuing_without_them(): void
    {
        [$execution, $run] = $this->startExecution();
        $this->stopAtPlugins($run, 'PLUGIN_TECHNICAL_VALIDATION_FAILED');
        $conflict = $execution->conflicts()->sole();
        $this->assertSame(['CONFIRM_COMPLETED'], $conflict->details['allowed_decisions']);
        $this->resolve($execution, $conflict, 'CONTINUE_WITHOUT_PLUGINS', 'not-offered')->assertSessionHasErrors('decision');
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

    /** The platform's own S3 check of a backup, recorded instead of calling AWS. */
    private function fakeS3Verification(): void
    {
        config(['toolkit.remote.package_bucket' => 'kevin-moodle-backups-2026', 'toolkit.remote.package_region' => 'us-east-2']);
        $verified = &$this->verifiedObjects;
        $this->app->instance(RemotePackageObjects::class, new class(app(S3ClientFactory::class), $verified) extends RemotePackageObjects
        {
            /** @param list<string> $verified */
            public function __construct(S3ClientFactory $clients, private array &$verified)
            {
                parent::__construct($clients);
            }

            public function verifyPackage(string $region, string $bucket, string $key, string $sha256, int $size): array
            {
                $this->verified[] = $bucket.'/'.$key;

                return ['size' => $size, 'checksum_verification' => 'S3_COMPOSITE_SHA256'];
            }
        });
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
        // Without any run the destination's writes, if any, are not this consolidation's.
        $this->postJson(route('projects.executions.consolidation.retry', [$this->project, $execution]), ['configuration_version' => 2],
            ['Idempotency-Key' => 'consolidation-retry-without-runs'])->assertJsonValidationErrors('execution');
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
