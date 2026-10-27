<?php

namespace App\Http\Controllers;

use App\Domain\Collector\CollectorConfiguration;
use App\Domain\Collector\LabMoodleProfiles;
use App\Domain\Projects\ProjectWizard;
use App\Domain\Projects\SimulatedUrlSafety;
use App\Domain\Remote\RemoteAccessPresenter;
use App\Domain\Remote\RemoteConsolidationCopias;
use App\Domain\Remote\RemoteConsolidationSources;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Models\MoodleInstance;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        ]);
    }

    /** Step 1 of the wizard for a project that does not exist yet: saving it creates the project. */
    public function create(): Response
    {
        Gate::authorize('create', Project::class);

        return Inertia::render('projects/create', [
            'projectTypes' => $this->projectTypes(),
            // With real servers a consolidation names its destination in this step.
            'destinationLabel' => (bool) config('toolkit.features.remote_agent.enabled'),
        ]);
    }

    public function store(Request $request, ProjectWizard $wizard): RedirectResponse
    {
        Gate::authorize('create', Project::class);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(ProjectType::creatableValues())],
            'description' => ['nullable', 'string', 'max:2000'],
            'destination_label' => ['nullable', 'string', 'max:120'],
        ], ['type.in' => 'Elija Recolección o Consolidación: la integración no está disponible para proyectos nuevos.']);
        /** @var User $actor */
        $actor = $request->user();
        // Creating is saving step 1: a remote consolidation registers its destination there too.
        /** @var array{name: string, type: string, description?: string|null, destination_label?: string|null} $validated */
        $project = DB::transaction(fn (): Project => $wizard->saveBasics($wizard->create($actor, $validated), $actor, $validated));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Proyecto creado. Continúe con el asistente.']);

        return to_route('projects.show', $project->uuid);
    }

    public function show(
        Request $request,
        Project $project,
        ProjectWizard $wizard,
        SimulatedUrlSafety $urlSafety,
    ): Response {
        Gate::authorize('view', $project);
        /** @var User $actor */
        $actor = $request->user();
        $project->load(['configuration', 'moodleInstances.server']);
        $configuration = $project->configuration;
        abort_if($configuration === null, 409, 'El proyecto no tiene configuración de wizard.');
        $settings = $wizard->settings($configuration);
        $latestExecution = $project->executions()->latest('attempt')->first();

        $laboratory = app(CollectorConfiguration::class)->selected($configuration);

        return Inertia::render($laboratory ? 'projects/collector' : 'projects/show', [
            'collectorLab' => [
                'enabled' => $project->type === ProjectType::COLLECT && (bool) config('toolkit.features.recolector_742.enabled') && (bool) config('toolkit.features.local_runner.enabled'),
                'profiles' => app(LabMoodleProfiles::class)->choices(),
            ],
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
                        'base_url' => $urlSafety->safeDisplayValue($instance->base_url),
                        'moodle_version' => $instance->moodle_version,
                        'validated' => $instance->validated,
                        'destination_kind' => is_array($instance->metadata)
                            ? ($instance->metadata['destination_kind'] ?? null)
                            : null,
                    ])
                    ->values(),
                'can_edit' => $this->canEdit($actor, $project),
                'can_start' => $actor->can('startExecution', $project)
                    && $project->status === ProjectStatus::READY,
                'latest_execution' => $latestExecution === null ? null : [
                    'uuid' => $latestExecution->uuid,
                    'attempt' => $latestExecution->attempt,
                    'status' => $latestExecution->status->value,
                ],
            ],
            'projectTypes' => $this->projectTypes($project->type),
            'remoteAccess' => app(RemoteAccessPresenter::class)->present($project),
            'consolidationSources' => $this->consolidationSources($project, $actor),
            'linkedConsolidations' => $this->linkedConsolidations($project, $actor),
        ]);
    }

    /**
     * Where a remote consolidation takes its sources from: its link, the completed
     * collections the viewer may use and the state of its packages in copias/.
     *
     * @return array<string, mixed>|null
     */
    private function consolidationSources(Project $project, User $actor): ?array
    {
        if ($project->type !== ProjectType::CONSOLIDATE || ! config('toolkit.features.remote_agent.enabled')) {
            return null;
        }
        $sources = app(RemoteConsolidationSources::class);

        return [
            'link' => $sources->presentLink($project),
            'selection' => $sources->presentSelection($project),
            'collections' => $sources->completedCollections($actor),
            'copias' => app(RemoteConsolidationCopias::class)->progress($project),
            'min_sources' => RemoteConsolidationSources::MIN_SOURCES,
            'max_sources' => RemoteConsolidationSources::MAX_SOURCES,
        ];
    }

    /**
     * The consolidations that wait for this collection's packages.
     *
     * @return list<array{uuid: string, name: string}>
     */
    private function linkedConsolidations(Project $project, User $actor): array
    {
        if ($project->type !== ProjectType::COLLECT) {
            return [];
        }

        return array_values(Project::query()->where('source_project_id', $project->getKey())
            ->where('source_link->mode', RemoteConsolidationSources::MODE_COLLECT_NOW)->get()
            ->filter(fn (Project $consolidation): bool => $actor->can('view', $consolidation))
            ->map(fn (Project $consolidation): array => ['uuid' => $consolidation->uuid, 'name' => $consolidation->name])
            ->all());
    }

    /**
     * The operations a project may choose; an existing project keeps its own
     * even when it is no longer offered for new projects.
     *
     * @return list<array{value: string, label: string}>
     */
    private function projectTypes(?ProjectType $current = null): array
    {
        $types = ProjectType::creatable();
        if ($current !== null && ! in_array($current, $types, true)) {
            $types[] = $current;
        }

        return array_map(fn (ProjectType $type): array => ['value' => $type->value, 'label' => $type->label()], $types);
    }

    private function canEdit(User $actor, Project $project): bool
    {
        return $actor->can('update', $project)
            && in_array($project->status, [ProjectStatus::DRAFT, ProjectStatus::CONFIGURING, ProjectStatus::READY], true);
    }
}
