<?php

namespace App\Domain\Remote;

use App\Domain\Tools\ToolDistributionVerifier;
use App\Enums\ConnectionStatus;
use App\Exceptions\RemoteAgentUnavailable;
use App\Exceptions\ToolOperationBlocked;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\ToolDistribution;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Deploys a catalog tool tree to a remote server. The tree is verified here
 * first, travels in base64 chunks to the agent's staging area and is installed
 * by the agent only if its canonical tree hash equals the catalog value.
 */
class RemoteToolDeployer
{
    public function __construct(
        private readonly RemoteServerConnections $connections,
        private readonly RemoteAgent $agent,
        private readonly RemoteTransportResolver $transports,
        private readonly ToolDistributionVerifier $verifier,
    ) {}

    /** @return array{installed: bool, already_present: bool, key: string, tree_sha256: string, chunks: int, error_code: string|null} */
    public function deploy(Server $server, ToolDistribution $distribution, ?User $actor): array
    {
        $this->connections->assertEnabled();
        $this->assertDeployable($server, $distribution);
        $verified = $this->verifier->verify($distribution);
        $target = $this->connections->target($server);

        $bundle = $this->bundle($verified->sourceRoot);
        $bundleSha = hash('sha256', $bundle);
        $staging = (string) Str::uuid();
        $chunks = str_split(base64_encode($bundle), max(4096, (int) config('toolkit.remote.deploy_chunk_bytes', 16384)));
        $directory = rtrim((string) config('toolkit.remote.agent_state_dir'), '/').'/staging/'.$staging;
        $errorCode = null;
        $response = null;

        try {
            foreach ($chunks as $index => $chunk) {
                // The base64 alphabet has no quote characters, so single quotes are sufficient.
                $script = implode("\n", [
                    'set -eu',
                    'umask 077',
                    'mkdir -p '.escapeshellarg($directory),
                    "printf %s '".$chunk."' > ".escapeshellarg(sprintf('%s/part-%06d', $directory, $index)),
                    'printf "%s\n" staged',
                    '',
                ]);
                $result = $this->transports->for($target)->run($target, $script, (int) config('toolkit.remote.call_timeout_seconds', 60));
                if (! $result->completed() || $result->exitCode !== 0 || trim($result->stdout) !== 'staged') {
                    throw new RemoteAgentUnavailable($result->errorCode ?? 'tool_staging_failed', 'No se pudo transferir la distribución al servidor.');
                }
            }
            $response = $this->agent->call($target, 'tool-commit', [$distribution->key, $verified->treeSha256, $staging, $bundleSha], 300);
            $errorCode = $response->ok ? null : $response->errorCode;
        } catch (RemoteAgentUnavailable $exception) {
            $errorCode = $exception->errorCode;
        }

        $installed = $response !== null && $response->ok;
        $alreadyPresent = $installed && ($response->data['already_present'] ?? false) === true;
        DB::transaction(function () use ($server, $distribution, $verified, $actor, $target, $installed, $errorCode, $chunks, $bundleSha): void {
            $locked = Server::query()->whereKey($server->getKey())->lockForUpdate()->firstOrFail();
            if ($installed) {
                $metadata = $locked->metadata ?? [];
                $metadata['remote_tools'][$distribution->key] = [
                    'tree_sha256' => $verified->treeSha256,
                    'file_count' => $verified->fileCount,
                    'deployed_at' => now()->utc()->toIso8601String(),
                ];
                $locked->update(['metadata' => $metadata]);
            }
            AuditLog::query()->create([
                'actor_id' => $actor?->getKey(), 'project_id' => $locked->project_id, 'action' => 'REMOTE_TOOL_DEPLOYED',
                'auditable_type' => $locked->getMorphClass(), 'auditable_id' => $locked->getKey(),
                'payload' => ['target' => $target->describe(), 'distribution_key' => $distribution->key, 'tree_sha256' => $verified->treeSha256,
                    'bundle_sha256' => $bundleSha, 'chunks' => count($chunks), 'result' => $installed ? 'SUCCESS' : 'ERROR', 'error_code' => $errorCode],
            ]);
        });

        return ['installed' => $installed, 'already_present' => $alreadyPresent, 'key' => $distribution->key,
            'tree_sha256' => $verified->treeSha256, 'chunks' => count($chunks), 'error_code' => $errorCode];
    }

    /** @return array<string, mixed> */
    public function status(Server $server, ToolDistribution $distribution): array
    {
        $this->connections->assertEnabled();
        $response = $this->agent->call($this->connections->target($server), 'tool-status', [$distribution->key, $distribution->distribution_sha256]);
        if (! $response->ok) {
            throw new RemoteAgentUnavailable((string) $response->errorCode, 'El agente no pudo informar el estado de la herramienta.');
        }

        return $response->data;
    }

    private function assertDeployable(Server $server, ToolDistribution $distribution): void
    {
        if ($this->connections->primary($server)?->status !== ConnectionStatus::VALID) {
            throw new ToolOperationBlocked('El servidor debe superar la prueba de conexión antes de recibir herramientas.');
        }
        if ($distribution->verification_state !== 'VERIFIED') {
            throw new ToolOperationBlocked('Solo se despliegan distribuciones verificadas del catálogo.');
        }
        // A deployed tree must hash exactly like the catalog entry; excluded or mutable
        // files would change that hash and need their own contract.
        if (($distribution->deployment_exclusions ?? []) !== [] || ($distribution->mutable_paths ?? []) !== []) {
            throw new ToolOperationBlocked('La distribución tiene archivos excluidos o mutables; su despliegue remoto no está soportado todavía.');
        }
    }

    private function bundle(string $root): string
    {
        $process = new Process(['tar', '-czf', '-', '-C', $root, '.'], null, ['LC_ALL' => 'C'], null, 120);
        $process->run();
        if (! $process->isSuccessful() || $process->getOutput() === '') {
            throw new RuntimeException('No se pudo empaquetar la distribución verificada.');
        }

        return $process->getOutput();
    }
}
