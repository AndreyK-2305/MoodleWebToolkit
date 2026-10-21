<?php

namespace App\Domain\Projects;

use App\Domain\Collector\CollectorConfiguration;
use App\Domain\Collector\CollectorPreflight;
use App\Domain\Remote\RemoteCollectionConfiguration;
use App\Domain\Remote\RemoteCollectionPreflight;
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
    ) {}

    /** @return list<array{id: string, description: string, result: string, detail: string}> */
    public function evaluate(Project $project, ProjectConfiguration $configuration): array
    {
        if ($this->remoteConfigurations->selected($configuration)) {
            return $this->remote->evaluate($project, $configuration);
        }

        return $this->configurations->selected($configuration) ? $this->collector->evaluate($project, $configuration) : $this->demo->evaluate($project, $configuration);
    }

    public function fingerprint(Project $project, ProjectConfiguration $configuration): string
    {
        if ($this->remoteConfigurations->selected($configuration)) {
            return $this->remote->fingerprint($project, $configuration);
        }

        return $this->configurations->selected($configuration) ? $this->collector->fingerprint($project, $configuration) : $this->demo->fingerprint($project, $configuration);
    }

    /** @return list<string> */
    public function configurationErrors(Project $project, ProjectConfiguration $configuration): array
    {
        if ($this->remoteConfigurations->selected($configuration)) {
            return $this->remote->configurationErrors($project, $configuration);
        }

        return $this->configurations->selected($configuration) ? $this->collector->configurationErrors($project, $configuration) : $this->demo->configurationErrors($project, $configuration);
    }
}
