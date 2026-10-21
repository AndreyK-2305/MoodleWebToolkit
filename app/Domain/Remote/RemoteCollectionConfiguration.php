<?php

namespace App\Domain\Remote;

use App\Domain\Collector\CollectorConfiguration;
use App\Enums\ServerRole;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\Server;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * COLLECT configuration that runs Collector 7.4.2 on a source server through
 * its agent. It stores identifiers only: the server, the root-owned source
 * profile on that server, workers and the package name.
 *
 * @phpstan-type RemoteCollectionSettings array{
 *   schema_version: string, mode: string, server_uuid: string, source_profile: string,
 *   workers: int, package_name: string, distribution_key: string, producer_version: string
 * }
 */
final class RemoteCollectionConfiguration
{
    public const SCHEMA = 'collector-remote.v1';

    public const MODE = 'REMOTE';

    public const INPUT = ['server_uuid', 'source_profile', 'workers', 'package_name'];

    public function __construct(private readonly CollectorConfiguration $revisions) {}

    public function selected(?ProjectConfiguration $configuration): bool
    {
        return ($configuration?->settings['options']['mode'] ?? null) === self::MODE;
    }

    /** @param array<string, mixed> $input */
    public function save(Project $project, User $actor, array $input): Project
    {
        return $this->revisions->persist($project, $actor, $this->normalize($project, $input));
    }

    /** @return RemoteCollectionSettings */
    public function settings(ProjectConfiguration $configuration): array
    {
        $options = $configuration->settings['options'] ?? null;
        if (! is_array($options) || ($options['schema_version'] ?? null) !== self::SCHEMA || ($options['mode'] ?? null) !== self::MODE) {
            throw new RuntimeException('La configuración de recolección remota está incompleta.');
        }
        $project = Project::query()->findOrFail($configuration->project_id);
        try {
            $normalized = $this->normalize($project, array_intersect_key($options, array_flip(self::INPUT)));
        } catch (ValidationException) {
            throw new RuntimeException('El servidor o los parámetros de la recolección remota dejaron de ser válidos.');
        }
        if (! hash_equals($this->revisions->hash($normalized), $this->revisions->hash($options))) {
            throw new RuntimeException('El esquema de la recolección remota cambió; se requiere una nueva revisión.');
        }
        $this->revisions->assertRevision($configuration, $options);

        return $normalized;
    }

    public function server(ProjectConfiguration $configuration): Server
    {
        return Server::query()->where('project_id', $configuration->project_id)
            ->where('uuid', $this->settings($configuration)['server_uuid'])->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return RemoteCollectionSettings
     */
    private function normalize(Project $project, array $input): array
    {
        $server = is_string($input['server_uuid'] ?? null)
            ? $project->servers()->where('uuid', $input['server_uuid'])->first() : null;
        if (array_diff(array_keys($input), self::INPUT) !== [] || $server === null || $server->role !== ServerRole::SOURCE
            || ! is_string($input['source_profile'] ?? null) || preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/D', $input['source_profile']) !== 1
            || ! is_int($input['workers'] ?? null) || $input['workers'] < 1 || $input['workers'] > 4
            || ! is_string($input['package_name'] ?? null) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $input['package_name']) !== 1
        ) {
            throw ValidationException::withMessages(['collector' => 'Los parámetros remotos son inválidos; se requiere un servidor de origen del proyecto, un perfil, workers y un nombre de paquete.']);
        }

        return [
            'schema_version' => self::SCHEMA, 'mode' => self::MODE, 'server_uuid' => $server->uuid,
            'source_profile' => $input['source_profile'], 'workers' => $input['workers'], 'package_name' => $input['package_name'],
            'distribution_key' => RemoteCollectorExport::DISTRIBUTION_KEY, 'producer_version' => '7.4.2-linux',
        ];
    }
}
