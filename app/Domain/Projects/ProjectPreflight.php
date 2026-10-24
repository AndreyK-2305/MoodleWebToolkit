<?php

namespace App\Domain\Projects;

use App\Domain\Collector\CollectorConfiguration;
use App\Domain\Collector\CollectorPreflight;
use App\Domain\Remote\RemoteCollectionConfiguration;
use App\Domain\Remote\RemoteCollectionPreflight;
use App\Domain\Remote\RemoteConsolidationConfiguration;
use App\Domain\Remote\RemoteConsolidationPreflight;
use App\Enums\ProjectType;
use App\Models\Project;
use App\Models\ProjectConfiguration;

final class ProjectPreflight
{
    public function __construct(
        private readonly SimulatedPreflight $demo,
        private readonly CollectorPreflight $collector,
        private readonly CollectorConfiguration $configurations,
        private readonly RemoteCollectionPreflight $remote,
        private readonly RemoteCollectionConfiguration $remoteConfigurations,
        private readonly RemoteConsolidationPreflight $consolidation,
        private readonly RemoteConsolidationConfiguration $consolidationConfigurations,
    ) {}

    /** @return list<array{id: string, description: string, result: string, detail: string}> */
    public function evaluate(Project $project, ProjectConfiguration $configuration): array
    {
        if ($this->remoteConsolidation($project, $configuration)) {
            return $this->consolidation->evaluate($project, $configuration);
        }
        if ($this->remoteConfigurations->selected($configuration)) {
            return $this->remote->evaluate($project, $configuration);
        }

        return $this->configurations->selected($configuration) ? $this->collector->evaluate($project, $configuration) : $this->demo->evaluate($project, $configuration);
    }

    public function fingerprint(Project $project, ProjectConfiguration $configuration): string
    {
        if ($this->remoteConsolidation($project, $configuration)) {
            return $this->consolidation->fingerprint($project, $configuration);
        }
        if ($this->remoteConfigurations->selected($configuration)) {
            return $this->remote->fingerprint($project, $configuration);
        }

        return $this->configurations->selected($configuration) ? $this->collector->fingerprint($project, $configuration) : $this->demo->fingerprint($project, $configuration);
    }

    /** @return list<string> */
    public function configurationErrors(Project $project, ProjectConfiguration $configuration): array
    {
        if ($this->remoteConsolidation($project, $configuration)) {
            return $this->consolidation->configurationErrors($project, $configuration);
        }
        if ($this->remoteConfigurations->selected($configuration)) {
            return $this->remote->configurationErrors($project, $configuration);
        }

        return $this->configurations->selected($configuration) ? $this->collector->configurationErrors($project, $configuration) : $this->demo->configurationErrors($project, $configuration);
    }

    /**
     * With the remote agent a consolidation is always remote, even before its card is
     * saved: the simulated checks would demand source instances it never uses.
     */
    private function remoteConsolidation(Project $project, ProjectConfiguration $configuration): bool
    {
        return $this->consolidationConfigurations->selected($configuration)
            || ($project->type === ProjectType::CONSOLIDATE && config('toolkit.features.remote_agent.enabled')
                && ($configuration->settings['options']['mode'] ?? null) === null);
    }
}
