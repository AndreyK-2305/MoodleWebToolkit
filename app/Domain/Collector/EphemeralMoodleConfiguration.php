<?php

namespace App\Domain\Collector;

use App\Domain\Collector\Contracts\SecretProvider;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Models\Execution;

/** @phpstan-import-type LabMoodleProfile from LabMoodleProfiles */
final class EphemeralMoodleConfiguration
{
    public function __construct(
        private readonly SecretProvider $secrets,
        private readonly ExecutionWorkspaceManager $workspaces,
    ) {}

    /**
     * @template T
     *
     * @param  LabMoodleProfile  $profile
     * @param  callable(string): T  $consumer
     * @return T
     */
    public function consume(Execution $execution, array $profile, callable $consumer): mixed
    {
        $path = $this->workspaces->resolve($execution, 'input', 'moodle-runtime.php');

        return (new MoodleConfigurationMaterializer($this->secrets, (string) config('toolkit.workspaces.root')))->consume($path, $profile, $consumer);
    }
}
