<?php

namespace App\Domain\Projects;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use Illuminate\Validation\ValidationException;

class ConfirmedProjectValidator
{
    public function __construct(
        private readonly ProjectWizard $wizard,
        private readonly SimulatedPreflight $preflight,
    ) {}

    /** @return array{configuration_version: int, configuration_hash: string} */
    public function validateForStart(Project $project, int $requestedVersion): array
    {
        if ($project->status !== ProjectStatus::READY) {
            throw ValidationException::withMessages([
                'project' => 'Sólo puede iniciar un proyecto confirmado en estado READY.',
            ]);
        }

        $configuration = $project->configuration;

        if (! $configuration instanceof ProjectConfiguration || $configuration->version !== $requestedVersion) {
            throw ValidationException::withMessages([
                'configuration_version' => 'La versión confirmada ya no coincide con la configuración vigente.',
            ]);
        }

        $project->loadMissing(['moodleInstances.server']);
        $settings = $this->wizard->settings($configuration);
        $storedPreflight = $settings['preflight'];
        $confirmation = $settings['confirmation'];
        $currentHash = $this->preflight->fingerprint($project, $configuration);
        $currentChecks = $this->preflight->evaluate($project, $configuration);

        if (! is_array($storedPreflight)
            || ($storedPreflight['configuration_version'] ?? null) !== $configuration->version
            || ! hash_equals((string) ($storedPreflight['configuration_hash'] ?? ''), $currentHash)
            || ! is_array($storedPreflight['checks'] ?? null)
            || $this->canonicalChecks($storedPreflight['checks']) !== $this->canonicalChecks($currentChecks)
        ) {
            throw ValidationException::withMessages([
                'preflight' => 'El preflight está obsoleto o no corresponde a la configuración actual.',
            ]);
        }

        if ($this->preflight->configurationErrors($project, $configuration) !== []
            || $this->checkIds($currentChecks, 'ERROR') !== []
        ) {
            throw ValidationException::withMessages([
                'preflight' => 'El preflight vigente contiene errores que impiden iniciar.',
            ]);
        }

        $acceptedWarnings = is_array($confirmation)
            && is_array($confirmation['accepted_warning_ids'] ?? null)
                ? collect($confirmation['accepted_warning_ids'])
                    ->map(fn (mixed $id): string => (string) $id)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all()
                : [];

        if (! is_array($confirmation)
            || ($confirmation['configuration_version'] ?? null) !== $configuration->version
            || $acceptedWarnings !== $this->checkIds($currentChecks, 'WARNING')
        ) {
            throw ValidationException::withMessages([
                'confirmation' => 'La confirmación está obsoleta o no acepta las advertencias vigentes.',
            ]);
        }

        return [
            'configuration_version' => $configuration->version,
            'configuration_hash' => $currentHash,
        ];
    }

    /** @param array<mixed> $checks */
    private function canonicalChecks(array $checks): string
    {
        $normalized = collect($checks)
            ->map(fn (mixed $check): array => is_array($check) ? [
                'id' => (string) ($check['id'] ?? ''),
                'description' => (string) ($check['description'] ?? ''),
                'result' => (string) ($check['result'] ?? ''),
                'detail' => (string) ($check['detail'] ?? ''),
            ] : [])
            ->values()
            ->all();

        return json_encode($normalized, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<array{id: string, description: string, result: string, detail: string}>  $checks
     * @return list<string>
     */
    private function checkIds(array $checks, string $result): array
    {
        return array_values(collect($checks)
            ->filter(fn (array $check): bool => $check['result'] === $result)
            ->map(fn (array $check): string => $check['id'])
            ->sort()
            ->values()
            ->all());
    }
}
