<?php

namespace App\Domain\Remote;

use App\Domain\Remote\DTOs\AgentResponse;
use App\Enums\ServerRole;
use App\Exceptions\RemoteAgentUnavailable;
use App\Exceptions\ToolOperationBlocked;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Prepares source and destination servers from the web. With SSM the agent
 * already runs as root on the instance and IAM is the real boundary, so the
 * profiles a server administrator used to write by hand are written here by
 * the agent, once a platform administrator confirms them with their password.
 * Paths travel to the server but are never audited in clear: the audit keeps
 * their SHA-256.
 *
 * The manual helpers (mwt-source-profile, mwt-destination-profile) remain the
 * alternative and write the same files.
 */
class RemoteServerPreparation
{
    public const PATH = '#^/[A-Za-z0-9._/-]{1,110}$#D';

    private const PROFILE = '/^[a-z0-9][a-z0-9-]{1,62}$/D';

    private const USER = '/^[a-z_][a-z0-9_-]{0,31}$/D';

    private const SERVICE = '/^[a-z0-9][a-z0-9_-]{0,62}$/D';

    private const CODE = '/^[a-z_]{1,64}$/D';

    public function __construct(
        private readonly RemoteServerConnections $connections,
        private readonly RemoteAgent $agent,
        private readonly CheckRemoteSource $sources,
        private readonly CheckRemoteDestination $destinations,
        private readonly RemotePackageObjects $objects,
    ) {}

    /**
     * Candidate Moodle installations on a source server. Their paths are kept for the
     * administrator to choose from; the agent never reads config.php to find them.
     *
     * @return array{detected: bool, error_code: string|null, candidates: list<array<string, mixed>>}
     */
    public function detectMoodle(Server $server, ?User $actor): array
    {
        $this->assertRole($server, ServerRole::SOURCE);
        $errorCode = null;
        $candidates = [];
        $facts = ['php_present' => null, 'docker_checked' => null];
        try {
            $response = $this->agent->call($this->connections->target($server), 'moodle-detect', [], 120);
            if ($response->ok) {
                foreach (is_array($response->data['candidates'] ?? null) ? $response->data['candidates'] : [] as $candidate) {
                    if (is_array($candidate) && ($valid = $this->candidate($candidate)) !== null) {
                        $candidates[] = $valid;
                    }
                }
                $facts = ['php_present' => ($response->data['php_present'] ?? false) === true, 'docker_checked' => ($response->data['docker_checked'] ?? false) === true];
            } else {
                $errorCode = $response->errorCode;
            }
        } catch (RemoteAgentUnavailable $exception) {
            $errorCode = $exception->errorCode;
        }
        $this->store($server, $actor, 'remote_moodle_detected', ['detected_at' => now()->utc()->toIso8601String(), 'error_code' => $errorCode,
            ...$facts, 'candidates' => array_slice($candidates, 0, 20)], 'REMOTE_MOODLE_DETECTED', ['error_code' => $errorCode,
                'candidates' => array_map(fn (array $candidate): array => ['config_sha256' => hash('sha256', $candidate['config_path']),
                    'runtime' => $candidate['runtime'], 'issue' => $candidate['issue']], $candidates)]);

        return ['detected' => $errorCode === null, 'error_code' => $errorCode, 'candidates' => $candidates];
    }

