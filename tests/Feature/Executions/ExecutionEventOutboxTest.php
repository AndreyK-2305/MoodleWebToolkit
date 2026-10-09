<?php

namespace Tests\Feature\Executions;

use App\Domain\Executions\ExecutionEventRecorder;
use App\Domain\Realtime\Contracts\ExecutionEventTransport;
use App\Domain\Realtime\ExecutionEventOutboxPublisher;
use App\Models\ExecutionEvent;
use App\Models\ExecutionEventOutbox;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\Feature\Domain\DomainFixtures;
use Tests\TestCase;

class ExecutionEventOutboxTest extends TestCase
{
    use DatabaseMigrations, DomainFixtures;

    private RecordingEventTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new RecordingEventTransport;
        app()->instance(ExecutionEventTransport::class, $this->transport);
    }

    public function test_event_and_outbox_roll_back_together_and_nothing_is_published_before_commit(): void
    {
        $execution = $this->execution($this->project());
        try {
            DB::transaction(function () use ($execution): void {
                app(ExecutionEventRecorder::class)->record($execution, 'collector.started', progress: null);
                $row = ExecutionEventOutbox::query()->sole();
                $this->assertFalse(app(ExecutionEventOutboxPublisher::class)->publish($row->getKey()));
                $this->assertSame([], $this->transport->delivered);
                throw new RuntimeException('roll back');
            });
        } catch (RuntimeException) {
            $this->assertDatabaseCount('execution_events', 0);
            $this->assertDatabaseCount('execution_event_outbox', 0);
            $this->assertSame(0, $execution->refresh()->last_event_sequence);
            $this->assertSame([], $this->transport->delivered);
        }
    }

    public function test_committed_event_is_published_once_and_repeated_recovery_does_not_change_sequence(): void
    {
        $execution = $this->execution($this->project());
        $event = app(ExecutionEventRecorder::class)->record($execution, 'collector.started', progress: null);
        $row = ExecutionEventOutbox::query()->sole();
        $this->assertNotNull($row->published_at);
        $this->assertSame([$event->getKey()], $this->transport->delivered);
        $this->assertTrue(app(ExecutionEventOutboxPublisher::class)->publish($row->getKey()));
        $this->assertSame(0, app(ExecutionEventOutboxPublisher::class)->recover());
        $this->assertSame([$event->getKey()], $this->transport->delivered);
        $this->assertSame(1, $row->refresh()->delivery_attempts);
        $this->assertSame(1, $execution->refresh()->last_event_sequence);
    }

    public function test_delivery_failure_preserves_postgresql_event_and_retries_without_private_error_text(): void
    {
        $this->transport->unavailable = true;
        $execution = $this->execution($this->project());
        $event = app(ExecutionEventRecorder::class)->record($execution, 'collector.progress', progress: null);
        $row = ExecutionEventOutbox::query()->sole();
        $this->assertNull($row->published_at);
        $this->assertSame('BROADCAST_UNAVAILABLE', $row->last_failure_code);
        $this->assertSame(1, $row->delivery_attempts);
        $this->assertStringNotContainsString('private-testing-material', $row->toJson());
        $this->assertSame(1, ExecutionEvent::query()->count());
        $this->assertNull($event->progress);
        $this->assertSame(0, app(ExecutionEventOutboxPublisher::class)->recover());
        $this->travel(6)->seconds();
        $this->transport->unavailable = false;
        $this->artisan('executions:recover-event-outbox')->assertSuccessful();
        $this->assertNotNull($row->refresh()->published_at);
        $this->assertNull($row->last_failure_code);
        $this->assertSame(2, $row->delivery_attempts);
        $this->assertSame([$event->getKey()], $this->transport->delivered);
        $this->assertSame(1, $execution->refresh()->last_event_sequence);
    }

    public function test_committed_outbox_survives_lost_after_commit_callback(): void
    {
        $publisher = Mockery::mock(ExecutionEventOutboxPublisher::class);
        $publisher->shouldReceive('publish')->once()->andThrow(new RuntimeException('callback interrupted'));
        app()->instance(ExecutionEventOutboxPublisher::class, $publisher);
        $execution = $this->execution($this->project());
        $event = app(ExecutionEventRecorder::class)->record($execution, 'collector.started');
        $this->assertSame([], $this->transport->delivered);
        $this->assertSame(0, ExecutionEventOutbox::query()->sole()->delivery_attempts);
        app()->forgetInstance(ExecutionEventOutboxPublisher::class);
        $this->assertSame(1, app(ExecutionEventOutboxPublisher::class)->recover());
        $this->assertSame([$event->getKey()], $this->transport->delivered);
        $this->assertNotNull(ExecutionEventOutbox::query()->sole()->published_at);
    }

    public function test_migration_rollback_and_reapply_preserve_existing_events_and_execution_data(): void
    {
        $execution = $this->execution($this->project());
        $event = app(ExecutionEventRecorder::class)->record($execution, 'existing.event');
        $migration = require database_path('migrations/2026_10_08_030000_create_execution_event_outbox.php');
        $migration->down();
        $this->assertDatabaseHas('execution_events', ['id' => $event->getKey(), 'sequence' => 1, 'type' => 'existing.event']);
        $this->assertSame(1, $execution->refresh()->last_event_sequence);
        $migration->up();
        $next = app(ExecutionEventRecorder::class)->record($execution, 'after.upgrade');
        $this->assertSame(2, $next->sequence);
        $this->assertDatabaseCount('execution_events', 2);
        $this->assertDatabaseCount('execution_event_outbox', 1);
        $this->assertSame([$event->getKey(), $next->getKey()], $this->transport->delivered);
    }
}

class RecordingEventTransport implements ExecutionEventTransport
{
    public bool $unavailable = false;

    /** @var list<int> */
    public array $delivered = [];

    public function publish(ExecutionEvent $event): void
    {
        if ($this->unavailable) {
            throw new RuntimeException('private-testing-material');
        }
        $this->delivered[] = $event->getKey();
    }
}
