<?php

namespace Tests\Laboratory;

use App\Domain\Collector\CollectorExecutionProvider;
use App\Domain\Collector\CollectorRegisteredCommand;
use App\Domain\Collector\CollectorRuntimeConfiguration;
use App\Domain\Collector\CollectorSourceEvidence;
use App\Domain\Collector\CollectorWorkflow;
use App\Domain\Collector\Contracts\SecretProvider;
use App\Domain\Executions\LocalProcessInspector;
use App\Domain\Executions\LocalToolExecutionProvider;
use App\Domain\Executions\RemoteOperationCoordinator;
use App\Domain\Tools\SourcePackageRegistry;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Enums\ExecutionStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Exceptions\ToolOperationBlocked;
use App\Models\CollectorObservationCursor;
use App\Models\CollectorPackageAudit;
use App\Models\Execution;
use App\Models\ExecutionRuntimeConfiguration;
use App\Models\Project;
use App\Models\RemoteOperation;
use App\Models\SourcePackage;
use App\Models\ToolDistribution;
use Database\Seeders\ToolCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Domain\DomainFixtures;
use Tests\TestCase;

/** Executes the registered collector; no fabricated exit evidence or package. */
class CollectorWorkflowTest extends TestCase
{
    use DatabaseMigrations, DomainFixtures;