    /**
     * Writes a source profile on the server (root:root 0600) and records the check the
     * agent runs right after: Moodle, PHP and the CPUs the workers are chosen from.
     *
     * @param  array{source: string, config_path: string, runtime: string, run_as: string, compose_dir?: string|null,
     *   compose_service?: string|null, compose_profile?: string|null, force?: bool}  $input
     * @return array{created: bool, replaced: bool, error_code: string|null, check: array<string, mixed>|null}
     */
    public function createSource(Server $server, User $actor, array $input): array
    {
        $this->assertRole($server, ServerRole::SOURCE);
        $this->assertAdmin($actor);
        $compose = $input['runtime'] === 'compose';
        $this->assertPath('config_path', $input['config_path'], true);
        if ($compose) {
            $this->assertPath('compose_dir', (string) ($input['compose_dir'] ?? ''), false);
        }
        $arguments = [$input['source'], 'config='.RemoteAgent::text($input['config_path']), 'runtime='.$input['runtime'],
            'run_as='.$input['run_as'], 'force='.(($input['force'] ?? false) ? '1' : '0')];
        if ($compose) {
            $arguments[] = 'compose_dir='.RemoteAgent::text((string) $input['compose_dir']);
            $arguments[] = 'compose_service='.($input['compose_service'] ?? '');
            if (is_string($input['compose_profile'] ?? null) && $input['compose_profile'] !== '') {
                $arguments[] = 'compose_profile='.$input['compose_profile'];
            }
        }
        $response = $this->callOrFail($server, 'source-profile-create', $arguments, 180);
        $replaced = ($response->data['replaced'] ?? false) === true;
        $this->audit($server, $actor, 'REMOTE_SOURCE_PROFILE_CREATED', ['source' => $input['source'], 'created' => $response->ok,
            'error_code' => $response->errorCode, 'replaced' => $replaced, 'runtime' => $input['runtime'], 'run_as' => $input['run_as'],
            'config_sha256' => hash('sha256', $input['config_path']),
            'compose_dir_sha256' => $compose ? hash('sha256', (string) $input['compose_dir']) : null]);
        if (! $response->ok) {
            return ['created' => false, 'replaced' => false, 'error_code' => $response->errorCode, 'check' => null];
        }
        [$data, $checkError] = $this->nested($response->data['check'] ?? null, 'source-check');
        $check = $this->sources->record($server, $input['source'], $actor, $data, $checkError);

        return ['created' => true, 'replaced' => $replaced, 'error_code' => null, 'check' => $check];
    }

