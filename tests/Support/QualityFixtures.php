<?php

namespace Tests\Support;

use App\Domain\Projects\ProjectWizard;
use App\Enums\ProjectType;
use App\Models\Project;
use App\Models\User;

final class QualityFixtures
{
    public static function ready(User $actor, string $type = 'COLLECT', string $scenario = 'SUCCESS'): Project
    {
        $wizard = app(ProjectWizard::class);
        $project = $wizard->create($actor, ['name' => 'Calidad '.$type, 'type' => $type]);
        $roles = match (ProjectType::from($type)) {
            ProjectType::COLLECT => ['SOURCE'],
            ProjectType::CONSOLIDATE => ['SOURCE', 'SOURCE', 'DESTINATION'],
            ProjectType::INTEGRATE => ['SOURCE', 'DESTINATION'],
        };
        $instances = [];
        foreach ($roles as $index => $role) {
            $instances[] = [
                'uuid' => null, 'server_uuid' => null, 'role' => $role,
                'name' => 'Moodle '.$index, 'server_name' => 'Servidor '.$index,
                'server_host' => "moodle-{$index}.test", 'base_url' => "https://moodle-{$index}.test",
                'moodle_version' => '4.5', 'validated' => true,
                'destination_kind' => $role === 'SOURCE' ? null : ($type === 'CONSOLIDATE' ? 'PREPARED' : 'EXISTING_CONSOLIDATED'),
            ];
        }
        $wizard->saveInstances($project, $actor, $instances);
        $wizard->saveOptions($project, $actor, self::options($type, $scenario));
        $wizard->runPreflight($project, $actor);
        $wizard->confirm($project, $actor, $project->fresh('configuration')->configuration->version, []);

        return $project->fresh('configuration');
    }

    public static function options(string $type, string $scenario = 'SUCCESS'): array
    {
        return ['simulation_scenario' => 'SUCCESS', 'processing_scenario' => $scenario] + match ($type) {
            'COLLECT' => ['artifact_name' => 'paquete-quality'],
            'CONSOLIDATE' => ['category_strategy' => 'PRESERVE', 'user_conflict_strategy' => 'REVIEW', 'admin_strategy' => 'EXCLUDE_SOURCE_ADMINS', 'include_archived_courses' => false],
            'INTEGRATE' => ['conflict_strategy' => 'REVIEW', 'preserve_destination_admins' => true],
        };
    }
}
