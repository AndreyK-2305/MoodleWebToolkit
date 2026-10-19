<?php

namespace App\Domain\Remote;

use App\Domain\Remote\DTOs\AgentResponse;
use App\Domain\Remote\DTOs\RemoteTarget;
use App\Exceptions\RemoteAgentUnavailable;

/**
 * Copies the repository's agent to the server, verifies its SHA-256 there
 * and only then moves it into the versioned install directory.
 */
class RemoteAgentInstaller
{
    /** SSM and SSH both accept this size comfortably in a single call. */
    private const MAX_SCRIPT_BYTES = 49152;

    public function __construct(
        private readonly RemoteAgent $agent,
        private readonly RemoteTransportResolver $transports,
    ) {}

    public function install(RemoteTarget $target): AgentResponse
    {
        $distribution = $this->agent->distribution();
        $path = $this->agent->installedPath();
        $directory = dirname($path);
        $failure = fn (string $code): string => escapeshellarg((string) json_encode(['schema' => RemoteAgent::SCHEMA, 'ok' => false,
            'agent_version' => null, 'command' => 'version', 'error' => ['code' => $code]]));

        $script = implode("\n", [
            'set -euo pipefail',
            'umask 022',
            // Messages live in variables: quoted JSON nested inside the trap would break its quoting.
            'install_failed='.$failure('agent_install_failed'),
            'hash_mismatch='.$failure('agent_install_hash_mismatch'),
            'trap \'printf "%s\n" "$install_failed"; exit 2\' ERR',
            'dir='.escapeshellarg($directory),
            'state='.escapeshellarg((string) config('toolkit.remote.agent_state_dir')),
            'expected='.escapeshellarg($distribution['sha256']),
            'mkdir -p "$dir"',
            'tmp=$(mktemp "$dir/.mwt-agent.XXXXXX")',
            // The base64 alphabet has no quote characters, so single quotes are sufficient.
            "printf %s '".base64_encode((string) file_get_contents($distribution['path']))."' | base64 -d > \"\$tmp\"",
            'actual=$(sha256sum "$tmp" | cut -d" " -f1)',
            'if [ "$actual" != "$expected" ]; then rm -f "$tmp"; trap - ERR; printf "%s\n" "$hash_mismatch"; exit 2; fi',
            'chmod 0755 "$tmp"',
            'mv -f "$tmp" "$dir/mwt-agent"',
            '( umask 077; mkdir -p "$state" )',
            'chmod 0700 "$state"',
            'trap - ERR',
            'export MWT_AGENT_STATE_DIR="$state"',
            'exec bash "$dir/mwt-agent" version',
            '',
        ]);
        if (strlen($script) > self::MAX_SCRIPT_BYTES) {
            throw new RemoteAgentUnavailable('agent_too_large', 'La distribución del agente excede el tamaño admitido por una llamada.');
        }

        $response = $this->agent->parse('version', $this->transports->for($target)->run(
            $target, $script, (int) config('toolkit.remote.call_timeout_seconds', 60),
        ));
        if ($response->ok && (($response->data['agent_sha256'] ?? null) !== $distribution['sha256'] || $response->agentVersion !== $distribution['version'])) {
            throw new RemoteAgentUnavailable('agent_install_hash_mismatch', 'El agente instalado no coincide con la distribución verificada.');
        }

        return $response;
    }
}