    /**
     * Writes a destination profile and, when none exists, its runtime: a private copy
     * of the deployed and verified Consolidator tree with a new .env. The generated
     * Moodle administrator password stays in that .env on the server; the agent only
     * says where it is.
     *
     * @param  array{destination: string, public_url: string, admin_email: string, admin_user: string, http_port: int, run_as?: string|null, force?: bool}  $input
     * @return array{created: bool, error_code: string|null, check: array<string, mixed>|null}
     */
    public function createDestination(Server $server, User $actor, array $input): array
    {
        $this->assertRole($server, ServerRole::DESTINATION);
        $this->assertAdmin($actor);
        $url = rtrim($input['public_url'], '/');
        if (preg_match('#^https://[A-Za-z0-9._:-]+(/[A-Za-z0-9._~/-]*)?$#D', $url) !== 1 || strlen($url) > 110) {
            throw ValidationException::withMessages(['public_url' => 'La URL pública debe ser la dirección HTTPS definitiva del sitio, sin parámetros (hasta 110 caracteres).']);
        }
        $tree = $server->metadata['remote_tools'][CheckRemoteDestination::DISTRIBUTION_KEY]['tree_sha256'] ?? null;
        if (! is_string($tree)) {
            throw new ToolOperationBlocked('Despliegue primero el Consolidador en el destino: el runtime se copia de su árbol verificado.');
        }
        $runtime = rtrim((string) config('toolkit.remote.agent_runtimes_dir', '/srv/mwt-destinations'), '/').'/'.$input['destination'];
        $this->assertPath('runtime_dir', $runtime, false);
        $response = $this->callOrFail($server, 'destination-profile-create', [$input['destination'], 'public_url='.RemoteAgent::text($url),
            'admin_email='.RemoteAgent::text($input['admin_email']), 'admin_user='.$input['admin_user'], 'http_port='.$input['http_port'],
            'runtime_dir='.RemoteAgent::text($runtime), 'tree='.$tree, 'force='.(($input['force'] ?? false) ? '1' : '0'),
            // By default the agent creates the unprivileged mwt-<id> user the Consolidator runs as.
            ...(is_string($input['run_as'] ?? null) && $input['run_as'] !== '' ? ['run_as='.$input['run_as']] : [])], 600);
        $data = $response->data;
        $created = ['created_at' => now()->utc()->toIso8601String(), 'actor_id' => $actor->getKey(),
            'runtime_created' => ($data['runtime_created'] ?? false) === true, 'user_created' => ($data['user_created'] ?? false) === true,
            'replaced' => ($data['replaced'] ?? false) === true, 'docker_group' => ($data['docker_group'] ?? false) === true,
            'run_as' => is_string($data['run_as'] ?? null) && preg_match(self::USER, $data['run_as']) === 1 ? $data['run_as'] : null,
            'admin_user' => is_string($data['admin_user'] ?? null) && preg_match('/^[a-z][a-z0-9._-]{2,31}$/D', $data['admin_user']) === 1 ? $data['admin_user'] : null,
            // Where the root-only password is, never the password itself.
            'admin_password_location' => is_string($data['admin_password_location'] ?? null)
                && preg_match('#^/[A-Za-z0-9._/-]{1,240}/\.env \(MOODLE_ADMIN_PASSWORD\)$#D', $data['admin_password_location']) === 1
                ? $data['admin_password_location'] : null];
        $this->audit($server, $actor, 'REMOTE_DESTINATION_PROFILE_CREATED', ['destination' => $input['destination'], 'created' => $response->ok,
            'error_code' => $response->errorCode, 'public_url' => $url, 'admin_email_sha256' => hash('sha256', strtolower($input['admin_email'])),
            'admin_user' => $input['admin_user'], 'http_port' => $input['http_port'], 'tool_tree' => $tree,
            ...($response->ok ? array_intersect_key($created, array_flip(['runtime_created', 'user_created', 'replaced', 'docker_group', 'run_as'])) : [])]);
        if (! $response->ok) {
            return ['created' => false, 'error_code' => $response->errorCode, 'check' => null];
        }
        $this->store($server, null, 'remote_destination_created', [...($server->refresh()->metadata['remote_destination_created'] ?? []),
            $input['destination'] => $created], null, []);
        [$check, $checkError] = $this->nested($data['check'] ?? null, 'destination-check');

        return ['created' => true, 'error_code' => null, 'check' => $this->destinations->record($server->refresh(), $input['destination'], $actor, $check, $checkError)];
    }

    /**
     * The server's instance role writes a tiny object to the platform's bucket (and, on a
     * destination, reads it back); the platform then finds it with its own identity.
     *
     * @return array{ok: bool, error_code: string|null}
     */
    public function probeStorage(Server $server, ?User $actor): array
    {
        $this->connections->assertEnabled();
        $bucket = config('toolkit.remote.package_bucket');
        $region = config('toolkit.remote.package_region');
        if (! is_string($bucket) || $bucket === '' || ! is_string($region) || $region === '') {
            throw new ToolOperationBlocked('Configure el bucket y la región de paquetes de la plataforma antes de probar S3.');
        }
        $mode = $server->role === ServerRole::DESTINATION ? 'readwrite' : 'write';
        $connection = $this->connections->primary($server);
        $instance = $connection?->configuration['instance_id'] ?? null;
        $label = is_string($instance) && preg_match('/^i-[0-9a-f]{8,17}$/D', $instance) === 1 ? $instance : $server->uuid;
        $result = ['checked_at' => now()->utc()->toIso8601String(), 'mode' => $mode, 'bucket' => $bucket, 'region' => $region,
            'ok' => false, 'error_code' => null, 'key' => null, 'written' => false, 'read_back' => null, 'confirmed' => false, 'deleted' => false];
        try {
            $response = $this->agent->call($this->connections->target($server), 's3-upload-probe', [$bucket, $region, $mode, $label], 120);
            if (! $response->ok) {
                $result['error_code'] = $response->errorCode;
            } else {
                $key = (string) ($response->data['key'] ?? '');
                $result['key'] = $key;
                $result['written'] = true;
                $result['read_back'] = $mode === 'readwrite' ? ($response->data['read_back'] ?? false) === true : null;
                $confirmation = $this->objects->confirmProbe($region, $bucket, $key, (string) ($response->data['sha256'] ?? ''), (int) ($response->data['size'] ?? -1));
                $result['confirmed'] = $confirmation['confirmed'];
                $result['deleted'] = $confirmation['deleted'];
                $result['error_code'] = $confirmation['error_code'];
                $result['ok'] = $confirmation['confirmed'] && ($mode === 'write' || $result['read_back'] === true);
            }
        } catch (RemoteAgentUnavailable $exception) {
            $result['error_code'] = $exception->errorCode;
        }
        $this->store($server, $actor, 'remote_storage_probe', $result, 'REMOTE_STORAGE_PROBED',
            array_intersect_key($result, array_flip(['mode', 'bucket', 'region', 'ok', 'error_code', 'key', 'read_back', 'confirmed', 'deleted'])));

        return ['ok' => $result['ok'], 'error_code' => $result['error_code']];
    }

