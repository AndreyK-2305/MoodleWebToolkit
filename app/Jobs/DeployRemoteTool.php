<?php

namespace App\Jobs;

use App\Domain\Remote\CheckRemoteDestination;
use App\Domain\Remote\RemoteServerPreparation;
use App\Domain\Remote\RemoteToolDeployer;
use App\Exceptions\RemoteAgentUnavailable;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Server;
use App\Models\ToolDistribution;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deploys a large catalog tree in the background: the Consolidator travels in
 * dozens of calls, too many for one HTTP request. Its state is kept on the
 * server's metadata so the panel can show it.
 */
final class DeployRemoteTool implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 960;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $serverId, public readonly int $distributionId, public readonly ?int $actorId) {}

    public function uniqueId(): string
    {
        return 'remote-tool-deploy:'.$this->serverId;
    }

    public function handle(RemoteToolDeployer $deployer, RemoteServerPreparation $preparation): void
    {
        $server = Server::query()->find($this->serverId);
        $distribution = ToolDistribution::query()->find($this->distributionId);
        if ($server === null || $distribution === null) {
            return;
        }
        self::record($server, $distribution->key, 'RUNNING', null);
        try {
            $actor = $this->actorId === null ? null : User::query()->find($this->actorId);
            $result = $deployer->deploy($server, $distribution, $actor);
            self::record($server, $distribution->key, $result['installed'] ? 'DEPLOYED' : 'FAILED', $result['error_code']);
            // Profiles of a destination follow the Consolidator just deployed while their site
            // has not been written; each one says why it was not moved otherwise.
            if ($result['installed'] && $distribution->key === CheckRemoteDestination::DISTRIBUTION_KEY) {
                $preparation->repoint($server->refresh(), $result['tree_sha256'], $actor);
            }
        } catch (RemoteAgentUnavailable $exception) {
            self::record($server, $distribution->key, 'FAILED', $exception->errorCode);
        } catch (ToolOperationBlocked) {
            self::record($server, $distribution->key, 'FAILED', 'deployment_blocked');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $server = Server::query()->find($this->serverId);
        $distribution = ToolDistribution::query()->find($this->distributionId);
        if ($server !== null && $distribution !== null) {
            self::record($server, $distribution->key, 'FAILED', 'deployment_interrupted');
        }
    }

    public static function record(Server $server, string $key, string $state, ?string $errorCode): void
    {
        DB::transaction(function () use ($server, $key, $state, $errorCode): void {
            $locked = Server::query()->whereKey($server->getKey())->lockForUpdate()->firstOrFail();
            $metadata = $locked->metadata ?? [];
            $metadata['remote_tool_deployments'][$key] = ['state' => $state, 'error_code' => $errorCode,
                'updated_at' => now()->utc()->toIso8601String()];
            $locked->update(['metadata' => $metadata]);
        });
    }
}
