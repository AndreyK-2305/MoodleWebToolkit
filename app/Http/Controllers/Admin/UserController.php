<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/users/index', [
            'users' => User::query()
                ->select(['id', 'name', 'email', 'role', 'is_active', 'created_at'])
                ->latest()
                ->paginate(15),
            'roles' => collect(UserRole::cases())->map(fn (UserRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'email' => User::normalizeEmail((string) $request->input('email')),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);

        User::query()->create([
            ...$validated,
            'email_verified_at' => now(),
            'is_active' => true,
            'must_change_password' => true,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Usuario creado.']);

        return back();
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::enum(UserRole::class)],
        ]);
        $role = UserRole::from($validated['role']);

        abort_if($request->user()->is($user) && $role !== UserRole::ADMIN, 422, 'No puedes retirar tu propio rol de administrador.');

        DB::transaction(fn () => $user->update(['role' => $role]));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rol actualizado.']);

        return back();
    }

    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $active = (bool) $validated['is_active'];

        abort_if($request->user()->is($user) && ! $active, 422, 'No puedes desactivar tu propia cuenta.');

        DB::transaction(function () use ($active, $user): void {
            if (! $active && $user->isAdmin()) {
                $otherActiveAdmin = User::query()
                    ->whereKeyNot($user->getKey())
                    ->where('role', UserRole::ADMIN)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first(['id']);

                abort_if($otherActiveAdmin === null, 422, 'Debe permanecer al menos un administrador activo.');
            }

            $user->update(['is_active' => $active]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => $active ? 'Usuario activado.' : 'Usuario desactivado.']);

        return back();
    }
}