    /**
     * After a Consolidator deployment, points the destination's profiles at the new tree
     * while their site has not been written, and checks again those it moved. Replaces
     * the manual `mwt-destination-profile --force --tree`.
     *
     * @return array{error_code: string|null, destinations: list<array{id: string, repointed: bool, reason: string}>}
     */
    public function repoint(Server $server, string $tree, ?User $actor): array
    {
        $errorCode = null;
        $destinations = [];
        try {
            $response = $this->agent->call($this->connections->target($server), 'destination-repoint', [$tree], 120);
            if ($response->ok) {
                foreach (is_array($response->data['destinations'] ?? null) ? $response->data['destinations'] : [] as $destination) {
                    if (is_array($destination) && is_string($destination['id'] ?? null) && preg_match(self::PROFILE, $destination['id']) === 1
                        && is_string($destination['reason'] ?? null) && preg_match(self::CODE, $destination['reason']) === 1) {
                        $destinations[] = ['id' => $destination['id'], 'repointed' => ($destination['repointed'] ?? false) === true, 'reason' => $destination['reason']];
                    }
                }
            } else {
                $errorCode = $response->errorCode;
            }
        } catch (RemoteAgentUnavailable $exception) {
            $errorCode = $exception->errorCode;
        } catch (ToolOperationBlocked) {
            // Never undoes the deployment that just succeeded: the panel says the profiles were not moved.
            $errorCode = 'repoint_blocked';
        }
        $this->store($server, $actor, 'remote_destination_repoint', ['at' => now()->utc()->toIso8601String(), 'tree_sha256' => $tree,
            'error_code' => $errorCode, 'destinations' => $destinations], 'REMOTE_DESTINATIONS_REPOINTED',
            ['tree_sha256' => $tree, 'error_code' => $errorCode, 'destinations' => $destinations]);
        foreach ($destinations as $destination) {
            if ($destination['repointed'] || $destination['reason'] === 'already_current') {
                $this->destinations->handle($server->refresh(), $destination['id'], $actor);
            }
        }

        return ['error_code' => $errorCode, 'destinations' => $destinations];
    }