    public function test_detached_real_operation_is_audited_registered_and_observed_idempotently(): void
    {
        $this->assertTrue((bool) config('toolkit.features.recolector_742.enabled'));
        $this->assertTrue((bool) config('toolkit.features.local_runner.enabled'));
        $this->assertSame('moodle-tool-runner', gethostname());
        Storage::set('local', Storage::fake('collector-lab-'.bin2hex(random_bytes(8))));
        $root = Storage::disk('local')->path('workspaces');
        config(['toolkit.workspaces.root' => $root, 'toolkit.runner.host_id' => gethostname()]);
        Queue::fake();
        $this->seed(ToolCatalogSeeder::class);
        $distribution = ToolDistribution::query()->where('key', 'moodle-recolector-7.4.2-linux-tree')->sole();
        $this->artisan('collector:enable-laboratory')->assertExitCode(0);
        $actor = $this->user(UserRole::ADMIN);
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $this->post(route('projects.store'), ['name' => 'Durable COLLECT LAB', 'type' => 'COLLECT'])->assertRedirect();
        $project = Project::query()->sole();
        $this->put(route('projects.collector.configuration', $project), ['profile_id' => 'synthetic-moodle', 'workers' => 1,
            'package_name' => 'durable-proof', 'capacity_bytes' => 268435456, 'safety_margin_percent' => 20])->assertRedirect();
        $this->post(route('projects.wizard.preflight', $project))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('projects.wizard.confirm', $project), ['configuration_version' => 2, 'accepted_warning_ids' => ['collector.laboratory']])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('projects.wizard.confirm', $project), ['configuration_version' => 2, 'accepted_warning_ids' => ['collector.laboratory']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(ProjectStatus::READY, $project->fresh()->status);
        $this->assertSame(1, DB::table('audit_logs')->where('project_id', $project->id)->where('action', 'PROJECT_CONFIGURATION_CONFIRMED')->count());
        $this->postJson(route('projects.executions.store', $project), ['configuration_version' => 2], ['Idempotency-Key' => 'lab-real-start'])->assertCreated()->assertJsonPath('created', true);
        $this->postJson(route('projects.executions.store', $project), ['configuration_version' => 2], ['Idempotency-Key' => 'lab-real-start'])->assertOk()->assertJsonPath('created', false);
        $execution = $project->executions()->sole();
        $binding = $execution->toolBinding;
        $runtime = ExecutionRuntimeConfiguration::query()->findOrFail($binding->runtime_configuration_id);
        $workflow = app(CollectorWorkflow::class);
        $start = $execution->commands()->sole();
        app(CollectorExecutionProvider::class)->execute($start);
        $operation = $execution->remoteOperations()->sole();
        $this->assertNotNull($start->fresh()->processed_at);
        $this->assertSame(1, $execution->events()->where('type', 'collector.operation_registered')->count());
        $this->assertSame($operation->id, $workflow->start($execution->fresh())->id);
        $operation = app(RemoteOperationCoordinator::class)->runScheduled($operation->id, CollectorRegisteredCommand::KEY,
            ['project_uuid' => $project->uuid, 'execution_uuid' => $execution->uuid, 'runtime_sha256' => $runtime->content_sha256],
            'moodle-recolector-742-linux-tree');
        $deadline = microtime(true) + 240;
        try {
            try {
                do {
                    $workflow->observe($operation->fresh());
                    usleep(200000);
                    $observed = $execution->fresh();
                } while ($observed->status !== ExecutionStatus::REVIEW && ! $observed->status->isTerminal() && microtime(true) < $deadline);
            } catch (\Throwable $observationFailure) {
                $code = match (true) {
                    $observationFailure instanceof ToolOperationBlocked => 'OPERATION_BLOCKED',
                    $observationFailure instanceof QueryException => 'DATABASE_EXCEPTION',
                    $observationFailure instanceof \JsonException => 'JSON_EXCEPTION',
                    $observationFailure instanceof \InvalidArgumentException => 'INVALID_ARGUMENT',
                    $observationFailure instanceof \RuntimeException => 'RUNTIME_EXCEPTION',
                    default => 'OBSERVATION_EXCEPTION',
                };
                $this->retainObservationDiagnostic($execution, $operation, $runtime, 'OBSERVATION_EXCEPTION', $code);
                $this->fail('Collector observation failed with closed code '.$code.'.');
            }
            if ($execution->fresh()->status !== ExecutionStatus::REVIEW) {
                $this->retainObservationDiagnostic($execution, $operation, $runtime, 'REVIEW_EXPECTATION', null);
            }
            $this->assertSame(ExecutionStatus::REVIEW, $execution->fresh()->status);
            $this->assertSame(ProjectStatus::REVIEW, $project->fresh()->status);
            $this->assertSame('TERMINATED', $operation->fresh()->communication_state->value);
            $this->assertTrue(app(RemoteOperationCoordinator::class)->verifyTerminalEvidence($operation));
            $this->assertDatabaseCount('remote_operations', 1);
            $this->assertDatabaseCount('artifacts', 6);
            $this->assertDatabaseCount('source_packages', 1);
            $audit = CollectorPackageAudit::query()->sole();
            $this->assertSame('VERIFIED_UNCHANGED', $audit->snapshot['source_access']['code']['result']);
            $this->assertSame('TEMPORARY_ALLOWED', $audit->snapshot['source_access']['source_data_write']);
            $this->assertSame('NOT_VERIFIED', $audit->snapshot['source_access']['source_database_mutation']);
            $this->assertSame('VERIFIED_PROCESS_GROUP_AND_SUPERVISOR_TERMINATED', $audit->snapshot['terminal_reconciliation']);
            $beforeIds = $execution->artifacts()->orderBy('id')->pluck('id')->all();
            $again = app(LocalToolExecutionProvider::class)->collectArtifacts($operation->fresh(), ['source-package.zip' => [
                'collector_audit' => $audit->snapshot, 'manifest_sha256' => $audit->snapshot['manifest_sha256'], 'collector_audit_id' => $audit->id]]);
            $this->assertSame($beforeIds, collect($again)->pluck('id')->sort()->values()->all());
            $this->assertDatabaseCount('artifacts', 6);
            $this->assertDatabaseCount('collector_package_audits', 1);
            $package = SourcePackage::query()->sole();
            $this->assertSame('7.4.2-linux', $package->producer_tool_version);
            $this->assertSame('VALID', $package->validation_state);
            $this->assertSame('durable-proof', $package->name);
            $this->assertSame('1.0', $package->capabilities['theme_inventory']);
            $this->assertSame(2, $package->evidence['collector_audit']['courses']);
            $this->assertFileDoesNotExist(app(ExecutionWorkspaceManager::class)->resolve($execution, 'input', 'moodle-runtime.php'));
            $this->assertNull($execution->fresh()->progress);
            $this->assertTrue($execution->verifications()->sole()->approved);
            $eventsResponse = $this->getJson(route('projects.executions.events', [$project, $execution]));
            $eventsResponse->assertOk()->assertJsonPath('execution.collector.mode', 'LABORATORY')->assertJsonPath('review.source_packages.0.sha256', $package->sha256);
            $this->assertStringNotContainsString('/opt/moodle-lab', $eventsResponse->getContent());
            $this->assertStringNotContainsString('/run/secrets', $eventsResponse->getContent());
            $sequence = $execution->fresh()->last_event_sequence;
            $workflow->observe($operation->fresh());
            $this->assertSame($sequence, $execution->fresh()->last_event_sequence);
            $this->assertDatabaseCount('artifacts', 6);
            $this->assertDatabaseCount('source_packages', 1);
            $projectHttp = $this->get(route('projects.show', $project))->assertOk();
            $executionHttp = $this->get(route('projects.executions.show', [$project, $execution]))->assertOk();
            $httpSurfaces = $projectHttp->getContent()
                .$executionHttp->getContent()
                .$eventsResponse->getContent();
            app(SecretProvider::class)->consume('moodle-lab-db', '1', function (string $value) use ($execution, $httpSurfaces): void {
                $this->assertFalse(str_contains($execution->events()->get()->toJson(), $value), 'Private material reached events.');
                $this->assertFalse(str_contains($execution->logs()->get()->toJson(), $value), 'Private material reached logs.');
                $this->assertFalse(str_contains($execution->artifacts()->get()->toJson(), $value), 'Private material reached artifact metadata.');
                $this->assertFalse(str_contains($httpSurfaces, $value), 'Private material reached an HTTP surface.');
            });
            try {
                DB::transaction(fn () => DB::table('collector_package_audits')->where('id', CollectorPackageAudit::query()->sole()->id)->update(['package_bytes' => 1]));
                $this->fail('Immutable audit changed.');
            } catch (QueryException) {
                $this->assertSame($package->sha256, CollectorPackageAudit::query()->sole()->package_sha256);
            }
            $manifest = $execution->artifacts()->where('metadata->source_relative_path', 'manifest.json')->sole();
            $manifestPath = Storage::disk($manifest->disk)->path($manifest->path);
            $original = file_get_contents($manifestPath);
            chmod($manifestPath, 0600);
            try {
                file_put_contents($manifestPath, 'changed');
                try {
                    $workflow->assertFinalizable($execution->fresh());
                    $this->fail('Altered manifest authorized closure.');
                } catch (ToolOperationBlocked) {
                    $this->assertSame(ExecutionStatus::REVIEW, $execution->fresh()->status);
                }
            } finally {
                file_put_contents($manifestPath, $original);
                chmod($manifestPath, 0400);
            }
            $validationArtifact = $execution->artifacts()->where('metadata->source_relative_path', 'validation.json')->sole();
            $validationPath = Storage::disk($validationArtifact->disk)->path($validationArtifact->path);
            $validationOriginal = file_get_contents($validationPath);
            chmod($validationPath, 0600);
            try {
                $unsupported = json_decode($validationOriginal, true, 64, JSON_THROW_ON_ERROR);
                $unsupported['source_write'] = false;
                file_put_contents($validationPath, json_encode($unsupported, JSON_THROW_ON_ERROR));
                try {
                    $workflow->assertFinalizable($execution->fresh());
                    $this->fail('Unmeasured favorable source evidence authorized FINALIZE.');
                } catch (ToolOperationBlocked) {
                    $this->assertSame(ExecutionStatus::REVIEW, $execution->fresh()->status);
                    $this->assertSame(0, $execution->commands()->where('command_type', 'FINALIZE')->count());
                    $this->assertDatabaseCount('artifacts', 6);
                    $this->assertDatabaseCount('source_packages', 1);
                }
            } finally {
                file_put_contents($validationPath, $validationOriginal);
                chmod($validationPath, 0400);
            }
            $runtimePath = app(ExecutionWorkspaceManager::class)->resolve($execution, 'state', $runtime->relative_path);
            $runtimeOriginal = file_get_contents($runtimePath);
            try {
                $substituted = json_decode($runtimeOriginal, true, 64, JSON_THROW_ON_ERROR);
                $substituted['profile']['code'] = '/unapproved/synthetic/code';
                file_put_contents($runtimePath, json_encode($substituted, JSON_THROW_ON_ERROR));
                try {
                    $workflow->assertFinalizable($execution->fresh());
                    $this->fail('Replacing the approved code scope authorized FINALIZE.');
                } catch (ToolOperationBlocked) {
                    $this->assertSame(ExecutionStatus::REVIEW, $execution->fresh()->status);
                    $this->assertSame(0, $execution->commands()->where('command_type', 'FINALIZE')->count());
                    $this->assertDatabaseCount('artifacts', 6);
                }
            } finally {
                file_put_contents($runtimePath, $runtimeOriginal);
            }
            $this->postJson(route('projects.executions.finalize', [$project, $execution]), [], ['Idempotency-Key' => 'lab-real-finalize'])->assertAccepted();
            $finalize = $execution->commands()->where('command_type', 'FINALIZE')->sole();
            for ($job = 0; $job < 80 && $finalize->fresh()->processed_at === null; $job++) {
                app(CollectorExecutionProvider::class)->execute($finalize->fresh());
            }
            $this->assertSame(ExecutionStatus::COMPLETED, $execution->fresh()->status);
            $this->assertSame(ProjectStatus::COMPLETED, $project->fresh()->status);
            $this->assertDatabaseCount('artifacts', 10);
            $this->assertDatabaseCount('checkpoints', 0);
            $this->assertNull($execution->academicSnapshot);
            $this->assertSame(10, $execution->fresh()->completion_summary['artifact_count']);
            $report = $execution->artifacts()->where('type', 'JSON_REPORT')->sole();
            $reportJson = json_decode(Storage::disk($report->disk)->get($report->path), true, 64, JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('academic_nodes', $reportJson);
            $this->assertSame($package->uuid, $reportJson['source_package']['uuid']);
            $this->assertSame(2, $reportJson['audit']['courses']);
            app(SecretProvider::class)->consume('moodle-lab-db', '1', function (string $secret) use ($execution): void {
                foreach ($execution->artifacts()->whereIn('type', ['JSON_REPORT', 'VERIFICATION_REPORT', 'LOG_EXPORT', 'FINAL_SUMMARY'])->get() as $artifact) {
                    $this->assertFalse(str_contains(Storage::disk($artifact->disk)->get($artifact->path), $secret), 'Private material reached a report.');
                }
            });
            $path = Storage::disk($package->artifact->disk)->path($package->artifact->path);
            chmod($path, 0600);
            file_put_contents($path, 'tampered', FILE_APPEND);
            $this->expectException(ToolOperationBlocked::class);
            app(SourcePackageRegistry::class)->validate($package);
        } finally {
            if ($operation->fresh()->communication_state->value === 'TERMINATED') {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
                    if ($item->isDir() && ! $item->isLink()) {
                        chmod($item->getPathname(), 0700);
                    }
                }
                File::deleteDirectory($root);
                Storage::disk('local')->deleteDirectory('executions/'.$execution->workspace_key);
            }
        }
    }

