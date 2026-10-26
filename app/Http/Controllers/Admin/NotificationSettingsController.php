<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Notifications\PlatformMailer;
use App\Enums\NotificationGroup;
use App\Enums\SmtpEncryption;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\NotificationRecipient;
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
            'recipients' => NotificationRecipient::query()->orderBy('id')->get()
                ->map(fn (NotificationRecipient $recipient) => $recipient->only(['email', 'interventions', 'outcomes', 'backups', 'progress'])),
            'maxRecipients' => NotificationRecipient::MAX,
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

    /**
     * Replaces the list of extra recipients. Addresses are compared lowercased, so the
     * same mailbox cannot appear twice; the audit keeps counts, never the addresses.
     */
    public function updateRecipients(Request $request): RedirectResponse
    {
        $input = $request->input('recipients', []);
        if (is_array($input)) {
            $request->merge(['recipients' => array_map(fn (mixed $recipient) => is_array($recipient) && is_string($recipient['email'] ?? null)
                ? [...$recipient, 'email' => mb_strtolower(trim($recipient['email']))] : $recipient, $input)]);
        }
        $groupRules = [];
        foreach (NotificationGroup::cases() as $group) {
            $groupRules['recipients.*.'.$group->preference()] = ['required', 'boolean'];
        }
        $validated = $request->validate([
            'recipients' => ['present', 'array', 'max:'.NotificationRecipient::MAX],
            'recipients.*' => ['array:email,interventions,outcomes,backups,progress'],
            'recipients.*.email' => ['required', 'email', 'max:255', 'distinct'],
            ...$groupRules,
        ], [
            'recipients.max' => 'Puede agregar hasta '.NotificationRecipient::MAX.' destinatarios adicionales.',
            'recipients.*.email.required' => 'Escriba la dirección de correo.',
            'recipients.*.email.email' => 'Escriba una dirección de correo válida.',
            'recipients.*.email.distinct' => 'Esta dirección ya está en la lista.',
        ]);

        DB::transaction(function () use ($request, $validated): void {
            $before = NotificationRecipient::query()->lockForUpdate()->get()->keyBy('email');
            $kept = [];
            $added = 0;
            $changed = 0;
            foreach ($validated['recipients'] as $values) {
                $recipient = $before->get($values['email']) ?? new NotificationRecipient(['email' => $values['email']]);
                foreach (NotificationGroup::cases() as $group) {
                    $recipient->setAttribute($group->preference(), filter_var($values[$group->preference()], FILTER_VALIDATE_BOOLEAN));
                }
                if (! $recipient->exists) {
                    $added++;
                } elseif ($recipient->isDirty()) {
                    $changed++;
                }
                $recipient->save();
                $kept[] = $recipient->email;
            }
            $removed = NotificationRecipient::query()->whereNotIn('email', $kept)->delete();
            AuditLog::query()->create([
                'actor_id' => $request->user()->getKey(), 'action' => 'NOTIFICATION_RECIPIENTS_UPDATED',
                'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                // Counts only: the addresses themselves never reach the audit trail.
                'payload' => ['count' => count($kept), 'added' => $added, 'removed' => $removed, 'changed' => $changed],
            ]);
        }, attempts: 3);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Destinatarios adicionales guardados.']);

        return to_route('admin.notifications.edit');
    }

    /** Sends the test message to the admin, to a typed address or to every extra recipient. */
    public function test(Request $request, PlatformMailer $mailer): RedirectResponse
    {
        $validated = $request->validate([
            'target' => ['nullable', Rule::in(['SELF', 'ADDRESS', 'RECIPIENTS'])],
            'address' => ['exclude_unless:target,ADDRESS', 'required', 'email', 'max:255'],
        ], ['address.required' => 'Escriba la dirección a la que enviar la prueba.', 'address.email' => 'Escriba una dirección de correo válida.']);
        $target = $validated['target'] ?? 'SELF';
        $settings = NotificationSettings::current();
        if ($settings === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Guarde primero la configuración SMTP para poder probarla.']);

            return to_route('admin.notifications.edit');
        }
        $addresses = match ($target) {
            'ADDRESS' => [mb_strtolower(trim($validated['address']))],
            'RECIPIENTS' => NotificationRecipient::query()->orderBy('id')->pluck('email')->all(),
            default => [$request->user()->email],
        };
        if ($addresses === []) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'No hay destinatarios adicionales guardados.']);

            return to_route('admin.notifications.edit');
        }
        $sent = 0;
        $error = null;
        // One message per address, so no recipient sees the others; the first failure stops the run.
        foreach ($addresses as $address) {
            $error = $mailer->sendTest($settings, $address);
            if ($error !== null) {
                break;
            }
            $sent++;
        }
        AuditLog::query()->create([
            'actor_id' => $request->user()->getKey(), 'action' => 'NOTIFICATION_TEST_EMAIL',
            'auditable_type' => $settings->getMorphClass(), 'auditable_id' => $settings->getKey(),
            'ip_address' => $request->ip(), 'payload' => ['outcome' => $error === null ? 'SENT' : 'FAILED', 'target' => $target, 'sent' => $sent],
        ]);
        $done = match ($target) {
            'ADDRESS' => 'Correo de prueba enviado a '.$addresses[0].'.',
            'RECIPIENTS' => 'Correo de prueba enviado a '.$sent.' '.($sent === 1 ? 'destinatario adicional' : 'destinatarios adicionales').'.',
            default => 'Correo de prueba enviado a su dirección. Revise su bandeja de entrada.',
        };
        $failed = 'No se pudo enviar el correo de prueba'.($target === 'RECIPIENTS' && $sent > 0 ? ' a todos (llegó a '.$sent.')' : '').'. '.$error;
        Inertia::flash('toast', $error === null ? ['type' => 'success', 'message' => $done] : ['type' => 'error', 'message' => $failed]);

        return to_route('admin.notifications.edit')->with('notificationTest', ['ok' => $error === null, 'message' => $error === null ? $done : $error]);
    }
}
