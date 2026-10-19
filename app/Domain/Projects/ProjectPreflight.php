<?php

namespace App\Domain\Projects;

use App\Domain\Collector\CollectorConfiguration;
use App\Domain\Collector\CollectorPreflight;
use App\Models\Project;
use App\Models\ProjectConfiguration;

final class ProjectPreflight
{
    public function __construct(
        private readonly SimulatedPreflight $demo,
        private readonly CollectorPreflight $collector,
        private readonly CollectorConfiguration $configurations,
    ) {}

    /** @return list<array{id: string, description: string, result: string, detail: string}> */
    public function evaluate(Project $project, ProjectConfiguration $configuration): array
    {
        return $this->configurations->selected($configuration) ? $this->collector->evaluate($project, $configuration) : $this->demo->evaluate($project, $configuration);
    }

    public function fingerprint(Project $project, ProjectConfiguration $configuration): string
    {
        return $this->configurations->selected($configuration) ? $this->collector->fingerprint($project, $configuration) : $this->demo->fingerprint($project, $configuration);
    }

    /** @return list<string> */
    public function configurationErrors(Project $project, ProjectConfiguration $configuration): array
    {
        return $this->configurations->selected($configuration) ? $this->collector->configurationErrors($project, $configuration) : $this->demo->configurationErrors($project, $configuration);
    }
}
