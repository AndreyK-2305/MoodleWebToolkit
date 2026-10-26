<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\UserNotificationPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Each user decides which execution emails reach them. */
class NotificationPreferencesController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/notifications', [
            'preferences' => UserNotificationPreference::for($request->user()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'receive_email' => ['required', 'boolean'],
            'interventions' => ['required', 'boolean'],
            'outcomes' => ['required', 'boolean'],
            'backups' => ['required', 'boolean'],
            'progress' => ['required', 'boolean'],
        ]);
        UserNotificationPreference::query()->updateOrCreate(['user_id' => $request->user()->getKey()], $validated);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Preferencias de notificación guardadas.']);

        return to_route('notifications.edit');
    }
}
