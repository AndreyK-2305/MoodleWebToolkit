<?php

namespace App\Http\Controllers;

use App\Domain\Projects\ProjectWizard;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Models\MoodleInstance;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Project::class);
        /** @var User $actor */
        $actor = $request->user();
        $projects = Project::query()
            ->visibleTo($actor)
            ->with('configuration')
            ->latest()
            ->paginate(12)
            ->through(function (Project $project) use ($actor): array {
                $settings = is_array($project->configuration?->settings)
                    ? $project->configuration->settings
                    : [];

                return [
                    'uuid' => $project->uuid,
                    'name' => $project->name,
                    'type' => $project->type->value,
                    'type_label' => $project->type->label(),
                    'status' => $project->status->value,
                    'status_label' => $project->status->label(),
                    'current_step' => max(1, min(5, (int) ($settings['wizard_step'] ?? 1))),
                    'can_edit' => $this->canEdit($actor, $project),
                    'updated_at' => $project->updated_at?->toIso8601String(),
                ];
            });

        return Inertia::render('projects/index', [
            'projects' => $projects,
            'canCreate' => $actor->can('create', Project::class),
            'projectTypes' => $this->projectTypes(),
        ]);
    }

    public function store(Request $request, ProjectWizard $wizard): RedirectResponse
    {
        Gate::authorize('create', Project::class);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::enum(ProjectType::class)],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        /** @var array{name: string, type: string, description?: string|null} $validated */
        $project = $wizard->create($actor, $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Proyecto creado. Continúe con las instancias simuladas.']);

        return to_route('projects.show', $project->uuid);
    }

    public function show(Request $request, Project $project, ProjectWizard $wizard): Response
    {
        Gate::authorize('view', $project);
        /** @var User $actor */
        $actor = $request->user();
        $project->load(['configuration', 'moodleInstances.server']);
        $configuration = $project->configuration;
        abort_if($configuration === null, 409, 'El proyecto no tiene configuración de wizard.');
        $settings = $wizard->settings($configuration);

        return Inertia::render('projects/show', [
            'project' => [
                'uuid' => $project->uuid,
                'name' => $project->name,
                'description' => $project->description,
                'type' => $project->type->value,
                'type_label' => $project->type->label(),
                'status' => $project->status->value,
                'status_label' => $project->status->label(),
                'current_step' => $settings['wizard_step'],
                'configuration_version' => $configuration->version,
                'options' => $settings['options'],
                'preflight' => $settings['preflight'],
                'confirmation' => $settings['confirmation'],
                'instances' => $project->moodleInstances
                    ->sortBy(fn (MoodleInstance $instance): string => $instance->role->value.$instance->name)
                    ->map(fn (MoodleInstance $instance): array => [
                        'uuid' => $instance->uuid,
                        'server_uuid' => $instance->server?->uuid,
                        'role' => $instance->role->value,
                        'server_name' => $instance->server?->name,
                        'server_host' => $instance->server?->host,
                        'name' => $instance->name,
                        'base_url' => $instance->base_url,
                        'moodle_version' => $instance->moodle_version,
                        'validated' => $instance->validated,
                        'destination_kind' => is_array($instance->metadata)
                            ? ($instance->metadata['destination_kind'] ?? null)
                            : null,
                    ])
                    ->values(),
                'can_edit' => $this->canEdit($actor, $project),
            ],
            'projectTypes' => $this->projectTypes(),
        ]);
    }

    /** @return list<array{value: string, label: string}> */
    private function projectTypes(): array
    {
        return array_map(
            fn (ProjectType $type): array => ['value' => $type->value, 'label' => $type->label()],
            ProjectType::cases(),
        );
    }

    private function canEdit(User $actor, Project $project): bool
    {
        return $actor->can('update', $project)
            && in_array($project->status, [ProjectStatus::DRAFT, ProjectStatus::CONFIGURING, ProjectStatus::READY], true);
    }
}
