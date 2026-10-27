<?php

namespace App\Domain\Remote;

use App\Domain\Remote\DTOs\AgentResponse;
use App\Domain\Remote\DTOs\RemoteTarget;
use App\Domain\Remote\DTOs\TransportResult;
use App\Exceptions\RemoteAgentUnavailable;
use RuntimeException;

/**
 * Client for the versioned mwt-agent. Every call is a fixed script template
 * with validated, shell-escaped arguments; the response must be a single
 * mwt-agent.v1 JSON document.
 */
class RemoteAgent
{
    public const SCHEMA = 'mwt-agent.v1';

    private const SUBCOMMANDS = ['version', 'probe', 'launch', 'status', 'tail', 'cancel', 'tool-status', 'tool-commit', 'source-check', 'collector-report', 'upload-report', 'cleanup', 'source-list', 'destination-list', 'destination-check',
        'fetch-report', 'copias-list', 'copias-remove', 'consolidator-report', 'consolidator-intervention', 'site-upload-report', 'copias-release',
        'identity-candidates', 'identity-review-submit', 'theme-options', 'theme-policy-set', 'plugin-proposal',
        'category-map-proposal', 'category-map-submit', 'moodle-detect', 'source-profile-create', 'destination-profile-create', 'destination-repoint',
        's3-upload-probe'];

    private const ARGUMENT = '/^[A-Za-z0-9._:=-]{1,160}$/D';

    public function __construct(private readonly RemoteTransportResolver $transports) {}

    /** A free-text value (a path, a URL, an e-mail address) as base64url, inside the argument alphabet. */
    public static function text(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** @return array{path: string, version: string, sha256: string, size: int} */
    public function distribution(): array
    {
        $path = (string) config('toolkit.remote.agent_source');
        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException('La distribución del agente remoto no existe.');
        }
        $contents = (string) file_get_contents($path);
        if (preg_match('/^readonly AGENT_VERSION="([0-9]+\.[0-9]+\.[0-9]+)"$/m', $contents, $matches) !== 1) {
            throw new RuntimeException('La distribución del agente remoto no declara su versión.');
        }

        return ['path' => $path, 'version' => $matches[1], 'sha256' => hash('sha256', $contents), 'size' => strlen($contents)];
    }

    public function installedPath(): string
    {
        return rtrim((string) config('toolkit.remote.agent_install_root'), '/').'/'.$this->distribution()['version'].'/mwt-agent';
    }

    /** @param list<string> $arguments */
    public function call(RemoteTarget $target, string $subcommand, array $arguments = [], ?int $timeoutSeconds = null): AgentResponse
    {
        if (! in_array($subcommand, self::SUBCOMMANDS, true)) {
            throw new RuntimeException('El subcomando del agente no está registrado.');
        }
        foreach ($arguments as $argument) {
            if (preg_match(self::ARGUMENT, $argument) !== 1) {
                throw new RuntimeException('Un argumento del agente contiene caracteres no permitidos.');
            }
        }
        $agent = $this->installedPath();
        $missing = json_encode(['schema' => self::SCHEMA, 'ok' => false, 'agent_version' => null, 'command' => $subcommand,
            'error' => ['code' => 'agent_not_installed']], JSON_THROW_ON_ERROR);
        $script = implode("\n", [
            'set -eu',
            'export MWT_AGENT_STATE_DIR='.escapeshellarg((string) config('toolkit.remote.agent_state_dir')),
            'export MWT_AGENT_TOOLS_DIR='.escapeshellarg((string) config('toolkit.remote.agent_tools_dir')),
            'export MWT_AGENT_SOURCES_DIR='.escapeshellarg((string) config('toolkit.remote.agent_sources_dir')),
            'export MWT_AGENT_DESTINATIONS_DIR='.escapeshellarg((string) config('toolkit.remote.agent_destinations_dir')),
            'agent='.escapeshellarg($agent),
            'if [ ! -x "$agent" ]; then printf \'%s\n\' '.escapeshellarg($missing).'; exit 127; fi',
            'exec bash "$agent" '.implode(' ', array_map('escapeshellarg', [$subcommand, ...$arguments])),
            '',
        ]);

        return $this->parse($subcommand, $this->transports->for($target)->run(
            $target, $script, $timeoutSeconds ?? (int) config('toolkit.remote.call_timeout_seconds', 60),
        ));
    }

    public function parse(string $subcommand, TransportResult $result): AgentResponse
    {
        if (! $result->completed()) {
            throw new RemoteAgentUnavailable($result->errorCode ?? 'transport_unavailable', match ($result->status) {
                TransportResult::TIMED_OUT => 'La llamada al servidor excedió su plazo; el estado remoto es desconocido.',
                default => 'No fue posible comunicarse con el servidor remoto.',
            });
        }
        $maximum = (int) config('toolkit.remote.max_output_bytes', 65536);
        if (strlen($result->stdout) > $maximum) {
            throw new RemoteAgentUnavailable('agent_output_too_large', 'El agente devolvió más datos de los permitidos.');
        }
        $lines = array_values(array_filter(explode("\n", $result->stdout), fn (string $line): bool => trim($line) !== ''));
        if (count($lines) !== 1) {
            throw new RemoteAgentUnavailable('agent_output_invalid', 'La respuesta del agente no es un único documento.');
        }
        $document = json_decode($lines[0], true);
        if (! is_array($document) || ($document['schema'] ?? null) !== self::SCHEMA || ! is_bool($document['ok'] ?? null)
            || ($document['command'] ?? null) !== $subcommand
        ) {
            throw new RemoteAgentUnavailable('agent_output_invalid', 'La respuesta del agente no cumple el contrato mwt-agent.v1.');
        }
        $version = $document['agent_version'] ?? null;
        if ($document['ok'] === false) {
            $code = $document['error']['code'] ?? null;
            if (! is_string($code) || preg_match('/^[a-z_]{1,64}$/D', $code) !== 1) {
                throw new RemoteAgentUnavailable('agent_output_invalid', 'El agente devolvió un código de error no válido.');
            }

            return new AgentResponse(false, $subcommand, is_string($version) ? $version : null, [], $code, $result);
        }
        if (! is_string($version) || ! is_array($document['data'] ?? null)) {
            throw new RemoteAgentUnavailable('agent_output_invalid', 'La respuesta del agente no incluye datos válidos.');
        }

        return new AgentResponse(true, $subcommand, $version, $document['data'], null, $result);
    }
}
