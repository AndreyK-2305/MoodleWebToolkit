<?php

namespace App\Http\Controllers;

use App\Domain\Projects\ProjectWizard;
use App\Domain\Projects\SimulatedUrlSafety;
use App\Enums\MoodleInstanceRole;
use App\Enums\ProjectType;
use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ProjectWizardController extends Controller
{
    public function basics(Request $request, Project $project, ProjectWizard $wizard): RedirectResponse
    {
        Gate::authorize('update', $project);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::enum(ProjectType::class)],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        /** @var array{name: string, type: string, description?: string|null} $validated */
        $wizard->saveBasics($project, $actor, $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Datos básicos guardados.']);

        return to_route('projects.show', $project->uuid);
    }

    public function instances(
        Request $request,
        Project $project,
        ProjectWizard $wizard,
        SimulatedUrlSafety $urlSafety,
    ): RedirectResponse {
        Gate::authorize('update', $project);
        $validated = $request->validate([
            'instances' => ['present', 'array', 'max:12'],
            'instances.*.uuid' => ['nullable', 'uuid'],
            'instances.*.server_uuid' => ['nullable', 'uuid'],
            'instances.*.role' => ['required', Rule::enum(MoodleInstanceRole::class)],
            'instances.*.server_name' => ['required', 'string', 'max:120'],
            'instances.*.server_host' => ['required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9.-]+$/'],
            'instances.*.name' => ['required', 'string', 'max:120'],
            'instances.*.base_url' => [
                'required',
                'string',
                function (string $_attribute, mixed $value, Closure $fail) use ($urlSafety): void {
                    if (is_string($value) && ($error = $urlSafety->validationError($value)) !== null) {
                        $fail($error);
                    }
                },
            ],
            'instances.*.moodle_version' => ['required', 'string', 'max:40', 'regex:/^\d+\.\d+(?:\.\d+)?$/'],
            'instances.*.validated' => ['required', 'boolean'],
            'instances.*.destination_kind' => ['nullable', Rule::in(['PREPARED', 'EXISTING_CONSOLIDATED'])],
        ], [
            'instances.*.server_host.regex' => 'Use un host simulado válido, sin protocolo ni ruta.',
        ]);
        /** @var User $actor */
        $actor = $request->user();
        /** @var array{instances: list<array{uuid?: string|null, server_uuid?: string|null, role: string, server_name: string, server_host: string, name: string, base_url: string, moodle_version: string, validated: bool, destination_kind?: string|null}>} $validated */
        $wizard->saveInstances($project, $actor, $validated['instances']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Instancias simuladas guardadas.']);

        return to_route('projects.show', $project->uuid);
    }

    public function options(Request $request, Project $project, ProjectWizard $wizard): RedirectResponse
    {
        Gate::authorize('update', $project);
        $validated = $request->validate([
            'simulation_scenario' => ['nullable', Rule::in(['SUCCESS', 'WARNING', 'ERROR'])],
            'artifact_name' => ['nullable', 'string', 'max:120'],
            'category_strategy' => ['nullable', Rule::in(['PRESERVE', 'PREFIX_SOURCE'])],
            'user_conflict_strategy' => ['nullable', Rule::in(['KEEP_DESTINATION', 'REVIEW'])],
            'admin_strategy' => ['nullable', Rule::in(['EXCLUDE_SOURCE_ADMINS'])],
            'include_archived_courses' => ['sometimes', 'boolean'],
            'conflict_strategy' => ['nullable', Rule::in(['REVIEW'])],
            'preserve_destination_admins' => ['sometimes', 'boolean'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        /** @var array<string, mixed> $validated */
        $wizard->saveOptions($project, $actor, $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Configuración guardada.']);

        return to_route('projects.show', $project->uuid);
    }

    public function preflight(Request $request, Project $project, ProjectWizard $wizard): RedirectResponse
    {
        Gate::authorize('update', $project);
        /** @var User $actor */
        $actor = $request->user();
        $wizard->runPreflight($project, $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Preflight simulado actualizado.']);

        return to_route('projects.show', $project->uuid);
    }

    public function confirm(Request $request, Project $project, ProjectWizard $wizard): RedirectResponse
    {
        Gate::authorize('update', $project);
        $validated = $request->validate([
            'configuration_version' => ['required', 'integer', 'min:1'],
            'accepted_warning_ids' => ['present', 'array'],
            'accepted_warning_ids.*' => ['string', 'max:120', 'distinct'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        /** @var array{configuration_version: int, accepted_warning_ids: list<string>} $validated */
        $wizard->confirm(
            $project,
            $actor,
            $validated['configuration_version'],
            $validated['accepted_warning_ids'],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Configuración confirmada. El proyecto está listo.']);

        return to_route('projects.show', $project->uuid);
    }
}
