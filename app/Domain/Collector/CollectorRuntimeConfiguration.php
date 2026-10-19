<?php

namespace App\Domain\Collector;

use App\Domain\Tools\ToolDistributionVerifier;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Models\Execution;
use App\Models\ExecutionRuntimeConfiguration;
use App\Models\ProjectConfiguration;
use App\Models\ToolDistribution;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class CollectorRuntimeConfiguration
{
    public const SCHEMA = 'collector-runtime.v1';

    public const RELATIVE_PATH = 'collector-runtime.json';

    public function __construct(
        private readonly CollectorConfiguration $configurations,
        private readonly LabMoodleProfiles $profiles,
        private readonly ExecutionWorkspaceManager $workspaces,
        private readonly ToolDistributionVerifier $verifier,
    ) {}

    public function approve(Execution $execution, ProjectConfiguration $configuration, ToolDistribution $distribution, User $actor): ExecutionRuntimeConfiguration
    {
        if ((int) $execution->project_id !== (int) $configuration->project_id
            || $distribution->key !== 'moodle-recolector-7.4.2-linux-tree' || $distribution->toolVersion->version !== '7.4.2-linux') {
            throw new RuntimeException('La configuración runtime no corresponde a la ejecución o distribución COLLECT LAB.');
        }
        $settings = $this->configurations->settings($configuration);
        $profile = $this->profiles->get($settings['profile_id']);
        $verified = $this->verifier->verify($distribution);
        $document = [
            'schema_version' => self::SCHEMA, 'mode' => 'LABORATORY',
            'project_uuid' => $execution->project->uuid, 'execution_uuid' => $execution->uuid,
            'configuration_version' => $configuration->version, 'configuration_sha256' => $this->configurations->hash($settings),
            'settings' => $settings, 'profile' => $profile,
            'reference_scope_sha256' => hash('sha256', (string) config('collector.secret_root')),
            'distribution_key' => $distribution->key, 'distribution_sha256' => $verified->treeSha256,
            'tool_directory' => Str::slug($distribution->key), 'manifest_sha256' => $verified->manifestSha256,
            'manifest_files' => $verified->manifestFiles,
            'collector_version' => '7.4.2-linux', 'scope' => 'lab', 'notify_every' => 0,
            'reuse_backups' => false, 'restart' => false,
        ];
        $content = json_encode($this->canonicalize($document), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $hash = hash('sha256', $content);

        return DB::transaction(function () use ($execution, $distribution, $actor, $content, $hash): ExecutionRuntimeConfiguration {
            Execution::query()->whereKey($execution->getKey())->lockForUpdate()->firstOrFail();
            $existing = ExecutionRuntimeConfiguration::query()->where('execution_id', $execution->getKey())->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->schema_version !== self::SCHEMA || $existing->approval_state !== 'APPROVED'
                    || ! hash_equals($existing->content_sha256, $hash) || (int) $existing->approved_by !== (int) $actor->getKey()) {
                    throw new RuntimeException('La configuración runtime aprobada no puede sustituirse.');
                }
                $this->verify($execution, $existing);

                return $existing;
            }
            $path = $this->workspaces->resolve($execution, 'state', self::RELATIVE_PATH);
            if (file_exists($path) || is_link($path)) {
                throw new RuntimeException('Existe configuración runtime sin aprobación; no se sobrescribirá.');
            }
            $this->workspaces->writeState($execution, self::RELATIVE_PATH, json_decode($content, true, 64, JSON_THROW_ON_ERROR));

            return ExecutionRuntimeConfiguration::query()->create([
                'execution_id' => $execution->getKey(), 'tool_version_id' => $distribution->tool_version_id,
                'schema_version' => self::SCHEMA, 'source' => 'COLLECTOR_LAB_RENDERED', 'approved_by' => $actor->getKey(),
                'relative_path' => self::RELATIVE_PATH, 'content_sha256' => $hash, 'fingerprint' => $hash,
                'approval_state' => 'APPROVED', 'approved_at' => now()->utc(),
            ]);
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    public function verify(Execution $execution, ExecutionRuntimeConfiguration $configuration): array
    {
        if ($configuration->schema_version !== self::SCHEMA || $configuration->approval_state !== 'APPROVED'
            || (int) $configuration->execution_id !== (int) $execution->getKey()) {
            throw new RuntimeException('La aprobación COLLECT LAB no está vigente para esta ejecución.');
        }
        $path = $this->workspaces->resolve($execution, 'state', $configuration->relative_path);
        $stat = @lstat($path);
        $contents = is_file($path) && ! is_link($path) ? file_get_contents($path) : false;
        if ($stat === false || $stat['nlink'] !== 1 || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0777) !== 0600
            || ! is_string($contents) || ! hash_equals($configuration->content_sha256, hash('sha256', $contents))) {
            throw new RuntimeException('La configuración runtime fue alterada o no es un archivo regular privado.');
        }
        $document = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($document) || ($document['execution_uuid'] ?? null) !== $execution->uuid
            || ($document['project_uuid'] ?? null) !== $execution->project->uuid || ($document['scope'] ?? null) !== 'lab') {
            throw new RuntimeException('La identidad del documento runtime no coincide.');
        }

        return $document;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