    private function retainObservationDiagnostic(Execution $execution, RemoteOperation $operation,
        ExecutionRuntimeConfiguration $runtime, string $stage, ?string $exceptionCode): void
    {
        if (PHP_SAPI !== 'cli' || ! app()->environment('testing') || getenv('QUALITY_HARNESS') !== '1'
            || config('database.connections.pgsql.database') !== 'moodle_toolkit_testing'
            || ! config('toolkit.features.recolector_742.enabled') || ! config('toolkit.features.local_runner.enabled')
            || config('collector.profiles.synthetic-moodle.source_id') !== 'synthetic-lab' || gethostname() !== 'moodle-tool-runner') {
            return;
        }
        $diagnostic = ['schema_version' => 'collector-lab-php-diagnostic.v1', 'stage' => $stage,
            'exception_code' => $exceptionCode, 'diagnostic_health' => 'UNAVAILABLE'];
        try {
            $current = $execution->fresh();
            $remote = $operation->fresh();
            $cursor = CollectorObservationCursor::query()->where('remote_operation_id', $remote->id)->first();
            $evidence = is_array($remote->evidence) ? $remote->evidence : [];
            $exit = is_array($evidence['exit_evidence'] ?? null) ? $evidence['exit_evidence'] : [];
            $flag = static function (string $key) use ($evidence, $exit): ?bool {
                $value = $exit[$key] ?? $evidence[$key] ?? null;

                return is_bool($value) ? $value : null;
            };
            $runtimeValid = false;
            try {
                app(CollectorRuntimeConfiguration::class)->verify($current, $runtime);
                $runtimeValid = true;
            } catch (\Throwable) {
                // No document, exception argument or source-code hash is emitted.
            }
            $workspaces = app(ExecutionWorkspaceManager::class);
            $validationPath = $workspaces->resolve($current, 'output', 'validation.json');
            clearstatcache(true, $validationPath);
            $validationStat = @lstat($validationPath);
            $validation = null;
            $sourceValid = false;
            try {
                if ($validationStat !== false && ! is_link($validationPath) && realpath($validationPath) === $validationPath
                    && ($validationStat['mode'] & 0170000) === 0100000 && in_array($validationStat['nlink'], [1, 2], true)
                    && $validationStat['size'] > 0 && $validationStat['size'] <= 65536) {
                    $bytes = @file_get_contents($validationPath, length: 65537);
                    $decoded = is_string($bytes) && strlen($bytes) <= 65536 ? json_decode($bytes, true, 32, JSON_THROW_ON_ERROR) : null;
                    $validation = is_array($decoded) ? $decoded : null;
                }
                if (is_array($validation['source_access'] ?? null)) {
                    (new CollectorSourceEvidence)->validate($validation['source_access']);
                    $sourceValid = true;
                }
            } catch (\Throwable) {
                // Missing, unreadable or unsupported evidence remains unapproved.
            }
            $terminalValid = $groupTerminated = $supervisorTerminated = null;
            try {
                $terminalValid = app(RemoteOperationCoordinator::class)->verifyTerminalEvidence($remote);
                $inspector = app(LocalProcessInspector::class);
                $groupTerminated = ! $inspector->hasActiveProcessGroup($remote);
                $supervisorTerminated = ! $inspector->hasActiveOperation($remote);
            } catch (\Throwable) {
                // Unknown terminal observations remain null.
            }
            $source = $sourceValid ? $validation['source_access'] : [];
            $health = in_array($cursor?->reader_health, ['OK', 'MISSING', 'UNSAFE', 'ROTATED', 'TRUNCATED', 'UNREADABLE', 'ALTERED'], true)
                ? $cursor->reader_health : null;
            $privateConfiguration = $workspaces->resolve($current, 'input', 'moodle-runtime.php');
            clearstatcache(true, $privateConfiguration);
            $diagnostic = [...$diagnostic, 'diagnostic_health' => 'AVAILABLE',
                'execution_status' => $current->status->value, 'communication_state' => $remote->communication_state->value,
                'functional_state' => $remote->functional_state->value, 'exit_code' => $remote->exit_code,
                'timed_out' => $flag('timed_out'), 'resource_limit_exceeded' => $flag('resource_limit_exceeded'),
                'cursor_health' => $health, 'cursor_complete' => $cursor?->read_complete,
                'collector_started_received' => $current->events()->where('remote_operation_id', $remote->id)->where('type', 'collector.started')->exists(),
                'collector_validation_started_received' => $current->events()->where('remote_operation_id', $remote->id)->where('type', 'collector.progress')->where('payload->stage', 'validation')->exists(),
                'collector_package_validated_received' => $current->events()->where('remote_operation_id', $remote->id)->where('type', 'collector.package_validated')->exists(),
                'collector_error_received' => $current->events()->where('remote_operation_id', $remote->id)->where('type', 'collector.error')->exists(),
                'audits' => CollectorPackageAudit::query()->where('remote_operation_id', $remote->id)->count(),
                'artifacts' => $current->artifacts()->where('remote_operation_id', $remote->id)->count(),
                'source_packages' => SourcePackage::query()->where('producer_execution_id', $current->id)->count(),
                'terminal_evidence_valid' => $terminalValid, 'process_group_terminated' => $groupTerminated,
                'operation_supervisor_terminated' => $supervisorTerminated, 'validation_exists' => $validationStat !== false,
                'source_access_valid' => $sourceValid, 'approved_runtime_sha_valid' => $runtimeValid,
                'validation_runtime_sha_matches' => is_array($validation) && ($validation['runtime_sha256'] ?? null) === $runtime->content_sha256,
                'private_configuration_exists' => @lstat($privateConfiguration) !== false,
                'source_code_write' => $source['source_code_write'] ?? null, 'source_data_write' => $source['source_data_write'] ?? null,
                'source_database_mutation' => $source['source_database_mutation'] ?? null, 'destination_write' => $source['destination_write'] ?? null];
        } catch (\Throwable) {
            // Diagnostic failure never changes the test's original failed expectation.
        }
        $path = '/tmp/collector-lab-php-diagnostic.json';
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            return;
        }
        try {
            if (! @chmod($path, 0600)) {
                return;
            }
            $stat = fstat($handle);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1 || ($stat['mode'] & 0777) !== 0600) {
                return;
            }
            $json = json_encode($diagnostic, JSON_THROW_ON_ERROR);
            if (strlen($json) > 65536) {
                return;
            }
            $offset = 0;
            while ($offset < strlen($json)) {
                $written = @fwrite($handle, substr($json, $offset));
                if (! is_int($written) || $written < 1) {
                    return;
                }
                $offset += $written;
            }
            @fflush($handle);
        } catch (\Throwable) {
            // No exception messages or arguments enter JUnit or process output.
        } finally {
            fclose($handle);
        }
    }
}
