<?php

namespace Tests\Feature\Executions;

use App\Domain\Executions\ExecutionEventRecorder;
use App\Domain\Executions\ExecutionPresenter;
use App\Enums\ExecutionStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Events\ExecutionEventBroadcast;
use Tests\Feature\Domain\DomainTestCase;

class Iteration1GPrivacyTest extends DomainTestCase
{
    public function test_secrets_never_reach_persisted_logs_events_inertia_or_broadcasts(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $project = $this->project($admin, ProjectStatus::RUNNING);
        $execution = $this->execution($project, ExecutionStatus::RUNNING, creator: $admin);
        $secret = 'synthetic-secret-1g';
        $message = 'Authorization: Bearer '.$secret;
        $payload = ['nested' => ['smtp_password' => $secret], 'safe' => 'visible'];
        $event = app(ExecutionEventRecorder::class)->record($execution, 'quality.privacy', message: $message, payload: $payload);
        $log = $execution->logs()->create(['stream' => 'SYSTEM', 'level' => 'INFO', 'message' => $message, 'context' => $payload]);
        $this->assertStringNotContainsString($secret, $event->fresh()->toJson());
        $this->assertStringNotContainsString($secret, $log->fresh()->toJson());
        $this->assertStringContainsString('visible', $event->toJson());
        $this->assertStringNotContainsString($secret, json_encode((new ExecutionEventBroadcast($event))->broadcastWith(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($secret, json_encode(app(ExecutionPresenter::class)->event($event), JSON_THROW_ON_ERROR));
        $this->actingAs($admin)->get(route('projects.executions.show', [$project->uuid, $execution->uuid]))->assertOk()->assertDontSee($secret);
        $this->getJson(route('projects.executions.events', [$project->uuid, $execution->uuid]))->assertOk()->assertDontSee($secret);
    }
}
