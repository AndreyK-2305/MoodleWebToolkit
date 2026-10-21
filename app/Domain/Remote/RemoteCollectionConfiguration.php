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
 * COLLECT configuration that runs Collector 7.4.2 on one or more source
 * servers through their agents. It stores identifiers only: for each source
 * the server, the root-owned profile on that server and the package name,
 * plus the workers each export uses.
 *
 * collector-remote.v2 holds a list of sources; revisions saved as v1 (a
 * single source) are still read and behave as a list of one.
 *
 * @phpstan-type RemoteSource array{server_uuid: string, source_profile: string, package_name: string}
 * @phpstan-type RemoteCollectionSettings array{
 *   schema_version: string, mode: string, sources: list<RemoteSource>, workers: int,
 *   distribution_key: string, producer_version: string
 * }
 */
final class RemoteCollectionConfiguration
{
    public const SCHEMA = 'collector-remote.v2';

    public const SCHEMA_V1 = 'collector-remote.v1';

    public const MODE = 'REMOTE';

    public const MAX_SOURCES = 8;

    public const INPUT = ['sources', 'workers'];

    public const INPUT_V1 = ['server_uuid', 'source_profile', 'workers', 'package_name'];

    public function __construct(private readonly CollectorConfiguration $revisions) {}

    public function selected(?ProjectConfiguration $configuration): bool
    {
        return ($configuration?->settings['options']['mode'] ?? null) === self::MODE;
    }

    /** @param array<string, mixed> $input */
    public function save(Project $project, User $actor, array $input): Project
    {
        // A single source in the v1 shape is accepted and stored as a list of one.
        if (array_key_exists('server_uuid', $input) && ! array_key_exists('sources', $input)) {
            $input = ['sources' => [array_intersect_key($input, array_flip(['server_uuid', 'source_profile', 'package_name']))],
                'workers' => $input['workers'] ?? null, ...array_diff_key($input, array_flip(self::INPUT_V1))];
        }

        return $this->revisions->persist($project, $actor, $this->normalize($project, $input));
    }

    /** @return RemoteCollectionSettings */
    public function settings(ProjectConfiguration $configuration): array
    {
        $options = $configuration->settings['options'] ?? null;
        if (! is_array($options) || ($options['mode'] ?? null) !== self::MODE
            || ! in_array($options['schema_version'] ?? null, [self::SCHEMA, self::SCHEMA_V1], true)) {
            throw new RuntimeException('La configuración de recolección remota está incompleta.');
        }
        $project = Project::query()->findOrFail($configuration->project_id);
        try {
            $normalized = $options['schema_version'] === self::SCHEMA_V1
                ? $this->normalizeV1($project, array_intersect_key($options, array_flip(self::INPUT_V1)))
                : $this->normalize($project, array_intersect_key($options, array_flip(self::INPUT)));
        } catch (ValidationException) {
            throw new RuntimeException('Un servidor o un parámetro de la recolección remota dejó de ser válido.');
        }
        if (! hash_equals($this->revisions->hash($normalized), $this->revisions->hash($options))) {
            throw new RuntimeException('El esquema de la recolección remota cambió; se requiere una nueva revisión.');
        }
        $this->revisions->assertRevision($configuration, $options);

        return self::sources($normalized) === [] ? throw new RuntimeException('La recolección remota no tiene orígenes.') : [
            'schema_version' => self::SCHEMA, 'mode' => self::MODE, 'sources' => self::sources($normalized),
            'workers' => (int) $normalized['workers'], 'distribution_key' => RemoteCollectorExport::DISTRIBUTION_KEY, 'producer_version' => '7.4.2-linux',
        ];
    }

    /**
     * The sources of normalized options or of a binding snapshot, in either schema.
     *
     * @param  array<string, mixed>  $settings
     * @return list<RemoteSource>
     */
    public static function sources(array $settings): array
    {
        $sources = is_array($settings['sources'] ?? null) ? $settings['sources']
            : (is_string($settings['server_uuid'] ?? null) ? [$settings] : []);
        $list = [];
        foreach ($sources as $source) {
            if (is_array($source) && is_string($source['server_uuid'] ?? null) && is_string($source['source_profile'] ?? null)
                && is_string($source['package_name'] ?? null)) {
                $list[] = ['server_uuid' => $source['server_uuid'], 'source_profile' => $source['source_profile'], 'package_name' => $source['package_name']];
            }
        }

        return $list;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalize(Project $project, array $input): array
    {
        $rows = $input['sources'] ?? null;
        if (array_diff(array_keys($input), self::INPUT) !== [] || ! is_array($rows) || ! array_is_list($rows)
            || count($rows) < 1 || count($rows) > self::MAX_SOURCES
            || ! is_int($input['workers'] ?? null) || $input['workers'] < 1 || $input['workers'] > 4) {
            throw ValidationException::withMessages(['collector' => 'La recolección remota requiere de 1 a '.self::MAX_SOURCES.' orígenes y de 1 a 4 workers.']);
        }
        $sources = [];
        foreach ($rows as $index => $row) {
            $sources[] = $this->source($project, is_array($row) ? $row : [], $index);
        }
        $pairs = array_map(fn (array $source): string => $source['server_uuid'].'|'.$source['source_profile'], $sources);
        $packages = array_column($sources, 'package_name');
        if (count(array_unique($pairs)) !== count($pairs) || count(array_unique($packages)) !== count($packages)) {
            throw ValidationException::withMessages(['collector' => 'Cada origen debe ser un perfil distinto y tener su propio nombre de paquete.']);
        }

        return [
            'schema_version' => self::SCHEMA, 'mode' => self::MODE, 'sources' => $sources, 'workers' => $input['workers'],
            'distribution_key' => RemoteCollectorExport::DISTRIBUTION_KEY, 'producer_version' => '7.4.2-linux',
        ];
    }

    /**
     * Revisions saved before multiple sources existed.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalizeV1(Project $project, array $input): array
    {
        if (array_diff(array_keys($input), self::INPUT_V1) !== [] || ! is_int($input['workers'] ?? null) || $input['workers'] < 1 || $input['workers'] > 4) {
            throw ValidationException::withMessages(['collector' => 'Los parámetros remotos son inválidos.']);
        }

        return [
            'schema_version' => self::SCHEMA_V1, 'mode' => self::MODE, ...$this->source($project, $input, 0), 'workers' => $input['workers'],
            'distribution_key' => RemoteCollectorExport::DISTRIBUTION_KEY, 'producer_version' => '7.4.2-linux',
        ];
    }

    /**
     * @param  array<mixed>  $row
     * @return RemoteSource
     */
    private function source(Project $project, array $row, int $index): array
    {
        $server = is_string($row['server_uuid'] ?? null)
            ? $project->servers()->where('uuid', $row['server_uuid'])->first() : null;
        if (array_diff(array_keys($row), ['server_uuid', 'source_profile', 'package_name']) !== [] || ! $server instanceof Server
            || $server->role !== ServerRole::SOURCE
            || ! is_string($row['source_profile'] ?? null) || preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/D', $row['source_profile']) !== 1
            || ! is_string($row['package_name'] ?? null) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $row['package_name']) !== 1) {
            throw ValidationException::withMessages(['collector' => 'El origen '.($index + 1).' es inválido: se requiere un servidor de origen del proyecto, un perfil y un nombre de paquete.']);
        }

        return ['server_uuid' => $server->uuid, 'source_profile' => $row['source_profile'], 'package_name' => $row['package_name']];
    }
}
