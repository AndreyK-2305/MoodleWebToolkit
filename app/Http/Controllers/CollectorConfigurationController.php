<?php

namespace App\Http\Controllers;

use App\Domain\Collector\CollectorConfiguration;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class CollectorConfigurationController extends Controller
{
    public function __invoke(Request $request, Project $project, CollectorConfiguration $configurations): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless(config('toolkit.features.recolector_742.enabled') && config('toolkit.features.local_runner.enabled'), 409, 'COLLECT LAB está deshabilitado.');
        // Reject unknown input before validation can flash it to the session.
        abort_if(array_diff(array_keys($request->except('_token')), ['profile_id', 'workers', 'package_name', 'capacity_bytes', 'safety_margin_percent']) !== [], 422, 'Parámetros COLLECT LAB no registrados.');
        $validated = $request->validate([
            'profile_id' => ['required', 'string', 'max:64'], 'workers' => ['required', 'integer', 'between:1,4'],
            'package_name' => ['required', 'string', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/D'],
            'capacity_bytes' => ['required', 'integer', 'between:16777216,21474836480'],
            'safety_margin_percent' => ['required', 'integer', 'between:10,100'],
        ]);
        foreach (['workers', 'capacity_bytes', 'safety_margin_percent'] as $key) {
            $validated[$key] = (int) $validated[$key];
        }
        /** @var User $actor */
        $actor = $request->user();
        try {
            $configurations->save($project, $actor, $validated);
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['collector' => 'El perfil de laboratorio no está disponible o dejó de ser válido.']);
        }

        return to_route('projects.show', $project->uuid);
    }
}
