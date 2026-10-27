<?php

namespace App\Domain\Remote;

use App\Enums\ConnectionStatus;
use App\Enums\PreflightResult;
use App\Exceptions\RemoteAgentUnavailable;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Real connection preflight: reaches the server through its configured
 * transport, verifies the installed agent and records what the server
 * reports. It does not install or change anything on the server.
 */
class TestRemoteConnection
{
    /** Fields of the probe that are kept as server evidence. */
    private const PROBE_FIELDS = [
        'hostname', 'os_id', 'os_version', 'os_name', 'kernel', 'arch', 'cpus', 'memory_bytes', 'is_root',
        'systemd', 'systemd_version', 'launcher', 'bash_version', 'disk_total_bytes', 'disk_free_bytes',
        'php_version', 'docker_version', 'compose_version', 'pwsh_version', 'aws_cli_version', 'observed_at',
    ];

    public function __construct(
        private readonly RemoteServerConnections $connections,
        private readonly RemoteAgent $agent,
    ) {}

    /**
     * @return array{status: string, checks: list<array{key: string, result: string, message: string}>, probe: array<string, mixed>|null}
     */
    public function handle(Server $server, ?User $actor): array
    {
        $this->connections->assertEnabled();
        $target = $this->connections->target($server);
        $distribution = $this->agent->distribution();
        $checks = [];
        $probe = null;

        try {
            $version = $this->agent->call($target, 'version');
            $checks[] = $this->check('transport', PreflightResult::SUCCESS, 'El servidor respondió mediante '.$target->type->label().'.');
            if (! $version->ok) {
                $checks[] = $version->errorCode === 'agent_not_installed'
                    ? $this->check('agent', PreflightResult::ERROR, 'El agente '.$distribution['version'].' no está instalado en el servidor.')
                    : $this->check('agent', PreflightResult::ERROR, 'El agente respondió con el error '.$version->errorCode.'.');
            } elseif ($version->agentVersion !== $distribution['version'] || ($version->data['agent_sha256'] ?? null) !== $distribution['sha256']) {
                $checks[] = $this->check('agent', PreflightResult::ERROR, 'El agente instalado no coincide con la distribución verificada; reinstálelo.');
            } else {
                $checks[] = $this->check('agent', PreflightResult::SUCCESS, 'Agente '.$distribution['version'].' verificado por SHA-256.');
                $response = $this->agent->call($target, 'probe');
                if (! $response->ok) {
                    $checks[] = $this->check('probe', PreflightResult::ERROR, 'El diagnóstico del servidor falló con '.$response->errorCode.'.');
                } else {
                    $probe = array_intersect_key($response->data, array_flip(self::PROBE_FIELDS));
                    array_push($checks, ...$this->probeChecks($probe));
                }
            }
        } catch (RemoteAgentUnavailable $exception) {
            $checks[] = $this->check('transport', PreflightResult::ERROR, $exception->getMessage().' Código: '.$exception->errorCode.'.');
        }

        $status = collect($checks)->contains(fn (array $check): bool => $check['result'] === PreflightResult::ERROR->value)
            ? ConnectionStatus::INVALID : ConnectionStatus::VALID;

        DB::transaction(function () use ($server, $actor, $target, $checks, $probe, $status, $distribution): void {
            $locked = Server::query()->whereKey($server->getKey())->lockForUpdate()->firstOrFail();
            /** @var Connection $connection */
            $connection = $locked->connections()->where('name', RemoteServerConnections::PRIMARY)->lockForUpdate()->firstOrFail();
            $connection->update(['status' => $status]);
            $metadata = $locked->metadata ?? [];
            $metadata['remote_probe'] = [
                'checked_at' => now()->utc()->toIso8601String(),
                'status' => $status->value,
                'agent_version' => $distribution['version'],
                'checks' => $checks,
                'probe' => $probe,
            ];
            $locked->update(['metadata' => $metadata]);
            AuditLog::query()->create([
                'actor_id' => $actor?->getKey(), 'project_id' => $locked->project_id, 'action' => 'REMOTE_CONNECTION_TESTED',
                'auditable_type' => $locked->getMorphClass(), 'auditable_id' => $locked->getKey(),
                'payload' => ['target' => $target->describe(), 'status' => $status->value, 'checks' => $checks],
            ]);
        });

        return ['status' => $status->value, 'checks' => $checks, 'probe' => $probe];
    }

    /**
     * @param  array<string, mixed>  $probe
     * @return list<array{key: string, result: string, message: string}>
     */
    private function probeChecks(array $probe): array
    {
        $checks = [];
        $checks[] = match ($probe['launcher'] ?? null) {
            'systemd' => $this->check('launcher', PreflightResult::SUCCESS, 'systemd disponible con privilegios de root: los procesos sobreviven a la desconexión.'),
            'nohup' => $this->check('launcher', PreflightResult::WARNING, 'Sin systemd: los procesos se desacoplan con setsid y no sobreviven a un reinicio del servidor.'),
            default => $this->check('launcher', PreflightResult::ERROR, 'El agente no puede lanzar procesos durables: requiere systemd y root (o sudo sin contraseña).'),
        };

        $bash = (string) ($probe['bash_version'] ?? '');
        $checks[] = preg_match('/^([4-9]|[1-9][0-9])\./', $bash) === 1
            ? $this->check('bash', PreflightResult::SUCCESS, 'Bash '.$bash.'.')
            : $this->check('bash', PreflightResult::ERROR, 'Se requiere Bash 4 o superior.');

        $free = $probe['disk_free_bytes'] ?? null;
        $minimum = (int) config('toolkit.remote.minimum_free_bytes');
        $checks[] = match (true) {
            ! is_int($free) => $this->check('disk', PreflightResult::WARNING, 'No se pudo medir el espacio libre del servidor.'),
            $free < $minimum => $this->check('disk', PreflightResult::WARNING, sprintf('Espacio libre bajo: %.1f GiB.', $free / 1073741824)),
            default => $this->check('disk', PreflightResult::SUCCESS, sprintf('Espacio libre: %.1f GiB.', $free / 1073741824)),
        };

        return $checks;
    }

    /** @return array{key: string, result: string, message: string} */
    private function check(string $key, PreflightResult $result, string $message): array
    {
        return ['key' => $key, 'result' => $result->value, 'message' => $message];
    }
}
