<?php

namespace Tests\Feature\Domain;

use App\Enums\EventSeverity;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionStatus;
use App\Enums\MoodleInstanceRole;
use App\Enums\ProjectStatus;
use App\Enums\ServerRole;
use App\Models\Artifact;
use App\Models\ExecutionCommand;
use App\Models\ExecutionEvent;
use App\Models\MoodleInstance;
use App\Models\Server;
use App\Models\Tool;
use App\Models\ToolVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseConstraintTest extends DomainTestCase
{
    #[DataProvider('activeExecutionStatuses')]
    public function test_partial_unique_index_rejects_every_active_status_without_using_domain_service(
        ExecutionStatus $activeStatus,
    ): void {
        $project = $this->project(status: $activeStatus->projectStatus());
        $this->execution($project, $activeStatus, 1);

        $this->expectException(QueryException::class);

        $secondStatus = $activeStatus === ExecutionStatus::QUEUED
            ? ExecutionStatus::RUNNING
            : ExecutionStatus::QUEUED;
        $this->execution($project, $secondStatus, 2);
    }

    /** @return iterable<string, array{ExecutionStatus}> */
    public static function activeExecutionStatuses(): iterable
    {
        yield 'QUEUED' => [ExecutionStatus::QUEUED];
        yield 'RUNNING' => [ExecutionStatus::RUNNING];
        yield 'WAITING_USER_ACTION' => [ExecutionStatus::WAITING_USER_ACTION];
        yield 'CANCELLING' => [ExecutionStatus::CANCELLING];
        yield 'VERIFYING' => [ExecutionStatus::VERIFYING];
    }

    public function test_partial_unique_index_allows_multiple_terminal_executions(): void
    {
        $project = $this->project(status: ProjectStatus::FAILED);
        $first = $this->execution($project, ExecutionStatus::FAILED, 1);
        $second = $this->execution($project, ExecutionStatus::CANCELLED, 2);

        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertDatabaseCount('executions', 2);
    }

    public function test_execution_command_logical_key_is_unique(): void
    {
        $execution = $this->execution($this->project());
        $attributes = [
            'execution_id' => $execution->getKey(),
            'step_key' => 'collect',
            'attempt' => 1,
            'command_type' => ExecutionCommandType::START,
        ];
        ExecutionCommand::query()->create($attributes);

        $this->expectException(QueryException::class);

        ExecutionCommand::query()->create($attributes);
    }

    public function test_execution_event_sequence_is_unique_per_execution(): void
    {
        $execution = $this->execution($this->project());
        $attributes = [
            'execution_id' => $execution->getKey(),
            'sequence' => 1,
            'type' => 'STARTED',
            'severity' => EventSeverity::INFO,
        ];
        ExecutionEvent::query()->create($attributes);

        $this->expectException(QueryException::class);

        ExecutionEvent::query()->create($attributes);
    }

    public function test_commands_with_null_idempotency_keys_are_distinguished_by_logical_key(): void
    {
        $execution = $this->execution($this->project());

        ExecutionCommand::query()->create([
            'execution_id' => $execution->getKey(),
            'command_type' => ExecutionCommandType::START,
            'idempotency_key' => null,
        ]);
        ExecutionCommand::query()->create([
            'execution_id' => $execution->getKey(),
            'command_type' => ExecutionCommandType::CANCEL,
            'idempotency_key' => null,
        ]);

        $this->assertSame(2, $execution->commands()->whereNull('idempotency_key')->count());
    }

    public function test_default_step_key_still_participates_in_command_uniqueness(): void
    {
        $execution = $this->execution($this->project());
        $attributes = [
            'execution_id' => $execution->getKey(),
            'attempt' => 1,
            'command_type' => ExecutionCommandType::START,
            'idempotency_key' => null,
        ];
        ExecutionCommand::query()->create($attributes);

        $this->expectException(QueryException::class);

        ExecutionCommand::query()->create($attributes);
    }

    public function test_non_null_idempotency_key_is_unique_within_execution(): void
    {
        $execution = $this->execution($this->project());
        ExecutionCommand::query()->create([
            'execution_id' => $execution->getKey(),
            'command_type' => ExecutionCommandType::START,
            'idempotency_key' => 'same-request',
        ]);

        $this->expectException(QueryException::class);

        ExecutionCommand::query()->create([
            'execution_id' => $execution->getKey(),
            'command_type' => ExecutionCommandType::CANCEL,
            'idempotency_key' => 'same-request',
        ]);
    }

    public function test_progress_above_one_hundred_is_rejected_by_postgresql(): void
    {
        $execution = $this->execution($this->project());

        $this->expectException(QueryException::class);

        ExecutionEvent::query()->create([
            'execution_id' => $execution->getKey(),
            'sequence' => 1,
            'type' => 'PROGRESS',
            'severity' => EventSeverity::INFO,
            'progress' => 101,
        ]);
    }

    public function test_progress_below_zero_is_rejected_by_postgresql(): void
    {
        $execution = $this->execution($this->project());

        $this->expectException(QueryException::class);

        DB::table('execution_events')->insert([
            'execution_id' => $execution->getKey(),
            'sequence' => 1,
            'type' => 'PROGRESS',
            'severity' => EventSeverity::INFO->value,
            'progress' => -1,
        ]);
    }

    public function test_execution_attempt_must_be_positive(): void
    {
        $project = $this->project();

        $this->expectException(QueryException::class);

        DB::table('executions')->insert([
            'project_id' => $project->getKey(),
            'uuid' => fake()->uuid(),
            'attempt' => 0,
            'status' => ExecutionStatus::FAILED->value,
            'last_event_sequence' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_event_sequence_must_be_positive(): void
    {
        $execution = $this->execution($this->project());

        $this->expectException(QueryException::class);

        DB::table('execution_events')->insert([
            'execution_id' => $execution->getKey(),
            'sequence' => 0,
            'type' => 'PROGRESS',
            'severity' => EventSeverity::INFO->value,
            'progress' => null,
        ]);
    }

    public function test_artifact_size_cannot_be_negative(): void
    {
        $execution = $this->execution($this->project(), ExecutionStatus::FAILED);

        $this->expectException(QueryException::class);

        Artifact::query()->create([
            'execution_id' => $execution->getKey(),
            'type' => 'REPORT',
            'disk' => 'local',
            'path' => 'reports/negative.json',
            'filename' => 'negative.json',
            'size' => -1,
            'sha256' => str_repeat('a', 64),
        ]);
    }

    public function test_execution_history_cannot_be_deleted_directly(): void
    {
        $execution = $this->execution($this->project(), ExecutionStatus::FAILED);

        $this->expectException(QueryException::class);

        DB::table('executions')->where('id', $execution->getKey())->delete();
    }

    public function test_project_with_execution_history_cannot_be_deleted_in_cascade(): void
    {
        $project = $this->project();
        $this->execution($project, ExecutionStatus::FAILED);

        $this->expectException(QueryException::class);

        DB::table('projects')->where('id', $project->getKey())->delete();
    }

    public function test_tool_cannot_cascade_delete_version_identity(): void
    {
        $tool = Tool::query()->create(['key' => 'collector', 'name' => 'Recolector']);
        ToolVersion::query()->create([
            'tool_id' => $tool->getKey(),
            'version' => '1.0.0',
            'archive_name' => 'collector.zip',
            'archive_sha256' => str_repeat('b', 64),
            'enabled' => true,
        ]);

        $this->expectException(QueryException::class);

        DB::table('tools')->where('id', $tool->getKey())->delete();
    }

    public function test_moodle_instance_cannot_reference_a_server_from_another_project(): void
    {
        $firstProject = $this->project();
        $secondProject = $this->project();
        $server = Server::query()->create([
            'project_id' => $firstProject->getKey(),
            'name' => 'Servidor ajeno',
            'role' => ServerRole::SOURCE,
        ]);

        $this->expectException(QueryException::class);

        MoodleInstance::query()->create([
            'project_id' => $secondProject->getKey(),
            'server_id' => $server->getKey(),
            'name' => 'Instancia inválida',
            'role' => MoodleInstanceRole::SOURCE,
        ]);
    }
}
