<?php

namespace Tests\Feature\Domain;

use App\Domain\Executions\ExecutionEventRecorder;
use App\Enums\ConflictStatus;
use App\Enums\ConnectionStatus;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionStatus;
use App\Enums\ExecutionStepStatus;
use App\Enums\MoodleInstanceRole;
use App\Enums\ServerRole;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Models\Artifact;
use App\Models\AuditLog;
use App\Models\Checkpoint;
use App\Models\Conflict;
use App\Models\Connection;
use App\Models\ExecutionCommand;
use App\Models\ExecutionLog;
use App\Models\ExecutionStep;
use App\Models\MoodleInstance;
use App\Models\ProjectConfiguration;
use App\Models\Server;
use App\Models\Tool;
use App\Models\ToolVersion;
use App\Models\Verification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class DomainRelationsAndPrivacyTest extends DomainTestCase
{
    public function test_domain_entities_expose_the_expected_relationships(): void
    {
        $project = $this->project();
        $configuration = ProjectConfiguration::query()->create([
            'project_id' => $project->getKey(),
            'settings' => ['locale' => 'es'],
        ]);
        $server = Server::query()->create([
            'project_id' => $project->getKey(),
            'name' => 'Origen 1',
            'role' => ServerRole::SOURCE,
        ]);
        $connection = Connection::query()->create([
            'server_id' => $server->getKey(),
            'name' => 'Conexión simulada',
            'type' => 'SIMULATED',
            'status' => ConnectionStatus::UNTESTED,
            'secret_reference' => 'internal/reference',
        ]);
        $instance = MoodleInstance::query()->create([
            'project_id' => $project->getKey(),
            'server_id' => $server->getKey(),
            'name' => 'Moodle origen',
            'role' => MoodleInstanceRole::SOURCE,
        ]);
        $execution = $this->execution($project, ExecutionStatus::FAILED);
        $command = ExecutionCommand::query()->create([
            'execution_id' => $execution->getKey(),
            'step_key' => 'collect',
            'attempt' => 1,
            'command_type' => ExecutionCommandType::START,
        ]);
        $step = ExecutionStep::query()->create([
            'execution_id' => $execution->getKey(),
            'step_key' => 'collect',
            'name' => 'Recolectar',
            'position' => 1,
            'status' => ExecutionStepStatus::FAILED,
            'progress' => null,
        ]);
        $log = ExecutionLog::query()->create([
            'execution_id' => $execution->getKey(),
            'execution_step_id' => $step->getKey(),
            'message' => 'salida técnica',
        ]);
        $checkpoint = Checkpoint::query()->create([
            'execution_id' => $execution->getKey(),
            'step_key' => 'collect',
            'type' => 'TOOL_STATE',
            'resume_token' => 'opaque-secret-token',
            'validated' => false,
        ]);
        $conflict = Conflict::query()->create([
            'execution_id' => $execution->getKey(),
            'execution_step_id' => $step->getKey(),
            'key' => 'course-1',
            'type' => 'COURSE_NAME',
            'status' => ConflictStatus::OPEN,
            'details' => ['name' => 'Curso'],
        ]);
        $verification = Verification::query()->create([
            'execution_id' => $execution->getKey(),
            'key' => 'courses',
            'status' => VerificationStatus::PENDING,
        ]);
        $artifact = Artifact::query()->create([
            'execution_id' => $execution->getKey(),
            'type' => 'REPORT',
            'disk' => 'local',
            'path' => 'reports/test.json',
            'filename' => 'test.json',
            'size' => 2,
            'sha256' => str_repeat('a', 64),
        ]);
        $tool = Tool::query()->create(['key' => 'collector', 'name' => 'Recolector']);
        $version = ToolVersion::query()->create([
            'tool_id' => $tool->getKey(),
            'version' => '1.0.0',
            'archive_name' => 'collector.zip',
            'archive_sha256' => str_repeat('b', 64),
            'enabled' => true,
        ]);
        $audit = AuditLog::query()->create([
            'actor_id' => $project->created_by,
            'project_id' => $project->getKey(),
            'execution_id' => $execution->getKey(),
            'action' => 'domain.created',
        ]);

        $this->assertTrue($project->configuration->is($configuration));
        $this->assertTrue($project->servers->first()->is($server));
        $this->assertTrue($server->connections->first()->is($connection));
        $this->assertArrayNotHasKey('secret_reference', $connection->toArray());
        $this->assertTrue($server->moodleInstances->first()->is($instance));
        $this->assertTrue($execution->commands->first()->is($command));
        $this->assertTrue($execution->steps->first()->is($step));
        $this->assertTrue($step->logs->first()->is($log));
        $this->assertTrue($execution->checkpoints->first()->is($checkpoint));
        $this->assertTrue($execution->conflicts->first()->is($conflict));
        $this->assertTrue($execution->verifications->first()->is($verification));
        $this->assertTrue($execution->artifacts->first()->is($artifact));
        $this->assertTrue($tool->versions->first()->is($version));
        $this->assertTrue($project->auditLogs->first()->is($audit));
    }

    public function test_internal_tokens_are_encrypted_and_never_serialized(): void
    {
        $execution = $this->execution($this->project(), ExecutionStatus::FAILED);
        $checkpoint = Checkpoint::query()->create([
            'execution_id' => $execution->getKey(),
            'step_key' => 'collect',
            'type' => 'TOOL_STATE',
            'resume_token' => 'opaque-secret-token',
            'validated' => true,
        ])->fresh();

        $this->assertSame('opaque-secret-token', $checkpoint->resume_token);
        $this->assertNotSame('opaque-secret-token', $checkpoint->getRawOriginal('resume_token'));
        $this->assertArrayNotHasKey('resume_token', $checkpoint->toArray());
        $this->assertStringNotContainsString('opaque-secret-token', $checkpoint->toJson());

        $storedToken = DB::table('checkpoints')->where('id', $checkpoint->getKey())->value('resume_token');
        $this->assertIsString($storedToken);
        $this->assertStringNotContainsString('opaque-secret-token', $storedToken);

        $serializedExecution = $execution->fresh()->load('checkpoints')->toJson();
        $this->assertStringNotContainsString('opaque-secret-token', $serializedExecution);
        $this->assertStringNotContainsString('resume_token', $serializedExecution);
    }

    public function test_existing_user_authentication_secrets_are_not_serialized(): void
    {
        $user = $this->user(UserRole::OPERATOR);
        $user->forceFill([
            'two_factor_secret' => 'two-factor-secret',
            'two_factor_recovery_codes' => 'recovery-codes',
            'remember_token' => 'remember-token',
        ])->save();
        $serialized = $user->fresh()->toArray();

        $this->assertArrayNotHasKey('password', $serialized);
        $this->assertArrayNotHasKey('two_factor_secret', $serialized);
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $serialized);
        $this->assertArrayNotHasKey('remember_token', $serialized);
    }

    public function test_unknown_progress_remains_null(): void
    {
        $execution = $this->execution($this->project());

        $event = app(ExecutionEventRecorder::class)->record(
            execution: $execution,
            type: 'STEP_STARTED',
            progress: null,
        );

        $this->assertNull($event->progress);
        $this->assertSame(1, $event->sequence);

        $progressEvent = app(ExecutionEventRecorder::class)->record(
            execution: $execution,
            type: 'STEP_PROGRESS',
            progress: 25,
        );

        $this->assertSame(25, $progressEvent->progress);
        $this->assertSame(2, $progressEvent->sequence);
        $this->assertSame(2, $execution->fresh()->last_event_sequence);
    }

    public function test_failed_event_insert_rolls_back_monotonic_counter(): void
    {
        $execution = $this->execution($this->project());
        $caught = null;

        try {
            app(ExecutionEventRecorder::class)->record(
                execution: $execution,
                type: str_repeat('x', 81),
                message: 'must rollback',
            );
        } catch (QueryException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(QueryException::class, $caught);
        $this->assertSame(0, $execution->fresh()->last_event_sequence);
        $this->assertDatabaseMissing('execution_events', ['execution_id' => $execution->getKey()]);
    }

    public function test_audit_log_is_append_only_at_the_database_boundary(): void
    {
        $project = $this->project();
        $auditLog = AuditLog::query()->create([
            'actor_id' => $project->created_by,
            'project_id' => $project->getKey(),
            'action' => 'project.created',
        ]);

        $this->expectException(QueryException::class);

        DB::table('audit_logs')
            ->where('id', $auditLog->getKey())
            ->update(['action' => 'project.changed']);
    }

    public function test_direct_audit_reference_change_is_rejected(): void
    {
        $project = $this->project();
        $auditLog = AuditLog::query()->create([
            'actor_id' => $project->created_by,
            'project_id' => $project->getKey(),
            'action' => 'project.created',
        ]);

        $this->expectException(QueryException::class);

        DB::table('audit_logs')
            ->where('id', $auditLog->getKey())
            ->update(['project_id' => null]);
    }

    public function test_deleting_a_draft_project_detaches_its_immutable_audit_history(): void
    {
        $project = $this->project();
        $auditLog = AuditLog::query()->create([
            'actor_id' => $project->created_by,
            'project_id' => $project->getKey(),
            'action' => 'project.created',
            'payload' => ['project_uuid' => $project->uuid],
        ]);

        DB::table('projects')->where('id', $project->getKey())->delete();

        $persistedAudit = $auditLog->fresh();
        $this->assertDatabaseMissing('projects', ['id' => $project->getKey()]);
        $this->assertNotNull($persistedAudit);
        $this->assertNull($persistedAudit->project_id);
        $this->assertSame('project.created', $persistedAudit->action);
        $this->assertSame(['project_uuid' => $project->uuid], $persistedAudit->payload);
    }

    public function test_deleting_a_user_detaches_its_immutable_audit_history(): void
    {
        $actor = $this->user(UserRole::AUDITOR);
        $auditLog = AuditLog::query()->create([
            'actor_id' => $actor->getKey(),
            'action' => 'project.viewed',
        ]);

        DB::table('users')->where('id', $actor->getKey())->delete();

        $persistedAudit = $auditLog->fresh();
        $this->assertDatabaseMissing('users', ['id' => $actor->getKey()]);
        $this->assertNotNull($persistedAudit);
        $this->assertNull($persistedAudit->actor_id);
        $this->assertSame('project.viewed', $persistedAudit->action);
    }
}
