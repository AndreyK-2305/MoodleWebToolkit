<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $actor = $request->user();
        $userCounts = null;

        if ($actor->isAdmin()) {
            $userCounts = [
                'total' => User::query()->count(),
                'active' => User::query()->where('is_active', true)->count(),
                'admins' => User::query()->where('role', UserRole::ADMIN)->count(),
            ];
        }

        $visibleProjects = Project::query()->visibleTo($actor);
        $projectCounts = [
            'total' => (clone $visibleProjects)->count(),
            'configuring' => (clone $visibleProjects)
                ->whereIn('status', [ProjectStatus::DRAFT, ProjectStatus::CONFIGURING])
                ->count(),
            'ready' => (clone $visibleProjects)->where('status', ProjectStatus::READY)->count(),
            'active' => (clone $visibleProjects)->whereIn('status', [
                ProjectStatus::QUEUED,
                ProjectStatus::RUNNING,
                ProjectStatus::WAITING_USER_ACTION,
                ProjectStatus::CANCELLING,
                ProjectStatus::VERIFYING,
            ])->count(),
        ];

        return Inertia::render('dashboard', [
            'userCounts' => $userCounts,
            'projectCounts' => $projectCounts,
        ]);
    }
}
