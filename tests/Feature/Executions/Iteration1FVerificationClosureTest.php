<?php

namespace Tests\Feature\Executions;

use App\Domain\Artifacts\Contracts\ArtifactStorage;
use App\Domain\Artifacts\DTOs\StoredArtifact;
use App\Domain\Artifacts\LocalArtifactStorage;
use App\Domain\Executions\Contracts\ExecutionProvider;
use App\Domain\Executions\ExecutionFailureCloser;
use App\Domain\Projects\ProjectAssignmentManager;
use App\Domain\Projects\ProjectWizard;
use App\Domain\Tools\Contracts\ToolAdapter;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\UserRole;
use App\Events\ExecutionEventBroadcast;
use App\Jobs\RunExecutionUnit;
use App\Models\Execution;
use App\Models\ExecutionCommand;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class Iteration1FVerificationClosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_queues_initial_verification_and_rebuilds_review_for_every_project_type(): void
    {
        Queue::fake();
        $operator = User::factory()->create(['role' => UserRole::OPERATOR]);

        foreach (ProjectType::cases() as $type) {
            [$project, $execution] = $this->startToVerifying($operator, $type);

            $this->assertSame(ExecutionStatus::VERIFYING, $execution->fresh()->status);
            $this->assertSame(ProjectStatus::VERIFYING, $project->fresh()->status);
            $this->assertSame(50, $execution->fresh()->progress);
            $this->assertDatabaseHas('academic_snapshots', ['execution_id' => $execution->getKey(), 'project_type' => $type->value]);
            $this->assertSame(1, $execution->commands()->where('command_type', ExecutionCommandType::VALIDATE)->count());

            $this->execute($execution->commands()->where('command_type', ExecutionCommandType::VALIDATE)->sole());
            $execution->refresh();
            $this->assertSame(ExecutionStatus::REVIEW, $execution->status);
            $this->assertSame(ProjectStatus::REVIEW, $project->fresh()->status);
            $this->assertSame(75, $execution->progress);
            $this->assertTrue($execution->verifications()->sole()->approved);
            $this->assertSame($execution->review_fingerprint, $execution->validated_fingerprint);

            $this->actingAs($operator)
                ->get(route('projects.executions.show', [$project->uuid, $execution->uuid]))
                ->assertInertia(fn (Assert $page) => $page
                    ->where('execution.status', 'REVIEW')
                    ->where('review.validation_current', true)
                    ->has('review.tree')
                    ->has('review.verifications', 1));
        }
    }

    public function test_proposals_are_limited_validated_versioned_and_invalidate_previous_validation(): void
    {
        [$project, $execution, $operator] = $this->reviewedExecution();
        $fingerprint = $execution->review_fingerprint;
        $validPayload = [
            'operation' => 'RENAME_CATEGORY',
            'node_id' => 'cat:collection-academic',
            'value' => 'Oferta académica revisada',
            'expected_version' => 0,
            'base_fingerprint' => $fingerprint,
        ];

        $created = $this->actingAs($operator)->postJson(
            route('projects.executions.proposals.store', [$project->uuid, $execution->uuid]),
            $validPayload,
            ['Idempotency-Key' => 'proposal-valid-0001'],
        )->assertCreated()->assertJsonPath('version', 1);

        $this->actingAs($operator)->postJson(
            route('projects.executions.proposals.store', [$project->uuid, $execution->uuid]),
            $validPayload,
            ['Idempotency-Key' => 'proposal-valid-0001'],
        )->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('proposal_id', $created->json('proposal_id'));

        $this->actingAs($operator)->postJson(
            route('projects.executions.proposals.store', [$project->uuid, $execution->uuid]),
            [...$validPayload, 'value' => 'Payload diferente'],
            ['Idempotency-Key' => 'proposal-valid-0001'],
        )->assertConflict();

        $execution->refresh();
        $this->assertSame(1, $execution->proposal_version);
        $this->assertNull($execution->validated_proposal_version);
        $this->assertNull($execution->validated_fingerprint);
        $this->assertNotSame($fingerprint, $execution->review_fingerprint);
        $this->assertDatabaseHas('academic_proposals', [
            'execution_id' => $execution->getKey(),
            'operation' => 'RENAME_CATEGORY',
            'node_type' => 'category',
            'version' => 1,
        ]);

        $this->actingAs($operator)->postJson(
            route('projects.executions.proposals.store', [$project->uuid, $execution->uuid]),
            [
                'operation' => 'MOVE_CATEGORY',
                'node_id' => 'cat:collection-root',
                'value' => 'cat:collection-academic',
                'expected_version' => 1,
                'base_fingerprint' => $execution->review_fingerprint,
            ],
            ['Idempotency-Key' => 'proposal-cycle-0001'],
        )->assertUnprocessable()->assertJsonValidationErrors('value');

        $this->actingAs($operator)->postJson(
            route('projects.executions.proposals.store', [$project->uuid, $execution->uuid]),
            [
                'operation' => 'MOVE_COURSE',
                'node_id' => 'cat:collection-archive',
                'value' => 'cat:collection-academic',
                'expected_version' => 1,
                'base_fingerprint' => $execution->review_fingerprint,
            ],
            ['Idempotency-Key' => 'proposal-type-0001'],
        )->assertUnprocessable()->assertJsonValidationErrors('operation');

        $this->actingAs($operator)->postJson(
            route('projects.executions.proposals.store', [$project->uuid, $execution->uuid]),
            [
                'operation' => 'RENAME_CATEGORY',
                'node_id' => 'cat:collection-archive',
                'value' => 'Otro nombre',
                'expected_version' => 0,
                'base_fingerprint' => $fingerprint,
            ],
            ['Idempotency-Key' => 'proposal-stale-0001'],
        )->assertUnprocessable()->assertJsonValidationErrors('expected_version');

        $this->assertSame(1, $execution->academicProposals()->count());
    }

    public function test_rejected_validation_can_be_corrected_in_the_same_execution_and_finalized_idempotently(): void
    {
        Storage::fake('local');
        [$project, $execution, $operator] = $this->reviewedExecution();
        $originalExecutionId = $execution->getKey();
        $this->propose($operator, $project, $execution, 'CHANGE_VISIBLE_NAME', 'course:collection-101', 'REJECT propuesta simulada');

        $this->actingAs($operator)->postJson(
            route('projects.executions.validate', [$project->uuid, $execution->uuid]),
            [],
            ['Idempotency-Key' => 'validate-rejected-0001'],
        )->assertAccepted();
        $this->actingAs($operator)->postJson(
            route('projects.executions.validate', [$project->uuid, $execution->uuid]),
            [],
            ['Idempotency-Key' => 'validate-rejected-0001'],
        )->assertOk()->assertJsonPath('created', false);
        $validation = $execution->commands()->where('command_type', ExecutionCommandType::VALIDATE)->latest('id')->firstOrFail();
        $this->execute($validation);
        $execution->refresh();
        $this->assertSame(ExecutionStatus::REVIEW, $execution->status);
        $this->assertFalse($execution->verifications()->latest('proposal_version')->firstOrFail()->approved);
        $this->assertNull($execution->validated_fingerprint);

        $this->actingAs($operator)->postJson(
            route('projects.executions.finalize', [$project->uuid, $execution->uuid]),
            [],
            ['Idempotency-Key' => 'finalize-blocked-0001'],
        )->assertUnprocessable()->assertJsonValidationErrors('verification');

        $this->propose($operator, $project, $execution, 'CHANGE_VISIBLE_NAME', 'course:collection-101', 'Fundamentos corregidos');
        $this->actingAs($operator)->postJson(
            route('projects.executions.validate', [$project->uuid, $execution->uuid]),
            [],
            ['Idempotency-Key' => 'validate-rejected-0001'],
        )->assertConflict();
        $this->actingAs($operator)->postJson(
            route('projects.executions.validate', [$project->uuid, $execution->uuid]),
            [],
            ['Idempotency-Key' => 'validate-corrected-0002'],
        )->assertAccepted();
        $approvedCommand = $execution->commands()->where('command_type', ExecutionCommandType::VALIDATE)->latest('id')->firstOrFail();
        $this->execute($approvedCommand);
        $execution->refresh();
        $this->assertSame($originalExecutionId, $execution->getKey());
        $this->assertTrue($execution->verifications()->latest('proposal_version')->firstOrFail()->approved);

        $headers = ['Idempotency-Key' => 'finalize-approved-0002'];
        $this->actingAs($operator)->postJson(
            route('projects.executions.finalize', [$project->uuid, $execution->uuid]),
            [],
            $headers,
        )->assertAccepted();
        $finalize = $execution->commands()->where('command_type', ExecutionCommandType::FINALIZE)->sole();
        $this->execute($finalize);

        $execution->refresh();
        $finishedAt = $execution->finished_at;
        $this->assertSame(ExecutionStatus::COMPLETED, $execution->status);
        $this->assertSame(ProjectStatus::COMPLETED, $project->fresh()->status);
        $this->assertSame(100, $execution->progress);
        $this->assertSame($operator->getKey(), $execution->finalized_by);
        $this->assertSame(4, $execution->artifacts()->count());
        $this->assertEqualsCanonicalizing(
            ['JSON_REPORT', 'VERIFICATION_REPORT', 'LOG_EXPORT', 'FINAL_SUMMARY'],
            $execution->artifacts()->pluck('type')->all(),
        );

        foreach ($execution->artifacts as $artifact) {
            $contents = Storage::disk('local')->get($artifact->path);
            $this->assertIsString($contents);
            $this->assertSame(strlen($contents), $artifact->size);
            $this->assertSame(hash('sha256', $contents), $artifact->sha256);
            json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        }

        $this->actingAs($operator)->postJson(
            route('projects.executions.finalize', [$project->uuid, $execution->uuid]),
            [],
            $headers,
        )->assertOk()->assertJson(['created' => false, 'status' => 'COMPLETED']);
        $this->assertSame(1, $execution->commands()->where('command_type', ExecutionCommandType::FINALIZE)->count());
        $this->assertSame(1, $execution->events()->where('type', 'execution.completed')->count());
        $this->assertSame(4, $execution->artifacts()->count());
        $this->assertTrue($finishedAt->equalTo($execution->fresh()->finished_at));
    }

    public function test_completed_mode_and_artifact_downloads_enforce_roles_scope_and_integrity(): void
    {
        Storage::fake('local');
        [$project, $execution, $operator] = $this->reviewedExecution();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $auditor = User::factory()->create(['role' => UserRole::AUDITOR]);
        $unassigned = User::factory()->create(['role' => UserRole::OPERATOR]);
        $inactive = User::factory()->create(['role' => UserRole::OPERATOR, 'is_active' => false]);
        app(ProjectAssignmentManager::class)->assign($project, $auditor, $admin);

        foreach (['validate', 'finalize', 'cancel'] as $action) {
            $this->actingAs($auditor)->postJson(
                route("projects.executions.{$action}", [$project->uuid, $execution->uuid]),
                [],
                ['Idempotency-Key' => "auditor-{$action}-0001"],
            )->assertForbidden();
        }
        $this->actingAs($unassigned)->postJson(
            route('projects.executions.proposals.store', [$project->uuid, $execution->uuid]),
            ['operation' => 'RENAME_CATEGORY', 'node_id' => 'cat:collection-root', 'value' => 'No permitido', 'expected_version' => $execution->proposal_version, 'base_fingerprint' => $execution->review_fingerprint],
            ['Idempotency-Key' => 'unassigned-proposal-0001'],
        )->assertForbidden();
        $this->actingAs($inactive)->get(
            route('projects.executions.show', [$project->uuid, $execution->uuid]),
        )->assertRedirect(route('login'));

        $execution->logs()->create([
            'stream' => 'SYSTEM',
            'level' => 'INFO',
            'message' => 'password=hunter2 token:session-secret operación segura',
            'context' => [
                'cookie' => 'browser-cookie',
                'nested' => ['resume_token' => 'resume-secret', 'visible' => 'conservar'],
            ],
        ]);
        $this->finalize($operator, $project, $execution);
        $artifact = $execution->artifacts()->where('type', 'JSON_REPORT')->sole();
        $logArtifact = $execution->artifacts()->where('type', 'LOG_EXPORT')->sole();
        $logContents = Storage::disk('local')->get($logArtifact->path);
        $this->assertStringNotContainsString('hunter2', $logContents);
        $this->assertStringNotContainsString('session-secret', $logContents);
        $this->assertStringNotContainsString('browser-cookie', $logContents);
        $this->assertStringNotContainsString('resume-secret', $logContents);
        $this->assertStringContainsString('[REDACTED]', $logContents);
        $this->assertStringContainsString('conservar', $logContents);

        $page = $this->actingAs($operator)->get(route('projects.executions.show', [$project->uuid, $execution->uuid]));
        $page->assertInertia(fn (Assert $assert) => $assert
            ->where('execution.status', 'COMPLETED')
            ->where('review.read_only', true)
            ->has('review.artifacts', 4)
            ->missing('review.artifacts.0.path')
            ->missing('review.artifacts.0.disk')
            ->missing('review.idempotency_key'));
        $this->assertStringNotContainsString('hunter2', $page->getContent());
        $this->assertStringNotContainsString('resume-secret', $page->getContent());

        $this->actingAs($auditor)->get(
            route('projects.executions.artifacts.download', [$project->uuid, $execution->uuid, $artifact->getKey()]).'?key=auditor-download-0001',
        )->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($auditor)->get(
            route('projects.executions.artifacts.download', [$project->uuid, $execution->uuid, $artifact->getKey()]).'?key=auditor-download-0001',
        )->assertOk();
        $this->assertDatabaseCount('artifact_downloads', 1);

        $this->actingAs($auditor)->postJson(
            route('projects.executions.proposals.store', [$project->uuid, $execution->uuid]),
            ['operation' => 'RENAME_CATEGORY', 'node_id' => 'cat:collection-root', 'value' => 'No permitido', 'expected_version' => $execution->proposal_version, 'base_fingerprint' => $execution->review_fingerprint],
            ['Idempotency-Key' => 'auditor-proposal-0001'],
        )->assertForbidden();
        $this->actingAs($unassigned)->get(
            route('projects.executions.artifacts.download', [$project->uuid, $execution->uuid, $artifact->getKey()]).'?key=foreign-download-0001',
        )->assertForbidden();

        $otherProject = Project::query()->create([
            'name' => 'Proyecto ajeno', 'type' => ProjectType::COLLECT, 'status' => ProjectStatus::DRAFT, 'created_by' => $admin->getKey(),
        ]);
        $this->actingAs($admin)->get(
            route('projects.executions.artifacts.download', [$otherProject->uuid, $execution->uuid, $artifact->getKey()]).'?key=manipulated-download-0001',
        )->assertNotFound();

        Storage::disk('local')->put($artifact->path, 'alterado');
        $this->actingAs($admin)->get(
            route('projects.executions.artifacts.download', [$project->uuid, $execution->uuid, $artifact->getKey()]).'?key=tampered-download-0001',
        )->assertConflict();
        Storage::disk('local')->delete($logArtifact->path);
        $this->actingAs($admin)->get(
            route('projects.executions.artifacts.download', [$project->uuid, $execution->uuid, $logArtifact->getKey()]).'?key=missing-download-0001',
        )->assertGone();

        foreach (['validate', 'cancel'] as $action) {
            $this->actingAs($operator)->postJson(
                route("projects.executions.{$action}", [$project->uuid, $execution->uuid]),
                [],
                ['Idempotency-Key' => "completed-{$action}-0001"],
            )->assertForbidden();
        }
    }

    public function test_local_artifact_storage_rejects_absolute_traversal_and_symbolic_link_paths(): void
    {
        Storage::fake('local');
        $storage = new LocalArtifactStorage;

        foreach (['../escape.json', '/tmp/absolute.json', 'C:/absolute.json'] as $path) {
            try {
                $storage->put($path, '{}');
                $this->fail("La ruta insegura {$path} debía rechazarse.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('no puede escapar', $exception->getMessage());
            }
        }

        Storage::disk('local')->makeDirectory('real-target');
        $this->assertTrue(symlink(
            Storage::disk('local')->path('real-target'),
            Storage::disk('local')->path('unsafe-link'),
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('enlaces simbólicos');
        $storage->put('unsafe-link/artifact.json', '{}');
    }

    public function test_failed_artifact_generation_cleans_partial_files_and_retries_the_same_finalization(): void
    {
        Storage::fake('local');
        [$project, $execution, $operator] = $this->reviewedExecution();
        $key = 'finalize-retry-0001';
        $this->actingAs($operator)->postJson(
            route('projects.executions.finalize', [$project->uuid, $execution->uuid]),
            [],
            ['Idempotency-Key' => $key],
        )->assertAccepted();
        $command = $execution->commands()->where('command_type', ExecutionCommandType::FINALIZE)->sole();
        $failingStorage = new class implements ArtifactStorage
        {
            /** @var array<string, string> */
            public array $files = [];

            private int $writes = 0;

            public function put(string $path, string $contents): StoredArtifact
            {
                $this->writes++;

                if ($this->writes === 2) {
                    throw new RuntimeException('fallo de almacenamiento simulado');
                }

                $this->files[$path] = $contents;

                return new StoredArtifact('local', $path, strlen($contents), hash('sha256', $contents));
            }

            public function read(string $path): string
            {
                return $this->files[$path] ?? throw new RuntimeException('archivo ausente');
            }

            public function exists(string $path): bool
            {
                return array_key_exists($path, $this->files);
            }

            public function delete(string $path): void
            {
                unset($this->files[$path]);
            }
        };
        $this->app->instance(ArtifactStorage::class, $failingStorage);

        try {
            $this->execute($command);
            $this->fail('La primera generación debía fallar.');
        } catch (RuntimeException $exception) {
            $this->assertSame('fallo de almacenamiento simulado', $exception->getMessage());
            $this->assertTrue(app(ExecutionFailureCloser::class)->closeWorkerFailure((int) $command->getKey(), $exception));
        }

        $this->assertSame([], $failingStorage->files);
        $this->assertSame(ExecutionStatus::REVIEW, $execution->fresh()->status);
        $this->assertSame(ProjectStatus::REVIEW, $project->fresh()->status);
        $this->assertSame(0, $execution->artifacts()->count());
        $this->assertNull($command->fresh()->processing_started_at);
        $this->assertNull($command->fresh()->dispatched_at);

        $this->app->instance(ArtifactStorage::class, new LocalArtifactStorage);
        $this->actingAs($operator)->postJson(
            route('projects.executions.finalize', [$project->uuid, $execution->uuid]),
            [],
            ['Idempotency-Key' => $key],
        )->assertOk()->assertJsonPath('created', false);
        $this->execute($command->fresh());

        $this->assertSame(ExecutionStatus::COMPLETED, $execution->fresh()->status);
        $this->assertSame(4, $execution->artifacts()->count());
        $this->assertSame(1, $execution->commands()->where('command_type', ExecutionCommandType::FINALIZE)->count());
        $this->assertSame(1, $execution->events()->where('type', 'execution.completed')->count());
    }

    public function test_verification_can_be_cancelled_without_late_work(): void
    {
        [$project, $execution, $operator] = $this->startToVerifying(
            User::factory()->create(['role' => UserRole::OPERATOR]),
            ProjectType::COLLECT,
        );
        $validation = $execution->commands()->where('command_type', ExecutionCommandType::VALIDATE)->sole();

        $this->actingAs($operator)->postJson(
            route('projects.executions.cancel', [$project->uuid, $execution->uuid]),
            [],
            ['Idempotency-Key' => 'cancel-verifying-0001'],
        )->assertAccepted()->assertJson(['status' => 'CANCELLING']);
        $cancel = $execution->commands()->where('command_type', ExecutionCommandType::CANCEL)->sole();
        $this->execute($cancel);
        $this->execute($validation);

        $this->assertSame(ExecutionStatus::CANCELLED, $execution->fresh()->status);
        $this->assertSame(ProjectStatus::CANCELLED, $project->fresh()->status);
        $this->assertSame(0, $execution->verifications()->count());
        $this->assertSame(1, $execution->events()->where('type', 'execution.cancelled')->count());
    }

    public function test_proposal_transaction_rollback_emits_no_event(): void
    {
        [$project, $execution, $operator] = $this->reviewedExecution();
        $eventsBefore = $execution->events()->count();
        Event::fake([ExecutionEventBroadcast::class]);

        try {
            DB::transaction(function () use ($project, $execution, $operator): void {
                $this->propose($operator, $project, $execution, 'RENAME_CATEGORY', 'cat:collection-academic', 'Cambio revertido');
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }

        Event::assertNotDispatched(ExecutionEventBroadcast::class);
        $this->assertSame(0, $execution->academicProposals()->count());
        $this->assertSame($eventsBefore, $execution->events()->count());
    }

    /** @return array{Project, Execution, User} */
    private function reviewedExecution(): array
    {
        Queue::fake();
        $operator = User::factory()->create(['role' => UserRole::OPERATOR]);
        [$project, $execution] = $this->startToVerifying($operator, ProjectType::COLLECT);
        $this->execute($execution->commands()->where('command_type', ExecutionCommandType::VALIDATE)->sole());

        return [$project, $execution->fresh(), $operator];
    }

    /** @return array{Project, Execution, User} */
    private function startToVerifying(User $operator, ProjectType $type): array
    {
        Queue::fake();
        $project = $this->readyProject($operator, $type);
        $response = $this->actingAs($operator)->postJson(
            route('projects.executions.store', $project->uuid),
            ['configuration_version' => $project->configuration->version],
            ['Idempotency-Key' => 'start-'.strtolower($type->value).'-'.$project->getKey()],
        )->assertCreated();
        $execution = Execution::query()->where('uuid', $response->json('execution_uuid'))->firstOrFail();
        $this->execute($execution->commands()->where('command_type', ExecutionCommandType::START)->sole());
        $this->execute($execution->commands()->where('command_type', ExecutionCommandType::CONTINUE)->sole());

        return [$project, $execution, $operator];
    }

    private function propose(User $actor, Project $project, Execution $execution, string $operation, string $nodeId, string $value): void
    {
        $execution->refresh();
        $this->actingAs($actor)->postJson(
            route('projects.executions.proposals.store', [$project->uuid, $execution->uuid]),
            [
                'operation' => $operation,
                'node_id' => $nodeId,
                'value' => $value,
                'expected_version' => $execution->proposal_version,
                'base_fingerprint' => $execution->review_fingerprint,
            ],
            ['Idempotency-Key' => 'proposal-helper-'.$execution->getKey().'-'.$execution->proposal_version],
        )->assertCreated();
    }

    private function finalize(User $actor, Project $project, Execution $execution): void
    {
        $this->actingAs($actor)->postJson(
            route('projects.executions.finalize', [$project->uuid, $execution->uuid]),
            [],
            ['Idempotency-Key' => 'finalize-helper-'.$execution->getKey()],
        )->assertAccepted();
        $this->execute($execution->commands()->where('command_type', ExecutionCommandType::FINALIZE)->sole());
        $execution->refresh();
    }

    private function execute(ExecutionCommand $command): void
    {
        (new RunExecutionUnit((int) $command->getKey()))->handle(app(ExecutionProvider::class), app(ToolAdapter::class));
    }

    private function readyProject(User $actor, ProjectType $type): Project
    {
        $wizard = app(ProjectWizard::class);
        $project = $wizard->create($actor, [
            'name' => "Proyecto 1F {$type->value}",
            'type' => $type->value,
            'description' => 'Verificación y cierre simulados.',
        ]);
        $instances = match ($type) {
            ProjectType::COLLECT => [$this->instancePayload('SOURCE', $project->getKey())],
            ProjectType::CONSOLIDATE => [
                $this->instancePayload('SOURCE', $project->getKey() * 10 + 1),
                $this->instancePayload('SOURCE', $project->getKey() * 10 + 2),
                $this->instancePayload('DESTINATION', $project->getKey() * 10 + 3, 'PREPARED'),
            ],
            ProjectType::INTEGRATE => [
                $this->instancePayload('SOURCE', $project->getKey() * 10 + 1),
                $this->instancePayload('DESTINATION', $project->getKey() * 10 + 2, 'EXISTING_CONSOLIDATED'),
            ],
        };
        $wizard->saveInstances($project, $actor, $instances);
        $wizard->saveOptions($project, $actor, match ($type) {
            ProjectType::COLLECT => ['simulation_scenario' => 'SUCCESS', 'processing_scenario' => 'SUCCESS', 'artifact_name' => 'paquete-1f'],
            ProjectType::CONSOLIDATE => ['simulation_scenario' => 'SUCCESS', 'processing_scenario' => 'SUCCESS', 'category_strategy' => 'PRESERVE', 'user_conflict_strategy' => 'REVIEW', 'admin_strategy' => 'EXCLUDE_SOURCE_ADMINS', 'include_archived_courses' => false],
            ProjectType::INTEGRATE => ['simulation_scenario' => 'SUCCESS', 'processing_scenario' => 'SUCCESS', 'conflict_strategy' => 'REVIEW', 'preserve_destination_admins' => true],
        });
        $wizard->runPreflight($project, $actor);
        $configuration = $project->fresh('configuration')->configuration;
        $wizard->confirm($project, $actor, $configuration->version, []);

        return $project->fresh('configuration');
    }

    /** @return array<string, mixed> */
    private function instancePayload(string $role, int $sequence, ?string $destinationKind = null): array
    {
        return [
            'uuid' => null,
            'server_uuid' => null,
            'role' => $role,
            'server_name' => "Servidor {$sequence}",
            'server_host' => "moodle-{$sequence}.test",
            'name' => "Moodle {$sequence}",
            'base_url' => "https://moodle-{$sequence}.test",
            'moodle_version' => '4.5',
            'validated' => true,
            'destination_kind' => $destinationKind,
        ];
    }
}
