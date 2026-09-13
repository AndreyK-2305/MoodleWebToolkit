<?php

namespace Tests\Feature\Executions;

use App\Domain\Artifacts\ArtifactStreamVerifier;
use App\Domain\Artifacts\Contracts\ArtifactStorage;
use App\Domain\Artifacts\DownloadArtifact;
use App\Domain\Artifacts\DTOs\StoredArtifact;
use App\Domain\Artifacts\GenerateFinalArtifacts;
use App\Domain\Artifacts\LocalArtifactStorage;
use App\Domain\Artifacts\SensitiveValueRedactor;
use App\Domain\Artifacts\Streams\ArtifactReadStream;
use App\Domain\Executions\Contracts\ExecutionProvider;
use App\Domain\Executions\ExecutionCommandLease;
use App\Domain\Executions\ExecutionFailureCloser;
use App\Domain\Executions\FinalizationJobBudget;
use App\Domain\Executions\ProcessExecutionFinalization;
use App\Domain\Idempotency\IdempotencyRegistry;
use App\Domain\Projects\ProjectAssignmentManager;
use App\Domain\Projects\ProjectWizard;
use App\Domain\Tools\Contracts\ToolAdapter;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\UserRole;
use App\Events\ExecutionEventBroadcast;
use App\Exceptions\ArtifactIntegrityException;
use App\Exceptions\ExecutionCommandLeaseLost;
use App\Jobs\RunExecutionUnit;
use App\Models\Execution;
use App\Models\ExecutionCommand;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
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
            $this->assertDatabaseHas('idempotency_receipts', [
                'execution_id' => $execution->getKey(),
                'user_id' => $operator->getKey(),
                'action' => 'VALIDATE',
                'idempotency_key' => "auto:{$execution->uuid}:verification:0",
            ]);

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
        $this->assertDatabaseHas('idempotency_receipts', [
            'execution_id' => $execution->getKey(),
            'user_id' => $operator->getKey(),
            'action' => 'PROPOSE',
            'idempotency_key' => 'proposal-valid-0001',
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
        $this->assertSame(1, DB::table('idempotency_receipts')
            ->where('execution_id', $execution->getKey())
            ->where('action', 'FINALIZE')
            ->where('idempotency_key', 'finalize-approved-0002')
            ->count());
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
        $this->assertSame(1, DB::table('idempotency_receipts')
            ->where('execution_id', $execution->getKey())
            ->where('action', 'DOWNLOAD')
            ->where('idempotency_key', 'auditor-download-0001')
            ->count());

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

    public function test_log_export_redacts_complete_headers_cookies_and_uri_credentials(): void
    {
        Storage::fake('local');
        [$project, $execution, $operator] = $this->reviewedExecution();
        $secrets = [
            'abc.def.ghi',
            'dXNlcjpwYXNz',
            'proxy-secret',
            'sid=abc',
            'refresh=xyz',
            'usuario',
            'password-secreto',
            'json-authorization-secret',
            'json-token-secret',
            'json-cookie-secret',
            'json-password-secret',
            'fragment-secret',
        ];
        $execution->logs()->create([
            'stream' => 'SYSTEM',
            'level' => 'INFO',
            'message' => implode("\n", [
                'Authorization: Bearer abc.def.ghi',
                'AUTHORIZATION : Basic dXNlcjpwYXNz',
                'Proxy-Authorization: Bearer proxy-secret',
                'Cookie: sid=abc; refresh=xyz',
                'Set-Cookie: sid=abc; refresh=xyz; HttpOnly',
                'https://usuario:password-secreto@moodle.test/course',
                '{"Authorization":"Bearer json-authorization-secret"}',
                '{"items":[{"token":"json-token-secret"},{"nested":{"Cookie":"json-cookie-secret","password":"json-password-secret"}}]}',
                'prefijo {"token":"fragment-secret"} sufijo',
            ]),
            'context' => [
                'Authorization' => 'Bearer context-secret',
                'nested' => ['SeT-CoOkIe' => 'sid=context-cookie', 'visible' => 'conservar'],
                'serialized' => '{"nested":{"PASSWORD":"serialized-context-secret"}}',
            ],
        ]);

        $this->finalize($operator, $project, $execution);
        $artifact = $execution->artifacts()->where('type', 'LOG_EXPORT')->sole();
        $contents = Storage::disk('local')->get($artifact->path);

        foreach ($execution->artifacts as $generatedArtifact) {
            $bytes = Storage::disk('local')->get($generatedArtifact->path);

            foreach ([...$secrets, 'context-secret', 'context-cookie', 'serialized-context-secret'] as $secret) {
                $this->assertStringNotContainsString($secret, $bytes);
            }
        }

        $this->assertStringContainsString('[REDACTED]', $contents);
        $this->assertStringContainsString('conservar', $contents);
        $this->assertStringContainsString('tokenizer', app(SensitiveValueRedactor::class)->redactString('tokenizer inocuo'));
        json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_all_artifact_bytes_redact_the_extended_sensitive_key_catalog(): void
    {
        Storage::fake('local');
        [$project, $execution, $operator] = $this->reviewedExecution();
        $catalog = [
            'client_secret', 'clientSecret', 'API-KEY', 'apiKey', 'oauth_token', 'oauthToken',
            'AWS_SECRET_ACCESS_KEY', 'secretAccessKey', 'database_password', 'databasePassword',
            'db-password', 'connection_password', 'access_token', 'refreshToken', 'private-key',
            'APP_KEY', 'resumeToken', 'Authorization', 'Proxy-Authorization', 'Cookie', 'Set-Cookie',
        ];
        $secrets = [];
        $pairs = [];
        $context = [
            'tokenizer' => 'tokenizer-visible',
            'secretary' => 'secretary-visible',
            'monkey' => 'monkey-visible',
            'password_policy' => 'password-policy-visible',
        ];

        foreach ($catalog as $index => $key) {
            $secret = "catalog-secret-{$index}";
            $secrets[] = $secret;
            $pairs[] = rawurlencode($key).'='.$secret;
            $context[$key] = "context-{$secret}";
            $secrets[] = "context-{$secret}";
        }

        $serializedSecret = 'serialized-client-secret';
        $fragmentSecret = 'fragment-oauth-secret';
        $execution->logs()->create([
            'stream' => 'SYSTEM',
            'level' => 'INFO',
            'message' => 'https://moodle.test/callback?'.implode('&', $pairs)
                .' prefijo {"clientSecret":"'.$serializedSecret.'"} oauthToken='.$fragmentSecret,
            'context' => [
                ...$context,
                'serialized' => '{"nested":{"aws_secret_access_key":"serialized-aws-secret"}}',
            ],
        ]);
        $secrets[] = $serializedSecret;
        $secrets[] = $fragmentSecret;
        $secrets[] = 'serialized-aws-secret';

        $this->finalize($operator, $project, $execution);

        foreach ($execution->fresh()->artifacts as $artifact) {
            $bytes = Storage::disk('local')->get($artifact->path);

            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $bytes, "{$artifact->type} filtró {$secret}");
            }

            json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        }

        $log = Storage::disk('local')->get($execution->artifacts()->where('type', 'LOG_EXPORT')->sole()->path);

        foreach (['tokenizer-visible', 'secretary-visible', 'monkey-visible', 'password-policy-visible'] as $visible) {
            $this->assertStringContainsString($visible, $log);
        }
    }

    public function test_log_and_event_export_stop_at_the_byte_budget_and_reports_are_generated_one_per_job(): void
    {
        Storage::fake('local');
        config([
            'services.finalization.records_per_job' => 10,
            'services.finalization.bytes_per_job' => 300,
            'services.finalization.max_record_bytes' => 256,
        ]);
        [$project, $execution, $operator] = $this->reviewedExecution();
        DB::table('execution_logs')->where('execution_id', $execution->getKey())->delete();

        foreach (range(1, 4) as $index) {
            $execution->logs()->create([
                'stream' => 'SYSTEM',
                'level' => 'INFO',
                'message' => "budget-log-{$index} ".str_repeat('x', 180),
                'context' => ['index' => $index],
            ]);
        }
        $oversizedSecret = 'oversized-sensitive-value';
        $oversizedMessage = 'clientSecret='.$oversizedSecret.' '.str_repeat('ñ', 1_000);
        $legacyLog = $execution->logs()->create([
            'stream' => 'SYSTEM',
            'level' => 'INFO',
            'message' => $oversizedMessage,
            'context' => ['payload' => str_repeat('á', 1_000)],
        ]);
        // Simulate an existing row from before write-time redaction (1G).
        // The exporter must still redact it and describe the original stored bytes.
        DB::table('execution_logs')->where('id', $legacyLog->getKey())->update(['message' => $oversizedMessage]);

        $this->actingAs($operator)->postJson(
            route('projects.executions.finalize', [$project->uuid, $execution->uuid]),
            [],
            ['Idempotency-Key' => 'finalize-budget-regression'],
        )->assertAccepted();
        $command = $execution->commands()->where('command_type', ExecutionCommandType::FINALIZE)->sole();
        $this->executeOnce($command);
        $this->executeOnce($command->fresh());
        $state = DB::table('execution_finalizations')->where('execution_command_id', $command->getKey())->sole();

        $this->assertSame('EXPORT_LOGS', $state->stage);
        $this->assertGreaterThan(0, (int) $state->log_cursor);
        $this->assertLessThan((int) $execution->logs()->max('id'), (int) $state->log_cursor);

        $logJobs = 1;
        $eventJobs = 0;

        while (($state = DB::table('execution_finalizations')->where('execution_command_id', $command->getKey())->sole())->stage !== 'GENERATE_REPORTS') {
            $stage = $state->stage;
            $this->executeOnce($command->fresh());

            if ($stage === 'EXPORT_LOGS') {
                $logJobs++;
            } elseif ($stage === 'EXPORT_EVENTS') {
                $eventJobs++;
            }
        }

        $this->assertGreaterThan(1, $logJobs);
        $this->assertGreaterThan(1, $eventJobs);

        $before = count(json_decode($state->artifacts, true, flags: JSON_THROW_ON_ERROR));
        $this->executeOnce($command->fresh());
        $afterState = DB::table('execution_finalizations')->where('execution_command_id', $command->getKey())->sole();
        $after = count(json_decode($afterState->artifacts, true, flags: JSON_THROW_ON_ERROR));
        $this->assertLessThanOrEqual(1, $after - $before);
        $this->assertSame('GENERATE_REPORTS', $afterState->stage);

        $this->execute($command->fresh());
        $decoded = json_decode(Storage::disk('local')->get(
            $execution->fresh()->artifacts()->where('type', 'LOG_EXPORT')->sole()->path,
        ), true, flags: JSON_THROW_ON_ERROR);
        $messages = collect($decoded['logs'])->pluck('message')->implode('|');

        foreach (range(1, 4) as $index) {
            $this->assertSame(1, substr_count($messages, "budget-log-{$index}"));
        }

        $large = collect($decoded['logs'])->first(fn (array $log): bool => str_starts_with($log['message'], 'clientSecret=[REDACTED]'));
        $this->assertIsArray($large);
        $this->assertTrue($large['truncation']['message']['truncated'] ?? false);
        $this->assertSame(strlen($oversizedMessage), $large['truncation']['message']['original_bytes'] ?? null);
        $this->assertSame(hash('sha256', $oversizedMessage), $large['truncation']['message']['original_sha256'] ?? null);
        $this->assertStringNotContainsString($oversizedSecret, json_encode($decoded, JSON_THROW_ON_ERROR));
    }

    public function test_time_budget_stops_after_progress_and_persists_the_cursor(): void
    {
        Storage::fake('local');
        config([
            'services.finalization.records_per_job' => 10,
            'services.finalization.bytes_per_job' => 1_000_000,
        ]);
        $this->app->instance(FinalizationJobBudget::class, new class extends FinalizationJobBudget
        {
            public function start(int $seconds): void {}

            public function exhausted(): bool
            {
                return true;
            }
        });
        [$project, $execution, $operator] = $this->reviewedExecution();
        DB::table('execution_logs')->where('execution_id', $execution->getKey())->delete();

        foreach (range(1, 3) as $index) {
            $execution->logs()->create([
                'stream' => 'SYSTEM',
                'level' => 'INFO',
                'message' => "time-budget-{$index}",
                'context' => [],
            ]);
        }

        $this->actingAs($operator)->postJson(
            route('projects.executions.finalize', [$project->uuid, $execution->uuid]),
            [],
            ['Idempotency-Key' => 'finalize-time-budget'],
        )->assertAccepted();
        $command = $execution->commands()->where('command_type', ExecutionCommandType::FINALIZE)->sole();
        $this->executeOnce($command);
        $this->executeOnce($command->fresh());
        $state = DB::table('execution_finalizations')->where('execution_command_id', $command->getKey())->sole();

        $this->assertSame('EXPORT_LOGS', $state->stage);
        $this->assertSame((int) $execution->logs()->min('id'), (int) $state->log_cursor);
        $this->execute($command->fresh());
        $decoded = json_decode(Storage::disk('local')->get(
            $execution->fresh()->artifacts()->where('type', 'LOG_EXPORT')->sole()->path,
        ), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(3, $decoded['logs']);
    }

    public function test_artifact_contract_requires_bounded_streaming_and_atomic_promotion(): void
    {
        foreach (['writeStream', 'readStream', 'promote'] as $method) {
            $this->assertTrue(
                method_exists(ArtifactStorage::class, $method),
                "ArtifactStorage debe exponer {$method} para evitar copias completas en memoria.",
            );
        }
    }

    public function test_large_log_generation_verification_and_download_are_chunked(): void
    {
        Storage::fake('local');
        [$project, $execution, $operator] = $this->reviewedExecution();
        $rows = [];

        for ($index = 0; $index < 300; $index++) {
            $rows[] = [
                'execution_id' => $execution->getKey(),
                'execution_step_id' => null,
                'stream' => 'SYSTEM',
                'level' => 'INFO',
                'message' => "registro-{$index} ".str_repeat('x', 4096),
                'context' => json_encode(['batch' => $index], JSON_THROW_ON_ERROR),
                'logged_at' => now(),
                'created_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 50) as $batch) {
            DB::table('execution_logs')->insert($batch);
        }

        $largeMessage = str_repeat('á', 140_000);
        $largeContext = ['payload' => str_repeat('ñ', 140_000)];
        $execution->logs()->create([
            'stream' => 'SYSTEM',
            'level' => 'INFO',
            'message' => $largeMessage,
            'context' => $largeContext,
        ]);

        $writeCalls = 0;
        $maxWrite = 0;
        $readCalls = 0;
        $maxRead = 0;
        $storage = new LocalArtifactStorage(
            writeObserver: function (int $bytes) use (&$writeCalls, &$maxWrite): void {
                $writeCalls++;
                $maxWrite = max($maxWrite, $bytes);
            },
            readObserver: function (int $bytes) use (&$readCalls, &$maxRead): void {
                $readCalls++;
                $maxRead = max($maxRead, $bytes);
            },
        );
        $this->app->instance(ArtifactStorage::class, $storage);
        $this->finalize($operator, $project, $execution);
        $artifact = $execution->artifacts()->where('type', 'LOG_EXPORT')->sole();

        $this->assertGreaterThan(1_000_000, $artifact->size);
        $this->assertGreaterThan(300, $writeCalls);
        $this->assertLessThanOrEqual(65_536, $maxWrite);
        $readCalls = 0;
        $maxRead = 0;

        $response = $this->actingAs($operator)->get(
            route('projects.executions.artifacts.download', [$project->uuid, $execution->uuid, $artifact->getKey()]).'?key=large-stream-download-0001',
        )->assertOk();
        $downloaded = $response->streamedContent();

        $this->assertSame($artifact->size, strlen($downloaded));
        $this->assertSame($artifact->sha256, hash('sha256', $downloaded));
        $decoded = json_decode($downloaded, true, flags: JSON_THROW_ON_ERROR);
        $last = $decoded['logs'][array_key_last($decoded['logs'])];
        $this->assertSame($largeMessage, $last['message']);
        $this->assertSame($largeContext, $last['context']);
        $this->assertGreaterThan(20, $readCalls);
        $this->assertLessThanOrEqual(65_536, $maxRead);
    }

    public function test_finalization_is_persisted_and_requires_multiple_bounded_jobs(): void
    {
        Storage::fake('local');
        [$project, $execution, $operator] = $this->reviewedExecution();
        $this->actingAs($operator)->postJson(
            route('projects.executions.finalize', [$project->uuid, $execution->uuid]),
            [],
            ['Idempotency-Key' => 'finalize-multijob-reproduction'],
        )->assertAccepted();
        $command = $execution->commands()->where('command_type', ExecutionCommandType::FINALIZE)->sole();

        $this->executeOnce($command);

        $this->assertSame(ExecutionStatus::REVIEW, $execution->fresh()->status);
        $this->assertTrue(Schema::hasTable('execution_finalizations'));
        $this->assertDatabaseHas('execution_finalizations', [
            'execution_command_id' => $command->getKey(),
            'stage' => 'EXPORT_LOGS',
        ]);
        Queue::assertPushed(RunExecutionUnit::class, fn (RunExecutionUnit $job): bool => $job->commandId === $command->getKey());
    }

    public function test_download_verifies_and_streams_the_same_handle(): void
    {
        Storage::fake('local');
        [$project, $execution, $operator] = $this->reviewedExecution();
        $this->finalize($operator, $project, $execution);
        $artifact = $execution->artifacts()->where('type', 'JSON_REPORT')->sole();
        $contents = Storage::disk('local')->get($artifact->path);
        $storage = new class($artifact->path, $contents) implements ArtifactStorage
        {
            public int $opens = 0;

            public ?ArtifactReadStream $lastStream = null;

            public function __construct(private readonly string $path, public string $contents) {}

            public function writeStream(string $path, iterable $chunks): StoredArtifact
            {
                throw new RuntimeException('not used');
            }

            public function appendStream(string $path, iterable $chunks, int $expectedSize): int
            {
                throw new RuntimeException('not used');
            }

            public function readStream(string $path): ArtifactReadStream
            {
                $this->opens++;
                $handle = fopen('php://temp', 'w+b');
                fwrite($handle, $this->contents);
                rewind($handle);

                return $this->lastStream = new ArtifactReadStream($handle);
            }

            public function promote(string $stagingPath, string $finalPath, ?StoredArtifact $expected = null): StoredArtifact
            {
                throw new RuntimeException('not used');
            }

            public function exists(string $path): bool
            {
                return $path === $this->path;
            }

            public function delete(string $path): void {}
        };
        $download = new DownloadArtifact(
            $storage,
            new ArtifactStreamVerifier($storage),
            app(IdempotencyRegistry::class),
        );

        $stream = $download->prepare($artifact, $operator, 'single-handle-download');

        $this->assertSame(1, $storage->opens);
        $this->assertSame($contents, $stream->read(strlen($contents)));
        $this->assertFalse($stream->isClosed());
        $stream->close();
        $this->assertTrue($stream->isClosed());
        $storage->contents .= 'alterado';

        try {
            $download->prepare($artifact, $operator, 'single-handle-tampered');
            $this->fail('El contenido alterado debía bloquearse.');
        } catch (ArtifactIntegrityException) {
            $this->assertTrue($storage->lastStream?->isClosed());
        }

        $storage->contents = $contents;

        try {
            $download->prepare($artifact, User::factory()->make(), 'single-handle-audit-failure');
            $this->fail('Una descarga abortada durante la auditoría debía fallar.');
        } catch (\Throwable) {
            $this->assertTrue($storage->lastStream?->isClosed());
        }
    }

    public function test_multibatch_finalization_recovers_partial_append_and_rejects_a_stale_worker(): void
    {
        Storage::fake('local');
        config([
            'services.finalization.records_per_job' => 2,
            'services.finalization.verification_bytes_per_job' => 65_536,
        ]);
        [$project, $execution, $operator] = $this->reviewedExecution();

        foreach (range(1, 7) as $index) {
            $execution->logs()->create([
                'stream' => 'SYSTEM',
                'level' => 'INFO',
                'message' => "multilote-{$index}",
                'context' => ['index' => $index],
            ]);
        }

        $this->actingAs($operator)->postJson(
            route('projects.executions.finalize', [$project->uuid, $execution->uuid]),
            [],
            ['Idempotency-Key' => 'finalize-multibatch-recovery'],
        )->assertAccepted();
        $command = $execution->commands()->where('command_type', ExecutionCommandType::FINALIZE)->sole();
        $this->executeOnce($command);
        $this->executeOnce($command->fresh());
        $beforeFailure = DB::table('execution_finalizations')->where('execution_command_id', $command->getKey())->sole();
        $throwOnce = true;
        $storage = new LocalArtifactStorage(writeObserver: function () use (&$throwOnce): void {
            if ($throwOnce) {
                $throwOnce = false;
                throw new RuntimeException('caída después de escribir un lote');
            }
        });
        $this->app->instance(ArtifactStorage::class, $storage);

        try {
            $this->executeOnce($command->fresh());
            $this->fail('El lote debía simular una caída después de su escritura física.');
        } catch (RuntimeException $exception) {
            $this->assertSame('caída después de escribir un lote', $exception->getMessage());
            $this->assertTrue(app(ExecutionFailureCloser::class)->closeWorkerFailure((int) $command->getKey(), $exception));
        }

        $afterFailure = DB::table('execution_finalizations')->where('execution_command_id', $command->getKey())->sole();
        $this->assertSame($beforeFailure->log_cursor, $afterFailure->log_cursor);
        $persistedWork = json_decode($afterFailure->temporary_files, true, flags: JSON_THROW_ON_ERROR)[0];
        $this->assertGreaterThan($persistedWork['size'], Storage::disk('local')->size($persistedWork['path']));

        $leases = app(ExecutionCommandLease::class);
        $stale = $leases->claim((int) $command->getKey());
        $this->assertNotNull($stale);
        $command->refresh()->update(['lease_expires_at' => now()->utc()->subSecond()]);
        $this->assertTrue(app(ExecutionFailureCloser::class)->closeAbandoned((int) $command->getKey()));
        $winner = $leases->claim((int) $command->getKey());
        $this->assertNotNull($winner);

        try {
            app(ProcessExecutionFinalization::class)->process((int) $command->getKey(), $stale->owner);
            $this->fail('La respuesta tardía del worker obsoleto debía rechazarse.');
        } catch (ExecutionCommandLeaseLost) {
            $this->assertTrue(true);
        }

        app(ProcessExecutionFinalization::class)->process((int) $command->getKey(), $winner->owner);
        $units = 4;

        while ($command->fresh()->processed_at === null && $units < 100) {
            $this->executeOnce($command->fresh());
            $units++;
        }

        $execution->refresh();
        $this->assertSame(ExecutionStatus::COMPLETED, $execution->status);
        $this->assertGreaterThan(10, $units);
        $this->assertSame(4, $execution->artifacts()->count());
        $this->assertSame(1, $execution->events()->where('type', 'execution.completed')->count());
        $artifact = $execution->artifacts()->where('type', 'LOG_EXPORT')->sole();
        $decoded = json_decode(Storage::disk('local')->get($artifact->path), true, flags: JSON_THROW_ON_ERROR);
        $messages = collect($decoded['logs'])->pluck('message');

        foreach (range(1, 7) as $index) {
            $this->assertSame(1, $messages->filter(fn (string $message): bool => $message === "multilote-{$index}")->count());
        }

        $this->assertCount($execution->logs()->count(), $decoded['logs']);
        $this->assertCount($execution->events()->count() - 1, $decoded['events']);
        $this->assertSame($artifact->sha256, hash('sha256', Storage::disk('local')->get($artifact->path)));
    }

    public function test_stale_worker_cleanup_cannot_delete_files_written_by_a_later_worker(): void
    {
        Storage::fake('local');
        [$project, $execution, $operator] = $this->reviewedExecution();
        $this->actingAs($operator)->postJson(
            route('projects.executions.finalize', [$project->uuid, $execution->uuid]),
            [],
            ['Idempotency-Key' => 'finalize-stale-reproduction'],
        )->assertAccepted();
        $generator = app(GenerateFinalArtifacts::class);
        $command = $execution->commands()->where('command_type', ExecutionCommandType::FINALIZE)->sole();
        $completedAt = now()->utc()->toImmutable();
        $workerA = $generator->stage($execution, $operator, (int) $command->getKey(), 'worker-a', $command->created_at, $completedAt);
        $workerB = $generator->stage($execution, $operator, (int) $command->getKey(), 'worker-b', $command->created_at, $completedAt);

        $generator->cleanup($workerA);

        foreach ($workerB as $artifact) {
            $this->assertTrue(
                Storage::disk('local')->exists($artifact['stored']->path),
                'El cleanup del worker A eliminó el archivo definitivo promovido por B.',
            );
        }
    }

    public function test_completed_at_is_set_by_the_single_final_unit_after_inter_job_wait(): void
    {
        Storage::fake('local');
        Carbon::setTestNow('2026-09-10T10:00:00+00:00');

        try {
            [$project, $execution, $operator] = $this->reviewedExecution();
            $this->actingAs($operator)->postJson(
                route('projects.executions.finalize', [$project->uuid, $execution->uuid]),
                [],
                ['Idempotency-Key' => 'finalize-delayed-0001'],
            )->assertAccepted();
            $command = $execution->commands()->where('command_type', ExecutionCommandType::FINALIZE)->sole();
            $requestedAt = $command->created_at;

            Carbon::setTestNow('2026-09-10T10:15:00+00:00');
            $this->executeOnce($command);
            Carbon::setTestNow('2026-09-10T10:16:00+00:00');

            $units = 1;

            while (DB::table('execution_finalizations')->where('execution_command_id', $command->getKey())->value('stage') !== 'FINAL_SUMMARY' && $units < 100) {
                $this->executeOnce($command->fresh());
                $units++;
            }

            $this->assertLessThan(100, $units);
            $this->assertSame(ExecutionStatus::REVIEW, $execution->fresh()->status);
            Carbon::setTestNow('2026-09-10T10:30:00+00:00');
            $this->executeOnce($command->fresh());
            $execution->refresh();
            $artifact = $execution->artifacts()->where('type', 'FINAL_SUMMARY')->sole();
            $summary = json_decode(Storage::disk('local')->get($artifact->path), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame($requestedAt->utc()->toIso8601String(), $summary['finalization_requested_at'] ?? null);
            $this->assertSame('2026-09-10T10:30:00+00:00', $summary['generated_at'] ?? null);
            $this->assertSame($execution->finished_at?->toIso8601String(), $summary['execution']['completed_at'] ?? null);
            $this->assertFalse($requestedAt->equalTo($execution->finished_at));
            $finalization = DB::table('execution_finalizations')->where('execution_command_id', $command->getKey())->sole();
            $this->assertTrue(Carbon::parse($finalization->finalization_started_at)->lessThan($execution->finished_at));
            $this->assertSame('2026-09-10T10:30:00+00:00', $execution->finished_at?->toIso8601String());
            $this->assertSame($execution->finished_at?->toIso8601String(), Carbon::parse($finalization->completed_at)->toIso8601String());
            $this->assertSame($execution->finished_at?->toIso8601String(), $execution->steps()->where('step_key', 'finalization')->sole()->finished_at?->toIso8601String());
            $this->assertSame($execution->finished_at?->toIso8601String(), $command->fresh()->processed_at?->toIso8601String());
            $completedEvent = $execution->events()->where('type', 'execution.completed')->sole();
            $this->assertSame($execution->finished_at?->toIso8601String(), $completedEvent->created_at->toIso8601String());
            $this->assertSame($execution->finished_at?->toIso8601String(), $completedEvent->payload['completed_at'] ?? null);
            $this->assertSame(
                $execution->finished_at?->toIso8601String(),
                $execution->auditLogs()->where('action', 'EXECUTION_COMPLETED')->sole()->payload['completed_at'] ?? null,
            );
            $this->assertSame(
                $execution->finished_at?->toIso8601String(),
                $execution->completion_summary['completed_at'] ?? null,
            );
        } finally {
            Carbon::setTestNow();
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

    public function test_abandoned_finalization_cleanup_is_available_and_repeatable(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('executions/orphan/.staging/1/worker/part.json', '{}');

        $this->artisan('artifacts:cleanup-finalization', ['--minimum-age' => 0])->assertSuccessful();
        $this->assertFalse(Storage::disk('local')->exists('executions/orphan/.staging/1/worker/part.json'));
        $this->artisan('artifacts:cleanup-finalization', ['--minimum-age' => 0])->assertSuccessful();
    }

    public function test_cleanup_preserves_active_and_referenced_files_but_removes_expired_orphans(): void
    {
        Storage::fake('local');
        [$completedProject, $completedExecution, $completedOperator] = $this->reviewedExecution();
        $this->finalize($completedOperator, $completedProject, $completedExecution);
        $referenced = $completedExecution->artifacts()->where('type', 'JSON_REPORT')->sole();
        [$activeProject, $activeExecution, $activeOperator] = $this->reviewedExecution();
        $this->actingAs($activeOperator)->postJson(
            route('projects.executions.finalize', [$activeProject->uuid, $activeExecution->uuid]),
            [],
            ['Idempotency-Key' => 'finalize-cleanup-active'],
        )->assertAccepted();
        $activeCommand = $activeExecution->commands()->where('command_type', ExecutionCommandType::FINALIZE)->sole();
        $this->executeOnce($activeCommand);
        $state = DB::table('execution_finalizations')->where('execution_command_id', $activeCommand->getKey())->sole();
        $activeWork = json_decode($state->temporary_files, true, flags: JSON_THROW_ON_ERROR)[0]['path'];
        $activeLease = app(ExecutionCommandLease::class)->claim((int) $activeCommand->getKey());
        $this->assertNotNull($activeLease);
        $activeLeaseFile = $state->staging_prefix.'/'.$activeLease->owner.'/active.part';
        $expiredPart = $state->staging_prefix.'/expired-owner/orphan.part';
        $orphanFinal = "executions/{$activeExecution->workspace_key}/final/orphan/unreferenced.json";
        Storage::disk('local')->put($expiredPart, 'expired');
        Storage::disk('local')->put($activeLeaseFile, 'active');
        Storage::disk('local')->put($orphanFinal, 'orphan');

        $this->artisan('artifacts:cleanup-finalization', ['--minimum-age' => 0])->assertSuccessful();

        $this->assertTrue(Storage::disk('local')->exists($referenced->path));
        $this->assertTrue(Storage::disk('local')->exists($activeWork));
        $this->assertTrue(Storage::disk('local')->exists($activeLeaseFile));
        $this->assertFalse(Storage::disk('local')->exists($expiredPart));
        $this->assertFalse(Storage::disk('local')->exists($orphanFinal));
        $this->artisan('artifacts:cleanup-finalization', ['--minimum-age' => 0])->assertSuccessful();
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

            public ?int $failAt = 2;

            public function writeStream(string $path, iterable $chunks): StoredArtifact
            {
                $this->writes++;

                if ($this->failAt !== null && $this->writes === $this->failAt) {
                    throw new RuntimeException('fallo de almacenamiento simulado');
                }

                $contents = implode('', iterator_to_array($chunks, false));
                $this->files[$path] = $contents;

                return new StoredArtifact('local', $path, strlen($contents), hash('sha256', $contents));
            }

            public function appendStream(string $path, iterable $chunks, int $expectedSize): int
            {
                $contents = implode('', iterator_to_array($chunks, false));
                $this->files[$path] = substr($this->files[$path] ?? '', 0, $expectedSize).$contents;

                return strlen($this->files[$path]);
            }

            public function readStream(string $path): ArtifactReadStream
            {
                $contents = $this->files[$path] ?? throw new RuntimeException('archivo ausente');
                $handle = fopen('php://temp', 'w+b');
                fwrite($handle, $contents);
                rewind($handle);

                return new ArtifactReadStream($handle);
            }

            public function promote(string $stagingPath, string $finalPath, ?StoredArtifact $expected = null): StoredArtifact
            {
                if (isset($this->files[$finalPath])) {
                    throw new RuntimeException('destino existente');
                }

                $contents = $this->files[$stagingPath] ?? throw new RuntimeException('archivo ausente');
                $this->files[$finalPath] = $contents;

                return new StoredArtifact('local', $finalPath, strlen($contents), hash('sha256', $contents));
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

        $this->assertCount(1, $failingStorage->files);
        $this->assertDatabaseHas('execution_finalizations', [
            'execution_command_id' => $command->getKey(),
            'stage' => 'GENERATE_REPORTS',
        ]);
        $this->assertSame(ExecutionStatus::REVIEW, $execution->fresh()->status);
        $this->assertSame(ProjectStatus::REVIEW, $project->fresh()->status);
        $this->assertSame(0, $execution->artifacts()->count());
        $this->assertNull($execution->fresh()->finished_at);
        $this->assertNull(DB::table('execution_finalizations')->where('execution_command_id', $command->getKey())->value('completed_at'));
        $this->assertNull($command->fresh()->processing_started_at);
        $this->assertNull($command->fresh()->dispatched_at);

        $failingStorage->failAt = null;
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

    public function test_final_unit_failure_after_summary_promotion_retries_without_false_completion_or_duplicates(): void
    {
        Storage::fake('local');
        Carbon::setTestNow('2026-09-10T10:00:00+00:00');

        try {
            [$project, $execution, $operator] = $this->reviewedExecution();
            $storage = new class(new LocalArtifactStorage) implements ArtifactStorage
            {
                public bool $failAfterSummaryPromotion = true;

                public ?string $orphanedFinalPath = null;

                public function __construct(private readonly LocalArtifactStorage $inner) {}

                public function writeStream(string $path, iterable $chunks): StoredArtifact
                {
                    return $this->inner->writeStream($path, $chunks);
                }

                public function appendStream(string $path, iterable $chunks, int $expectedSize): int
                {
                    return $this->inner->appendStream($path, $chunks, $expectedSize);
                }

                public function readStream(string $path): ArtifactReadStream
                {
                    return $this->inner->readStream($path);
                }

                public function promote(string $stagingPath, string $finalPath, ?StoredArtifact $expected = null): StoredArtifact
                {
                    $stored = $this->inner->promote($stagingPath, $finalPath, $expected);

                    if ($this->failAfterSummaryPromotion && str_contains($finalPath, 'final-summary.json')) {
                        $this->failAfterSummaryPromotion = false;
                        $this->orphanedFinalPath = $finalPath;

                        throw new RuntimeException('fallo posterior a la promoción del resumen');
                    }

                    return $stored;
                }

                public function exists(string $path): bool
                {
                    return $this->inner->exists($path);
                }

                public function delete(string $path): void
                {
                    $this->inner->delete($path);
                }
            };
            $this->app->instance(ArtifactStorage::class, $storage);
            $this->actingAs($operator)->postJson(
                route('projects.executions.finalize', [$project->uuid, $execution->uuid]),
                [],
                ['Idempotency-Key' => 'finalize-final-unit-retry'],
            )->assertAccepted();
            $command = $execution->commands()->where('command_type', ExecutionCommandType::FINALIZE)->sole();
            Carbon::setTestNow('2026-09-10T10:16:00+00:00');
            $units = 0;

            while (DB::table('execution_finalizations')->where('execution_command_id', $command->getKey())->value('stage') !== 'FINAL_SUMMARY' && $units < 100) {
                $this->executeOnce($command->fresh());
                $units++;
            }

            Carbon::setTestNow('2026-09-10T10:30:00+00:00');

            try {
                $this->executeOnce($command->fresh());
                $this->fail('La unidad final debía fallar después de promover el resumen.');
            } catch (RuntimeException $exception) {
                $this->assertSame('fallo posterior a la promoción del resumen', $exception->getMessage());
                $this->assertTrue(app(ExecutionFailureCloser::class)->closeWorkerFailure((int) $command->getKey(), $exception));
            }

            $this->assertNotNull($storage->orphanedFinalPath);
            $this->assertTrue(Storage::disk('local')->exists($storage->orphanedFinalPath));
            $this->assertSame(ExecutionStatus::REVIEW, $execution->fresh()->status);
            $this->assertNull($execution->fresh()->finished_at);
            $this->assertNull(DB::table('execution_finalizations')->where('execution_command_id', $command->getKey())->value('completed_at'));
            $this->assertSame(0, $execution->artifacts()->count());
            $this->assertSame(0, $execution->events()->where('type', 'execution.completed')->count());
            $this->assertSame(0, $execution->auditLogs()->where('action', 'EXECUTION_COMPLETED')->count());

            Carbon::setTestNow('2026-09-10T10:45:00+00:00');
            $this->actingAs($operator)->postJson(
                route('projects.executions.finalize', [$project->uuid, $execution->uuid]),
                [],
                ['Idempotency-Key' => 'finalize-final-unit-retry'],
            )->assertOk()->assertJsonPath('created', false);
            $this->execute($command->fresh());
            $execution->refresh();
            $summaryArtifact = $execution->artifacts()->where('type', 'FINAL_SUMMARY')->sole();

            $this->assertSame(ExecutionStatus::COMPLETED, $execution->status);
            $this->assertSame('2026-09-10T10:45:00+00:00', $execution->finished_at?->toIso8601String());
            $this->assertNotSame($storage->orphanedFinalPath, $summaryArtifact->path);
            $this->assertSame(4, $execution->artifacts()->count());
            $this->assertSame(1, $execution->events()->where('type', 'execution.completed')->count());
            $this->assertSame(1, $execution->auditLogs()->where('action', 'EXECUTION_COMPLETED')->count());
        } finally {
            Carbon::setTestNow();
        }
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
        $attempts = 0;

        do {
            $this->executeOnce($command);
            $command->refresh();
            $attempts++;
        } while ($command->command_type === ExecutionCommandType::FINALIZE
            && $command->processed_at === null
            && $attempts < 100);

        if ($attempts === 100 && $command->processed_at === null) {
            $this->fail('La finalización reanudable no convergió dentro de 100 unidades.');
        }
    }

    private function executeOnce(ExecutionCommand $command): void
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
