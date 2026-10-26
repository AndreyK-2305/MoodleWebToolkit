<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Notifications\PlatformMailer;
use App\Enums\SmtpEncryption;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\NotificationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform's SMTP configuration, for administrators. The password is never
 * sent back to the browser: the page only knows whether one is configured, and
 * the form can keep it, replace it or clear it. Changes are audited by field
 * name, never by value.
 */
class NotificationSettingsController extends Controller
{
    private const FIELDS = ['enabled', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'from_address', 'from_name', 'progress_interval_minutes'];

    public function edit(Request $request): Response
    {
        $settings = NotificationSettings::current();

        return Inertia::render('admin/notifications', [
            'settings' => $settings === null ? null : [
                ...$settings->only(self::FIELDS),
                'smtp_encryption' => $settings->smtp_encryption->value,
                'password_configured' => $settings->smtp_password !== null && $settings->smtp_password !== '',
                'updated_at' => $settings->updated_at?->toIso8601String(),
            ],
            'encryptions' => collect(SmtpEncryption::cases())->map(fn (SmtpEncryption $encryption) => [
                'value' => $encryption->value,
                'label' => $encryption->label(),
            ]),
            'usesApplicationMailer' => $settings === null,
            'lastTest' => $request->session()->get('notificationTest'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'smtp_host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$|^\[[0-9A-Fa-f:.]+\]$/'],
            'smtp_port' => ['required', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['required', Rule::enum(SmtpEncryption::class)],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'password_action' => ['required', Rule::in(['KEEP', 'REPLACE', 'CLEAR'])],
            'smtp_password' => ['exclude_unless:password_action,REPLACE', 'required', 'string', 'max:255'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:120'],
            'progress_interval_minutes' => ['nullable', 'integer', 'between:5,1440'],
        ], [
            'smtp_host.regex' => 'Escriba sólo el nombre o la IP del servidor, sin protocolo ni ruta.',
            'smtp_password.required' => 'Escriba la nueva contraseña o elija conservar la actual.',
            'progress_interval_minutes.between' => 'El intervalo del resumen debe estar entre 5 y 1440 minutos.',
        ]);
        $action = $validated['password_action'];
        unset($validated['password_action'], $validated['smtp_password']);
        $values = [...$validated, 'smtp_username' => ($validated['smtp_username'] ?? '') !== '' ? $validated['smtp_username'] : null];

        DB::transaction(function () use ($request, $values, $action): void {
            $settings = NotificationSettings::query()->lockForUpdate()->orderBy('id')->first() ?? new NotificationSettings;
            $settings->fill([...$values, 'updated_by' => $request->user()->getKey()]);
            if ($action === 'REPLACE') {
                $settings->smtp_password = (string) $request->input('smtp_password');
            } elseif ($action === 'CLEAR') {
                $settings->smtp_password = null;
            }
            $created = ! $settings->exists;
            $changed = array_values(array_diff(array_keys($settings->getDirty()), ['updated_by', 'smtp_password']));
            $settings->save();
            AuditLog::query()->create([
                'actor_id' => $request->user()->getKey(), 'action' => 'NOTIFICATION_SETTINGS_UPDATED',
                'auditable_type' => $settings->getMorphClass(), 'auditable_id' => $settings->getKey(),
                'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                // Field names only: no host, address or password ever reaches the audit trail.
                'payload' => ['created' => $created, 'changed_fields' => $changed, 'password' => match ($action) {
                    'REPLACE' => 'REPLACED', 'CLEAR' => 'CLEARED', default => 'KEPT',
                }, 'enabled' => $settings->enabled],
            ]);
        }, attempts: 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Configuración de notificaciones guardada.']);

        return to_route('admin.notifications.edit');
    }

    public function test(Request $request, PlatformMailer $mailer): RedirectResponse
    {
        $settings = NotificationSettings::current();
        if ($settings === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Guarde primero la configuración SMTP para poder probarla.']);

            return to_route('admin.notifications.edit');
        }
        $error = $mailer->sendTest($settings, $request->user()->email);
        AuditLog::query()->create([
            'actor_id' => $request->user()->getKey(), 'action' => 'NOTIFICATION_TEST_EMAIL',
            'auditable_type' => $settings->getMorphClass(), 'auditable_id' => $settings->getKey(),
            'ip_address' => $request->ip(), 'payload' => ['outcome' => $error === null ? 'SENT' : 'FAILED'],
        ]);
        Inertia::flash('toast', $error === null
            ? ['type' => 'success', 'message' => 'Correo de prueba enviado a su dirección. Revise su bandeja de entrada.']
            : ['type' => 'error', 'message' => 'No se pudo enviar el correo de prueba. '.$error]);

        return to_route('admin.notifications.edit')->with('notificationTest', ['ok' => $error === null, 'message' => $error]);
    }
}
