<?php

namespace Tests\Feature\Tools;

use App\Domain\Collector\CollectorEventObserver;
use App\Domain\Collector\CollectorRegisteredCommand;
use App\Domain\Workspaces\ApproveExecutionCapacity;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Enums\ExecutionStatus;
use App\Models\CollectorObservationCursor;
use App\Models\ExecutionEvent;
use App\Models\ExecutionStep;
use App\Models\RemoteOperation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Domain\DomainTestCase;

class CollectorEventObserverTest extends DomainTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/it3-observer-'.bin2hex(random_bytes(8));
        config(['toolkit.workspaces.root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function operation(): RemoteOperation
    {
        $project = $this->project();
        $execution = $this->execution($project, ExecutionStatus::RUNNING);
        app(ApproveExecutionCapacity::class)->approve($execution, 16777216, 20, $project->creator);
        ExecutionStep::query()->create(['execution_id' => $execution->id, 'step_key' => 'collection',
            'name' => 'Recolectar', 'position' => 1, 'attempt' => 1, 'status' => 'RUNNING']);

        return RemoteOperation::query()->create(['execution_id' => $execution->id, 'operation_uuid' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(), 'provider_key' => 'local-registered-process', 'host_id' => 'moodle-tool-runner',
            'runtime_key' => 'php-cli', 'command_key' => CollectorRegisteredCommand::KEY, 'command_sha256' => str_repeat('a', 64),
            'communication_state' => 'CONNECTED', 'functional_state' => 'RUNNING']);
    }

    private function line(RemoteOperation $operation, int $sequence, ?int $total = 2): string
    {
        return json_encode(['schema_version' => 'collector-event.v1', 'operation_uuid' => $operation->operation_uuid,
            'sequence' => $sequence, 'type' => 'snapshot', 'stage' => 'course-backups',
            'total_courses' => $total, 'completed_courses' => 1, 'failed_courses' => 0], JSON_THROW_ON_ERROR)."\n";
    }

    public function test_restarted_observer_commits_cursor_events_progress_and_outbox_without_duplicates(): void
    {
        $operation = $this->operation();
        $path = app(ExecutionWorkspaceManager::class)->operationLogPath($operation->execution, $operation->operation_uuid, 'stdout');
        $tail = $this->line($operation, 2, null);
        file_put_contents($path, $this->line($operation, 1).substr($tail, 0, 40));
        $cursor = app(CollectorEventObserver::class)->observe($operation);
        $this->assertSame(1, $cursor->last_wire_sequence);
        $this->assertFalse($cursor->read_complete);
        $this->assertSame(50, $operation->execution->refresh()->progress);
        $this->assertSame(50, $operation->execution->steps()->sole()->progress);
        $this->assertSame(2, $operation->execution->steps()->sole()->metadata['units']['total']);
        app()->forgetInstance(CollectorEventObserver::class);
        file_put_contents($path, substr($tail, 40).$this->line($operation, 1), FILE_APPEND);
        $cursor = app(CollectorEventObserver::class)->observe($operation->fresh());
        $this->assertTrue($cursor->read_complete);
        $this->assertSame(2, $cursor->last_wire_sequence);
        $this->assertNull($operation->execution->refresh()->progress);
        $this->assertSame(ExecutionStatus::RUNNING, $operation->execution->status);
        $this->assertSame([1, 2], ExecutionEvent::query()->pluck('sequence')->all());
        $this->assertDatabaseCount('execution_event_outbox', 2);
        app(CollectorEventObserver::class)->observe($operation->fresh());
        $this->assertDatabaseCount('execution_events', 2);
        $this->assertDatabaseCount('collector_observation_cursors', 1);
    }

    public function test_rollback_does_not_commit_cursor_or_event_and_retry_rereads_the_line(): void
    {
        $operation = $this->operation();
        $path = app(ExecutionWorkspaceManager::class)->operationLogPath($operation->execution, $operation->operation_uuid, 'stdout');
        file_put_contents($path, $this->line($operation, 1));
        try {
            DB::transaction(function () use ($operation): void {
                app(CollectorEventObserver::class)->observe($operation);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            $this->assertDatabaseCount('collector_observation_cursors', 0);
            $this->assertDatabaseCount('execution_events', 0);
            $this->assertDatabaseCount('execution_event_outbox', 0);
        }
        $this->assertSame(1, app(CollectorEventObserver::class)->observe($operation->fresh())->last_wire_sequence);
        $this->assertSame(1, ExecutionEvent::query()->sole()->sequence);
    }

    public function test_truncation_preserves_evidence_and_does_not_repeat_warning_each_poll(): void
    {
        $operation = $this->operation();
        $path = app(ExecutionWorkspaceManager::class)->operationLogPath($operation->execution, $operation->operation_uuid, 'stdout');
        file_put_contents($path, $this->line($operation, 1));
        $cursor = app(CollectorEventObserver::class)->observe($operation);
        file_put_contents($path, 'short');
        $blocked = app(CollectorEventObserver::class)->observe($operation->fresh());
        $this->assertSame('TRUNCATED', $blocked->reader_health);
        $this->assertSame($cursor->stdout_offset, $blocked->stdout_offset);
        $this->assertSame($cursor->prefix_sha256, $blocked->prefix_sha256);
        app(CollectorEventObserver::class)->observe($operation->fresh());
        $this->assertDatabaseCount('execution_events', 2);
        $this->assertSame(ExecutionStatus::RUNNING, $operation->execution->fresh()->status);
    }

    public function test_database_rejects_cursor_rewind_even_without_the_model(): void
    {
        $operation = $this->operation();
        $path = app(ExecutionWorkspaceManager::class)->operationLogPath($operation->execution, $operation->operation_uuid, 'stdout');
        file_put_contents($path, $this->line($operation, 1));
        $cursor = app(CollectorEventObserver::class)->observe($operation);
        $this->expectException(QueryException::class);
        DB::table('collector_observation_cursors')->where('id', $cursor->id)->update(['stdout_offset' => 0]);
    }

    public function test_database_rejects_cross_execution_cursor_identity(): void
    {
        $operation = $this->operation();
        $other = $this->execution($this->project());
        $this->expectException(QueryException::class);
        CollectorObservationCursor::query()->create(['remote_operation_id' => $operation->id, 'execution_id' => $other->id,
            'prefix_sha256' => hash('sha256', '')]);
    }

    public function test_cursor_migration_rollback_and_reapply_preserve_execution_and_events(): void
    {
        $operation = $this->operation();
        $path = app(ExecutionWorkspaceManager::class)->operationLogPath($operation->execution, $operation->operation_uuid, 'stdout');
        file_put_contents($path, $this->line($operation, 1));
        app(CollectorEventObserver::class)->observe($operation);
        $event = ExecutionEvent::query()->sole();
        $migration = require database_path('migrations/2026_10_08_200000_create_collector_observation_cursors.php');
        $migration->down();
        $this->assertSame(1, $operation->execution->fresh()->last_event_sequence);
        $this->assertSame($event->id, ExecutionEvent::query()->sole()->id);
        $migration->up();
        $this->assertDatabaseCount('collector_observation_cursors', 0);
        $this->assertSame($operation->id, $operation->fresh()->id);
        $blocked = app(CollectorEventObserver::class)->observe($operation->fresh());
        $this->assertSame('ALTERED', $blocked->reader_health);
        $this->assertSame(0, $blocked->last_wire_sequence);
        app(CollectorEventObserver::class)->observe($operation->fresh());
        $this->assertSame(1, $operation->execution->events()->where('type', 'collector.progress')->count());
    }
}
