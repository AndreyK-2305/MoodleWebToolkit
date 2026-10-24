<?php

namespace Tests\Feature\Remote;

use App\Domain\Remote\RemoteAgent;
use App\Domain\Remote\RemoteAgentOperations;
use App\Domain\Remote\RemoteServerConnections;
use App\Enums\ConnectionStatus;
use App\Enums\ConnectionType;
use App\Enums\ExecutionStatus;
use App\Enums\ProjectStatus;
use App\Enums\RemoteCommunicationState;
use App\Enums\RemoteFunctionalState;
use App\Enums\ServerRole;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\RemoteOperation;
use App\Models\Server;
use App\Models\User;
use Closure;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Domain\DomainTestCase;
use Tests\Support\QueuedRemoteTransport;

/**
 * While a project is in progress its remote panel is read-only, but an administrator can
 * replace a server's agent from the web when nothing runs on that server.
 */
class RemoteAgentUpdateTest extends DomainTestCase
{
    private User $admin;

    private Project $running;

    private Execution $execution;

    private Server $server;

    private QueuedRemoteTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        config(['toolkit.features.remote_agent.enabled' => true]);
        $this->transport = QueuedRemoteTransport::bind($this->app);
        $this->admin = $this->user(UserRole::ADMIN);
        $this->running = $this->project($this->admin, ProjectStatus::WAITING_USER_ACTION);
        $this->running->configuration()->create(['version' => 1, 'settings' => []]);
        $this->execution = $this->execution($this->running, ExecutionStatus::WAITING_USER_ACTION);
        $this->server = $this->running->servers()->create(['name' => 'Destino EC2', 'role' => ServerRole::DESTINATION, 'host' => 'campus.example.edu']);
        app(RemoteServerConnections::class)->configure($this->server, ConnectionType::AWS_SSM,
            ['instance_id' => 'i-0d69555bdfa275322', 'region' => 'us-east-2'], null, null)->update(['status' => ConnectionStatus::VALID]);
    }

    public function test_only_an_active_administrator_updates_with_a_fresh_password_and_an_explicit_confirmation(): void
    {
        $url = $this->url();
        $operator = $this->user();
        ProjectAssignment::query()->create(['project_id' => $this->running->id, 'user_id' => $operator->id, 'assigned_by' => $this->admin->id]);
        $this->confirmed($operator)->post($url, ['acknowledged' => true])->assertForbidden();
        $this->confirmed($this->user(UserRole::AUDITOR))->post($url, ['acknowledged' => true])->assertForbidden();
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => now()->timestamp - (int) config('auth.password_timeout') - 1])
            ->postJson($url, ['acknowledged' => true])->assertStatus(423);
        $this->confirmed($this->admin)->post($url)->assertSessionHasErrors(['acknowledged' => 'Confirme que desea actualizar el agente.']);
        $this->confirmed($this->admin)->post($url, ['acknowledged' => false])->assertSessionHasErrors('acknowledged');

        $foreign = $this->project($this->admin)->servers()->create(['name' => 'Ajeno', 'role' => ServerRole::DESTINATION, 'host' => 'ajeno.example.edu']);
        $this->confirmed($this->admin)->post(route('projects.servers.connection.update-agent', [$this->running->uuid, $foreign->uuid]), ['acknowledged' => true])
            ->assertNotFound();

        config(['toolkit.features.remote_agent.enabled' => false]);
        $this->confirmed($this->admin)->post($url, ['acknowledged' => true])->assertStatus(409);

        $this->assertSame([], $this->transport->subcommands());
        $this->assertSame(0, AuditLog::query()->where('action', 'REMOTE_AGENT_UPDATED')->count());
    }

    public function test_an_operation_in_progress_on_the_server_rejects_the_update_without_calling_it(): void
    {
        $operation = $this->operation(RemoteCommunicationState::CONNECTED, RemoteFunctionalState::RUNNING);

        $this->confirmed($this->admin)->post($this->url(), ['acknowledged' => true])
            ->assertSessionHasErrors(['agent' => 'No se puede actualizar el agente de Destino EC2 mientras una operación remota está en curso.'
                .' Espere a que termine o a que la Ejecución quede esperando una acción del administrador.']);
        $this->assertSame([], $this->transport->subcommands());
        $this->presented(fn (Assert $page) => $page->where('remoteAccess.servers.0.agent_update_blocked', 'una operación remota está en curso'));

        // A preparation of the destination also runs through the agent.
        $operation->update(['communication_state' => RemoteCommunicationState::TERMINATED, 'functional_state' => RemoteFunctionalState::SUCCEEDED, 'terminated_at' => now()]);
        $this->server->update(['metadata' => ['remote_destination_preparations' => ['campus' => ['state' => 'RUNNING']]]]);
        $this->confirmed($this->admin)->post($this->url(), ['acknowledged' => true])
            ->assertSessionHasErrors(['agent' => 'No se puede actualizar el agente de Destino EC2 mientras una preparación del destino está en curso.'
                .' Espere a que termine o a que la Ejecución quede esperando una acción del administrador.']);
        $this->assertSame([], $this->transport->subcommands());
    }

    public function test_a_waiting_execution_lets_the_administrator_install_and_verify_the_agent(): void
    {
        $this->operation(RemoteCommunicationState::TERMINATED, RemoteFunctionalState::WAITING);
        $this->presented(fn (Assert $page) => $page->where('project.can_edit', false)->where('remoteAccess.servers.0.agent_update_blocked', null));
        $sha = $this->sha();
        $this->transport->ok('version', ['agent_sha256' => str_repeat('0', 64)]);
        $this->transport->ok('version', ['agent_sha256' => $sha]);
        $this->transport->ok('version', ['agent_sha256' => $sha]);

        $this->confirmed($this->admin)->post($this->url(), ['acknowledged' => true])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('projects.show', $this->running->uuid))
            ->assertInertiaFlash('toast.type', 'success');

        // The installed version is asked to the agent after installing it.
        $this->assertSame(['version', 'install', 'version'], $this->transport->subcommands());
        $audit = AuditLog::query()->where('action', 'REMOTE_AGENT_UPDATED')->sole();
        $this->assertSame(['result' => 'SUCCESS', 'error_code' => null], array_intersect_key($audit->payload, ['result' => 1, 'error_code' => 1]));
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertSame(1, AuditLog::query()->where('action', 'REMOTE_AGENT_INSTALLED')->count());
        $this->assertSame(ProjectStatus::WAITING_USER_ACTION, $this->running->fresh()?->status);
        $this->assertSame(ExecutionStatus::WAITING_USER_ACTION, $this->execution->fresh()?->status);
    }

    public function test_an_agent_that_already_matches_is_not_installed_again(): void
    {
        $sha = $this->sha();
        $this->transport->ok('version', ['agent_sha256' => $sha]);
        $this->transport->ok('version', ['agent_sha256' => $sha]);

        foreach ([1, 2] as $attempt) {
            $this->confirmed($this->admin)->post($this->url(), ['acknowledged' => true])
                ->assertSessionHasNoErrors()->assertInertiaFlash('toast.type', 'success');
        }

        $this->assertSame(['version', 'version'], $this->transport->subcommands());
        $this->assertSame(['ALREADY_CURRENT', 'ALREADY_CURRENT'], AuditLog::query()->where('action', 'REMOTE_AGENT_UPDATED')
            ->orderBy('id')->get()->map(fn (AuditLog $log): string => $log->payload['result'])->all());
        $this->assertSame(0, AuditLog::query()->where('action', 'REMOTE_AGENT_INSTALLED')->count());
    }

    public function test_an_agent_that_does_not_answer_the_expected_version_after_installing_is_reported(): void
    {
        $sha = $this->sha();
        $this->transport->error('version', 'agent_not_installed', 127);
        $this->transport->ok('version', ['agent_sha256' => $sha]);
        $this->transport->unreachable();

        $this->confirmed($this->admin)->post($this->url(), ['acknowledged' => true])
            ->assertSessionHasNoErrors()->assertInertiaFlash('toast.type', 'error');

        $this->assertSame(['version', 'install', 'version'], $this->transport->subcommands());
        $this->assertSame('agent_version_mismatch', AuditLog::query()->where('action', 'REMOTE_AGENT_UPDATED')->sole()->payload['error_code']);
    }

    public function test_a_completed_project_never_updates_its_agent(): void
    {
        $completed = $this->project($this->admin, ProjectStatus::COMPLETED);
        $server = $completed->servers()->create(['name' => 'Cerrado', 'role' => ServerRole::DESTINATION, 'host' => 'cerrado.example.edu']);

        $this->confirmed($this->admin)->post(route('projects.servers.connection.update-agent', [$completed->uuid, $server->uuid]), ['acknowledged' => true])
            ->assertSessionHasErrors('agent');
        $this->assertSame([], $this->transport->subcommands());
    }

    private function confirmed(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    }

    private function url(): string
    {
        return route('projects.servers.connection.update-agent', [$this->running->uuid, $this->server->uuid]);
    }

    private function sha(): string
    {
        return app(RemoteAgent::class)->distribution()['sha256'];
    }

    private function presented(Closure $assert): void
    {
        $this->actingAs($this->admin)->get(route('projects.show', $this->running->uuid))->assertInertia($assert);
    }

    private function operation(RemoteCommunicationState $communication, RemoteFunctionalState $functional): RemoteOperation
    {
        return RemoteOperation::query()->create([
            'execution_id' => $this->execution->id, 'operation_uuid' => (string) Str::uuid(), 'idempotency_key' => 'consolidation-run',
            'provider_key' => RemoteAgentOperations::PROVIDER, 'host_id' => $this->server->uuid, 'runtime_key' => RemoteAgentOperations::RUNTIME,
            'command_key' => 'consolidator.run', 'command_sha256' => str_repeat('a', 64),
            'communication_state' => $communication, 'functional_state' => $functional,
            'terminated_at' => $communication === RemoteCommunicationState::TERMINATED ? now() : null,
        ]);
    }
}
