<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $userCounts = null;

        if ($request->user()->isAdmin()) {
            $userCounts = [
                'total' => User::query()->count(),
                'active' => User::query()->where('is_active', true)->count(),
                'admins' => User::query()->where('role', UserRole::ADMIN)->count(),
            ];
        }

        return Inertia::render('dashboard', ['userCounts' => $userCounts]);
    }
}
