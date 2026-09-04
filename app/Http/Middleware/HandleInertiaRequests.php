<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $confirmedAt = $request->session()->get('auth.password_confirmed_at');

        $timeout = (int) config('auth.password_timeout');
        $expired = $request->user() !== null && (
            ! is_numeric($confirmedAt)
            || ((int) now()->timestamp - (int) $confirmedAt) >= $timeout
        );

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'actionConfirmation' => [
                'required' => (bool) $request->session()->get('action_confirmation_required', false),
                'expired' => $expired,
                'confirmed_at' => is_numeric($confirmedAt) ? (int) $confirmedAt : null,
                'expires_at' => is_numeric($confirmedAt) ? ((int) $confirmedAt) + $timeout : null,
                'lifetime_minutes' => (int) ceil($timeout / 60),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