    /**
     * @param  array<mixed>  $candidate
     * @return array<string, mixed>|null
     */
    private function candidate(array $candidate): ?array
    {
        $path = $candidate['config_path'] ?? null;
        $runtime = $candidate['runtime'] ?? null;
        if (! is_string($path) || preg_match('#^/[A-Za-z0-9._/-]{1,255}$#D', $path) !== 1 || str_contains($path, '..')
            || ! in_array($runtime, ['native', 'compose'], true)) {
            return null;
        }
        $directory = $candidate['compose_dir'] ?? null;
        $string = fn (string $key, string $pattern): ?string => is_string($candidate[$key] ?? null) && preg_match($pattern, $candidate[$key]) === 1 ? $candidate[$key] : null;

        return ['config_path' => $path, 'runtime' => $runtime,
            'compose_dir' => is_string($directory) && preg_match('#^/[A-Za-z0-9._/-]{1,255}$#D', $directory) === 1 && ! str_contains($directory, '..') ? $directory : null,
            'compose_service' => $string('compose_service', self::SERVICE), 'compose_profile' => $string('compose_profile', self::SERVICE),
            'run_as' => $string('run_as', self::USER), 'config_owner' => $string('config_owner', self::USER), 'issue' => $string('issue', self::CODE),
            // The platform can only send what fits the agent's argument alphabet.
            'too_long' => strlen($path) > 110 || (is_string($directory) && strlen($directory) > 110)];
    }

    /**
     * The result of a check the agent ran right after writing a profile.
     *
     * @return array{array<string, mixed>|null, string|null}
     */
    private function nested(mixed $document, string $command): array
    {
        if (! is_array($document) || ($document['schema'] ?? null) !== RemoteAgent::SCHEMA || ($document['command'] ?? null) !== $command) {
            return [null, 'check_unavailable'];
        }
        if (($document['ok'] ?? false) === true && is_array($document['data'] ?? null)) {
            return [$document['data'], null];
        }
        $code = $document['error']['code'] ?? null;

        return [null, is_string($code) && preg_match(self::CODE, $code) === 1 ? $code : 'check_unavailable'];
    }

    /** @param list<string> $arguments */
    private function callOrFail(Server $server, string $subcommand, array $arguments, int $timeout): AgentResponse
    {
        try {
            return $this->agent->call($this->connections->target($server), $subcommand, $arguments, $timeout);
        } catch (RemoteAgentUnavailable $exception) {
            throw new ToolOperationBlocked($exception->getMessage().' ('.$exception->errorCode.')');
        }
    }

    private function assertRole(Server $server, ServerRole $role): void
    {
        $this->connections->assertEnabled();
        if ($server->role !== $role) {
            throw new ToolOperationBlocked($role === ServerRole::SOURCE ? 'Solo un servidor de origen tiene perfiles de origen.' : 'Solo un servidor de destino aloja el Consolidador.');
        }
    }

    private function assertAdmin(User $actor): void
    {
        if (! $actor->isAdmin()) {
            throw new ToolOperationBlocked('Solo un administrador puede crear perfiles en un servidor.');
        }
    }

    private function assertPath(string $field, string $path, bool $config): void
    {
        if (preg_match(self::PATH, $path) !== 1 || str_contains($path, '..') || ($config && ! str_ends_with($path, '/config.php'))) {
            throw ValidationException::withMessages([$field => $config
                ? 'Indique la ruta absoluta de config.php, sin «..» ni caracteres especiales (hasta 110 caracteres).'
                : 'Indique la ruta absoluta del directorio de Compose, sin «..» ni caracteres especiales (hasta 110 caracteres).']);
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $payload
     */
    private function store(Server $server, ?User $actor, string $key, array $value, ?string $action, array $payload): void
    {
        DB::transaction(function () use ($server, $actor, $key, $value, $action, $payload): void {
            $locked = Server::query()->whereKey($server->getKey())->lockForUpdate()->firstOrFail();
            $metadata = $locked->metadata ?? [];
            $metadata[$key] = $value;
            $locked->update(['metadata' => $metadata]);
            if ($action !== null) {
                $this->audit($locked, $actor, $action, $payload);
            }
        });
    }

    /** @param array<string, mixed> $payload */
    private function audit(Server $server, ?User $actor, string $action, array $payload): void
    {
        AuditLog::query()->create([
            'actor_id' => $actor?->getKey(), 'project_id' => $server->project_id, 'action' => $action,
            'auditable_type' => $server->getMorphClass(), 'auditable_id' => $server->getKey(), 'payload' => $payload,
        ]);
    }
}
