<?php

namespace Tests\Feature\Executions;

use App\Domain\Realtime\ProjectSessionChannels;
use App\Enums\EventSeverity;
use App\Enums\ExecutionStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Events\ExecutionEventBroadcast;
use App\Models\Execution;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Domain\DomainTestCase;
use Tests\Support\ReverbWebSocketClient;

class RealtimeRevocationTest extends DomainTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('broadcasting.default', 'reverb');
        config()->set('session.driver', 'database');
    }

    public function test_real_websocket_distribution_stops_after_account_deactivation(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $operator = $this->user(UserRole::OPERATOR);
        $project = $this->project($admin, ProjectStatus::RUNNING);
        $project->assignments()->create([
            'user_id' => $operator->getKey(),
            'assigned_by' => $admin->getKey(),
        ]);
        $execution = $this->execution($project, ExecutionStatus::RUNNING, creator: $admin);
        $adminSocket = $this->connect($project, $admin, 'realtime-admin-session');
        $operatorSocket = $this->connect($project, $operator, 'realtime-operator-session');

        try {
            $this->publish($execution, 1, 'visible-before-revocation');
            $this->assertEventSequence($adminSocket, 1);
            $this->assertEventSequence($operatorSocket, 1);

            $operator->update(['is_active' => false]);
            $this->publish($execution, 2, 'secret-after-deactivation');
            $this->assertEventSequence($adminSocket, 2);
            $this->assertNull($operatorSocket->receiveEvent('execution.event', 0.75));

            $this->assertReconnectRejected($project, $operator);
        } finally {
            $adminSocket->close();
            $operatorSocket->close();
        }
    }

    public function test_real_websocket_distribution_honors_assignment_role_and_session_revocation(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $assigned = $this->user(UserRole::OPERATOR);
        $demotedAdmin = $this->user(UserRole::ADMIN);
        $loggedOut = $this->user(UserRole::AUDITOR);
        $project = $this->project($admin, ProjectStatus::RUNNING);

        foreach ([$assigned, $loggedOut] as $user) {
            $project->assignments()->create([
                'user_id' => $user->getKey(),
                'assigned_by' => $admin->getKey(),
            ]);
        }

        $execution = $this->execution($project, ExecutionStatus::RUNNING, creator: $admin);
        $adminSocket = $this->connect($project, $admin, 'surviving-admin-session');
        $assignedSocket = $this->connect($project, $assigned, 'assigned-operator-session');
        $roleSocket = $this->connect($project, $demotedAdmin, 'demoted-admin-session');
        $logoutSocket = $this->connect($project, $loggedOut, 'logged-out-session');

        try {
            $this->publish($execution, 1, 'visible-to-all-authorized-sessions');

            foreach ([$adminSocket, $assignedSocket, $roleSocket, $logoutSocket] as $socket) {
                $this->assertEventSequence($socket, 1);
            }

            $project->assignments()->where('user_id', $assigned->getKey())->delete();
            $demotedAdmin->update(['role' => UserRole::OPERATOR]);
            DB::table('sessions')->where('id', 'logged-out-session')->delete();
            $this->publish($execution, 2, 'visible-only-to-currently-authorized-sessions');

            $this->assertEventSequence($adminSocket, 2);
            $this->assertNull($assignedSocket->receiveEvent('execution.event', 0.75));
            $this->assertNull($roleSocket->receiveEvent('execution.event', 0.75));
            $this->assertNull($logoutSocket->receiveEvent('execution.event', 0.75));
            $this->assertReconnectRejected($project, $assigned);
            $this->assertReconnectRejected($project, $demotedAdmin);
        } finally {
            $adminSocket->close();
            $assignedSocket->close();
            $roleSocket->close();
            $logoutSocket->close();
        }
    }

    private function connect(Project $project, User $user, string $sessionId): ReverbWebSocketClient
    {
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Reverb regression',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
        $socket = ReverbWebSocketClient::connect();
        $socket->subscribe(app(ProjectSessionChannels::class)->current($project, $sessionId));

        return $socket;
    }

    private function publish(Execution $execution, int $sequence, string $message): void
    {
        $event = $execution->events()->create([
            'sequence' => $sequence,
            'type' => 'revocation.regression',
            'severity' => EventSeverity::INFO,
            'progress' => $sequence,
            'message' => $message,
            'payload' => ['private' => $message],
        ]);

        broadcast(new ExecutionEventBroadcast($event));
    }

    private function assertEventSequence(ReverbWebSocketClient $socket, int $sequence): void
    {
        $message = $socket->receiveEvent('execution.event', 5);
        $this->assertNotNull($message);
        $this->assertSame($sequence, data_get($message, 'data.event.sequence'));
    }

    private function assertReconnectRejected(Project $project, User $user): void
    {
        Event::fake();
        $this->actingAs($user);
        $channel = app(ProjectSessionChannels::class)->current($project, session()->getId());

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-'.$channel,
        ])->assertForbidden();
    }
}
