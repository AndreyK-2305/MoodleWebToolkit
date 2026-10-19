<?php

namespace App\Domain\Remote;

use App\Exceptions\RemoteAgentUnavailable;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;

/**
 * Installing writes to the remote server, so it is an explicit, audited
 * action and never a side effect of testing a connection.
 */
class InstallRemoteAgent
{
    public function __construct(
        private readonly RemoteServerConnections $connections,
        private readonly RemoteAgentInstaller $installer,
        private readonly RemoteAgent $agent,
    ) {}

    /** @return array{installed: bool, agent_version: string, agent_sha256: string, error_code: string|null} */
    public function handle(Server $server, ?User $actor): array
    {
        $this->connections->assertEnabled();
        $target = $this->connections->target($server);
        $distribution = $this->agent->distribution();
        $errorCode = null;
        try {
            $response = $this->installer->install($target);
            $errorCode = $response->ok ? null : $response->errorCode;
        } catch (RemoteAgentUnavailable $exception) {
            $errorCode = $exception->errorCode;
        }

        AuditLog::query()->create([
            'actor_id' => $actor?->getKey(), 'project_id' => $server->project_id, 'action' => 'REMOTE_AGENT_INSTALLED',
            'auditable_type' => $server->getMorphClass(), 'auditable_id' => $server->getKey(),
            'payload' => ['target' => $target->describe(), 'agent_version' => $distribution['version'],
                'agent_sha256' => $distribution['sha256'], 'result' => $errorCode === null ? 'SUCCESS' : 'ERROR', 'error_code' => $errorCode],
        ]);

        return ['installed' => $errorCode === null, 'agent_version' => $distribution['version'],
            'agent_sha256' => $distribution['sha256'], 'error_code' => $errorCode];
    }
}
