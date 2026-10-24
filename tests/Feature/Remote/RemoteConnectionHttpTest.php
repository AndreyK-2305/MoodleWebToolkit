<?php

namespace Tests\Feature\Remote;

use App\Domain\Projects\ProjectWizard;
use App\Domain\Remote\RemoteAgent;
use App\Enums\ConnectionStatus;
use App\Enums\ServerRole;
use App\Enums\UserRole;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Domain\DomainTestCase;
use Tests\Support\QueuedRemoteTransport;

class RemoteConnectionHttpTest extends DomainTestCase
{
    private const HOST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl';

    private User $admin;

    private Project $project;

    private Server $server;

    private QueuedRemoteTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = QueuedRemoteTransport::bind($this->app);
        $this->admin = $this->user(UserRole::ADMIN);
        $this->project = app(ProjectWizard::class)->create($this->admin, ['name' => 'Consolidación', 'type' => 'CONSOLIDATE']);
        $this->server = $this->project->servers()->create(['name' => 'Origen Pregrado', 'role' => ServerRole::SOURCE, 'host' => 'origen.example.edu']);
    }

    public function test_endpoints_require_flag_permission_fresh_confirmation_and_own_server(): void
    {
        $url = $this->url('update');
        $this->actingAs($this->admin)->putJson($url, $this->ssm())->assertStatus(409);

        config(['toolkit.features.remote_agent.enabled' => true]);
        $this->actingAs($this->user())->putJson($url, $this->ssm())->assertForbidden();
        $this->actingAs($this->user(UserRole::AUDITOR))->putJson($url, $this->ssm())->assertForbidden();
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => now()->timestamp - (int) config('auth.password_timeout') - 1])
            ->putJson($url, $this->ssm())->assertStatus(423);
        $this->actingAs($this->admin)->putJson($url, [...$this->ssm(), 'command' => 'id'])->assertUnprocessable();

        $foreign = app(ProjectWizard::class)->create($this->admin, ['name' => 'Otro', 'type' => 'COLLECT'])
            ->servers()->create(['name' => 'Ajeno', 'role' => ServerRole::SOURCE, 'host' => 'ajeno.example.edu']);
        $this->actingAs($this->admin)->putJson(route('projects.servers.connection.update', [$this->project->uuid, $foreign->uuid]), $this->ssm())->assertNotFound();
        $this->assertSame(0, $this->server->connections()->count());
    }

    public function test_connection_is_saved_tested_and_presented_without_secrets(): void
    {
        config(['toolkit.features.remote_agent.enabled' => true]);
        $this->actingAs($this->admin)->putJson($this->url('update'), [
            'type' => 'ssh', 'host' => 'origen.example.edu', 'port' => 2222, 'user' => 'ubuntu', 'host_key' => self::HOST_KEY,
            'secret_reference' => 'origen-ssh-key', 'secret_version' => '2', 'use_sudo' => true,
        ])->assertRedirect(route('projects.show', $this->project->uuid));
        $this->assertSame('origen-ssh-key', $this->server->connections()->sole()->secret_reference);

        $sha = app(RemoteAgent::class)->distribution()['sha256'];
        $this->transport->ok('version', ['agent_sha256' => $sha, 'launcher' => 'systemd']);
        $this->transport->ok('probe', ['agent_sha256' => $sha, 'launcher' => 'systemd', 'bash_version' => '5.2.21(1)-release',
            'disk_free_bytes' => 200 * 1073741824, 'os_name' => 'Ubuntu 24.04 LTS', 'cpus' => 4]);
        $this->actingAs($this->admin)->postJson($this->url('test'))->assertRedirect(route('projects.show', $this->project->uuid));
        $this->assertSame(ConnectionStatus::VALID, $this->server->connections()->sole()->status);

        $response = $this->actingAs($this->admin)->get(route('projects.show', $this->project->uuid));
        $response->assertInertia(fn (Assert $page) => $page
            ->where('remoteAccess.enabled', true)
            ->where('remoteAccess.servers.0.uuid', $this->server->uuid)
            ->where('remoteAccess.servers.0.connection.status', 'VALID')
            ->where('remoteAccess.servers.0.connection.port', 2222)
            ->where('remoteAccess.servers.0.connection.has_secret_reference', true)
            ->where('remoteAccess.servers.0.connection.host_key_type', 'ssh-ed25519')
            ->where('remoteAccess.servers.0.connection.host_key_fingerprint',
                'SHA256:'.rtrim(base64_encode(hash('sha256', (string) base64_decode(explode(' ', self::HOST_KEY)[1], true), true)), '='))
            ->where('remoteAccess.servers.0.evidence.status', 'VALID')
            ->where('remoteAccess.servers.0.evidence.probe.os_name', 'Ubuntu 24.04 LTS'));
        $content = (string) $response->getContent();
        $this->assertStringNotContainsString('origen-ssh-key', $content);
        $this->assertStringNotContainsString('AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl', $content);
    }

    public function test_invalid_configuration_returns_field_errors(): void
    {
        config(['toolkit.features.remote_agent.enabled' => true]);
        $this->actingAs($this->admin)->putJson($this->url('update'), ['type' => 'aws-ssm', 'instance_id' => 'i-xyz', 'region' => 'us-east-1'])
            ->assertUnprocessable()->assertJsonValidationErrors(['instance_id']);
        $this->actingAs($this->admin)->putJson($this->url('update'), ['type' => 'ssh', 'host' => 'origen;id', 'user' => 'ubuntu', 'host_key' => self::HOST_KEY,
            'secret_reference' => 'origen-ssh-key', 'secret_version' => '1'])->assertUnprocessable()->assertJsonValidationErrors(['host']);
        $this->assertSame(0, $this->server->connections()->count());
    }

    public function test_install_agent_reports_the_result(): void
    {
        config(['toolkit.features.remote_agent.enabled' => true]);
        $this->actingAs($this->admin)->putJson($this->url('update'), $this->ssm())->assertRedirect();
        $this->transport->unreachable();

        $this->actingAs($this->admin)->post($this->url('install-agent'))
            ->assertRedirect(route('projects.show', $this->project->uuid))
            ->assertInertiaFlash('toast.type', 'error');
        $this->assertSame(['install'], $this->transport->subcommands());
    }

    public function test_a_successful_install_tests_the_connection_right_away(): void
    {
        config(['toolkit.features.remote_agent.enabled' => true]);
        $this->actingAs($this->admin)->putJson($this->url('update'), $this->ssm())->assertRedirect();
        $sha = app(RemoteAgent::class)->distribution()['sha256'];
        $this->transport->ok('version', ['agent_sha256' => $sha]);
        $this->transport->ok('version', ['agent_sha256' => $sha]);
        $this->transport->ok('probe', ['agent_sha256' => $sha, 'launcher' => 'systemd', 'bash_version' => '5.2.21(1)-release',
            'disk_free_bytes' => 200 * 1073741824, 'os_name' => 'Ubuntu 24.04 LTS', 'cpus' => 4]);

        $this->actingAs($this->admin)->post($this->url('install-agent'))
            ->assertRedirect(route('projects.show', $this->project->uuid))
            ->assertInertiaFlash('toast.type', 'success');
        $this->assertSame(['install', 'version', 'probe'], $this->transport->subcommands());
        $this->assertSame(ConnectionStatus::VALID, $this->server->connections()->sole()->status);
    }

    public function test_a_new_connection_suggests_the_configured_region(): void
    {
        config(['toolkit.remote.default_region' => 'sa-east-1']);

        $this->actingAs($this->admin)->get(route('projects.show', $this->project->uuid))
            ->assertInertia(fn (Assert $page) => $page->where('remoteAccess.default_region', 'sa-east-1'));
    }

    public function test_panel_is_hidden_while_the_flag_is_closed(): void
    {
        $this->actingAs($this->admin)->get(route('projects.show', $this->project->uuid))
            ->assertInertia(fn (Assert $page) => $page->where('remoteAccess.enabled', false)->where('remoteAccess.servers', []));
    }

    private function url(string $action): string
    {
        return route('projects.servers.connection.'.$action, [$this->project->uuid, $this->server->uuid]);
    }

    /** @return array<string, string> */
    private function ssm(): array
    {
        return ['type' => 'aws-ssm', 'instance_id' => 'i-0123456789abcdef0', 'region' => 'us-east-1'];
    }
}
