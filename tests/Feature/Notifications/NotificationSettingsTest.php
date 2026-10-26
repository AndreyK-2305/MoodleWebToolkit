<?php

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\PlatformMailer;
use App\Enums\SmtpEncryption;
use App\Enums\UserRole;
use App\Mail\SmtpTestMessage;
use App\Models\AuditLog;
use App\Models\NotificationRecipient;
use App\Models\NotificationSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\TestCase;

class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'smtp-secret-never-shown-42';

    public function test_only_admins_reach_the_notification_settings(): void
    {
        $operator = User::factory()->create(['role' => UserRole::OPERATOR]);

        $this->actingAs($operator)->get(route('admin.notifications.edit'))->assertForbidden();
        $this->actingAs($operator)->put(route('admin.notifications.update'), $this->valid())->assertForbidden();
        $this->actingAs($operator)->post(route('admin.notifications.test'))->assertForbidden();
        $this->actingAs($operator)->put(route('admin.notifications.recipients.update'), ['recipients' => [$this->recipient('a@example.org')]])->assertForbidden();
        $this->assertDatabaseCount('notification_settings', 0);
        $this->assertDatabaseCount('notification_recipients', 0);
        $this->actingAs($this->admin())->get(route('admin.notifications.edit'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/notifications')
                ->where('settings', null)->where('usesApplicationMailer', true));
    }

    public function test_settings_are_validated(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.notifications.update'), $this->valid([
            'smtp_host' => 'smtp://mail.example.edu/path', 'smtp_port' => 70000, 'smtp_encryption' => 'SSLv2',
            'from_address' => 'no-es-correo', 'progress_interval_minutes' => 1,
        ]))->assertSessionHasErrors(['smtp_host', 'smtp_port', 'smtp_encryption', 'from_address', 'progress_interval_minutes']);
        $this->actingAs($admin)->put(route('admin.notifications.update'), $this->valid(['password_action' => 'REPLACE', 'smtp_password' => '']))
            ->assertSessionHasErrors('smtp_password');
        $this->assertDatabaseCount('notification_settings', 0);
    }

    public function test_changes_need_a_confirmed_password(): void
    {
        $this->be($this->admin());

        $this->put(route('admin.notifications.update'), $this->valid())->assertRedirect();
        $this->post(route('admin.notifications.test'))->assertRedirect();
        $this->put(route('admin.notifications.recipients.update'), ['recipients' => [$this->recipient('a@example.org')]])->assertRedirect();
        $this->assertDatabaseCount('notification_settings', 0);
        $this->assertDatabaseCount('notification_recipients', 0);
    }

    public function test_extra_recipients_are_validated_deduplicated_and_bounded(): void
    {
        $admin = $this->admin();
        $put = fn (array $recipients) => $this->actingAs($admin)->put(route('admin.notifications.recipients.update'), ['recipients' => $recipients]);

        $put([$this->recipient('no-es-correo'), $this->recipient('')])->assertSessionHasErrors(['recipients.0.email', 'recipients.1.email']);
        // The same mailbox written twice with different case is one address.
        $put([$this->recipient('Obrero@Example.org'), $this->recipient(' obrero@example.org ')])->assertSessionHasErrors('recipients.1.email');
        $put([['email' => 'a@example.org', 'interventions' => 'quizá', 'outcomes' => true, 'backups' => true, 'progress' => true]])
            ->assertSessionHasErrors('recipients.0.interventions');
        $put(array_map(fn (int $i) => $this->recipient('p'.$i.'@example.org'), range(1, 21)))->assertSessionHasErrors('recipients');
        $this->assertDatabaseCount('notification_recipients', 0);

        $put([$this->recipient('Obrero@Example.org'), $this->recipient('respaldos@example.org', ['interventions' => false, 'outcomes' => false, 'backups' => true])])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.notifications.edit'));
        $this->assertSame(['obrero@example.org', 'respaldos@example.org'], NotificationRecipient::query()->orderBy('id')->pluck('email')->all());
        $this->assertTrue(NotificationRecipient::query()->where('email', 'respaldos@example.org')->sole()->backups);
        $this->actingAs($admin)->get(route('admin.notifications.edit'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('maxRecipients', 20)->has('recipients', 2)
                ->where('recipients.0', ['email' => 'obrero@example.org', ...NotificationRecipient::DEFAULTS]));

        // Replacing the list keeps the rows that stay, updates their groups and drops the rest.
        $kept = NotificationRecipient::query()->where('email', 'obrero@example.org')->sole()->id;
        $put([$this->recipient('obrero@example.org', ['progress' => true]), $this->recipient('nuevo@example.org')])->assertSessionHasNoErrors();
        $this->assertSame($kept, NotificationRecipient::query()->where('email', 'obrero@example.org')->sole()->id);
        $this->assertTrue(NotificationRecipient::query()->find($kept)?->progress);
        $this->assertSame(['nuevo@example.org', 'obrero@example.org'], NotificationRecipient::query()->orderBy('email')->pluck('email')->all());
        $put([])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('notification_recipients', 0);
    }

    public function test_recipient_changes_are_audited_by_count_only(): void
    {
        $admin = $this->admin();
        $put = fn (array $recipients) => $this->actingAs($admin)->put(route('admin.notifications.recipients.update'), ['recipients' => $recipients]);

        $put([$this->recipient('obrero@example.org'), $this->recipient('respaldos@example.org')])->assertSessionHasNoErrors();
        $put([$this->recipient('obrero@example.org', ['backups' => true]), $this->recipient('nuevo@example.org')])->assertSessionHasNoErrors();

        $audits = AuditLog::query()->where('action', 'NOTIFICATION_RECIPIENTS_UPDATED')->orderBy('id')->get();
        $this->assertEquals([['count' => 2, 'added' => 2, 'removed' => 0, 'changed' => 0], ['count' => 2, 'added' => 1, 'removed' => 1, 'changed' => 1]],
            $audits->map(fn (AuditLog $audit) => $audit->payload)->all());
        $this->assertSame($admin->id, $audits[0]->actor_id);
        foreach (['obrero', 'respaldos', 'nuevo', 'example.org'] as $value) {
            $this->assertStringNotContainsString($value, (string) json_encode($audits->pluck('payload')));
        }
    }

    public function test_test_email_goes_to_a_typed_address_or_to_every_extra_recipient(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('admin.notifications.update'), $this->valid())->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('admin.notifications.test'), ['target' => 'ADDRESS', 'address' => 'no-es-correo'])->assertSessionHasErrors('address');
        $this->actingAs($admin)->post(route('admin.notifications.test'), ['target' => 'TODOS'])->assertSessionHasErrors('target');
        // Without extra recipients there is nobody to send to.
        $this->actingAs($admin)->post(route('admin.notifications.test'), ['target' => 'RECIPIENTS'])->assertRedirect(route('admin.notifications.edit'));
        Mail::assertNothingSent();

        $this->actingAs($admin)->post(route('admin.notifications.test'), ['target' => 'ADDRESS', 'address' => 'Obrero@Example.org'])
            ->assertSessionHas('notificationTest', fn (array $test): bool => $test['ok'] === true && str_contains((string) $test['message'], 'obrero@example.org'));
        Mail::assertSent(SmtpTestMessage::class, fn (SmtpTestMessage $mail): bool => $mail->hasTo('obrero@example.org') && ! $mail->hasTo($admin->email));

        NotificationRecipient::query()->create(['email' => 'uno@example.org', ...NotificationRecipient::DEFAULTS]);
        NotificationRecipient::query()->create(['email' => 'dos@example.org', ...NotificationRecipient::DEFAULTS]);
        $this->actingAs($admin)->post(route('admin.notifications.test'), ['target' => 'RECIPIENTS'])->assertSessionHas('notificationTest.ok', true);
        // One message per address, so no recipient sees the others.
        Mail::assertSent(SmtpTestMessage::class, fn (SmtpTestMessage $mail): bool => $mail->hasTo('uno@example.org') && ! $mail->hasTo('dos@example.org'));
        Mail::assertSent(SmtpTestMessage::class, fn (SmtpTestMessage $mail): bool => $mail->hasTo('dos@example.org') && ! $mail->hasTo('uno@example.org'));
        Mail::assertSentCount(3);
        Mail::assertNotSent(SmtpTestMessage::class, fn (SmtpTestMessage $mail): bool => $mail->hasTo($admin->email));

        $payloads = AuditLog::query()->where('action', 'NOTIFICATION_TEST_EMAIL')->orderBy('id')->pluck('payload')->all();
        $this->assertEquals([['outcome' => 'SENT', 'target' => 'ADDRESS', 'sent' => 1], ['outcome' => 'SENT', 'target' => 'RECIPIENTS', 'sent' => 2]], $payloads);
        $this->assertStringNotContainsString('example.org', (string) json_encode($payloads));
    }

    public function test_password_is_encrypted_never_returned_and_only_replaced_or_cleared(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.notifications.update'), $this->valid())->assertSessionHasNoErrors();
        $raw = (string) DB::table('notification_settings')->value('smtp_password');
        $this->assertNotSame(self::SECRET, $raw);
        $this->assertStringNotContainsString(self::SECRET, $raw);
        $this->assertSame(self::SECRET, NotificationSettings::current()?->smtp_password);
        $this->assertStringNotContainsString(self::SECRET, (string) NotificationSettings::current()?->toJson());

        $page = $this->actingAs($admin)->get(route('admin.notifications.edit'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('settings.password_configured', true)
                ->where('settings.smtp_host', 'smtp.example.edu')->missing('settings.smtp_password'));
        $this->assertStringNotContainsString(self::SECRET, (string) $page->getContent());

        // Keeping it leaves the stored value untouched, even if a value is sent along.
        $this->actingAs($admin)->put(route('admin.notifications.update'), $this->valid(['password_action' => 'KEEP', 'smtp_password' => 'ignored', 'smtp_port' => 465]))
            ->assertSessionHasNoErrors();
        $this->assertSame(self::SECRET, NotificationSettings::current()?->smtp_password);
        $this->assertSame(465, NotificationSettings::current()?->smtp_port);

        $this->actingAs($admin)->put(route('admin.notifications.update'), $this->valid(['password_action' => 'CLEAR']))->assertSessionHasNoErrors();
        $this->assertNull(NotificationSettings::current()?->smtp_password);
        $this->assertDatabaseCount('notification_settings', 1);
    }

    public function test_changes_are_audited_without_values(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.notifications.update'), $this->valid())->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('admin.notifications.update'), $this->valid(['password_action' => 'KEEP', 'from_name' => 'Avisos']))->assertSessionHasNoErrors();

        $audits = AuditLog::query()->where('action', 'NOTIFICATION_SETTINGS_UPDATED')->orderBy('id')->get();
        $this->assertCount(2, $audits);
        $this->assertSame($admin->id, $audits[0]->actor_id);
        $this->assertSame('REPLACED', $audits[0]->payload['password'] ?? null);
        $this->assertSame(['from_name'], $audits[1]->payload['changed_fields'] ?? null);
        $this->assertSame('KEPT', $audits[1]->payload['password'] ?? null);
        foreach ($audits as $audit) {
            $json = (string) json_encode($audit->payload);
            foreach ([self::SECRET, 'smtp.example.edu', 'avisos@example.edu', 'mailer-user'] as $value) {
                $this->assertStringNotContainsString($value, $json);
            }
        }
    }

    public function test_test_email_goes_to_the_admin_with_the_saved_mailer(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('admin.notifications.update'), $this->valid())->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('admin.notifications.test'))->assertRedirect(route('admin.notifications.edit'));

        Mail::assertSent(SmtpTestMessage::class, fn (SmtpTestMessage $mail): bool => $mail->hasTo($admin->email)
            && $mail->mailer === PlatformMailer::MAILER && $mail->hasFrom('avisos@example.edu', 'Avisos de la plataforma'));
        $this->assertSame('smtp.example.edu', config('mail.mailers.'.PlatformMailer::MAILER.'.host'));
        $this->assertSame(self::SECRET, config('mail.mailers.'.PlatformMailer::MAILER.'.password'));
        $this->assertEquals(['outcome' => 'SENT', 'target' => 'SELF', 'sent' => 1], AuditLog::query()->where('action', 'NOTIFICATION_TEST_EMAIL')->sole()->payload);
    }

    public function test_test_email_failure_is_explained_in_plain_spanish(): void
    {
        $admin = $this->admin();
        // Nothing listens on port 1: the connection is refused at once.
        $this->actingAs($admin)->put(route('admin.notifications.update'), $this->valid(['smtp_host' => '127.0.0.1', 'smtp_port' => 1, 'smtp_encryption' => 'NONE']))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('admin.notifications.test'))->assertRedirect()
            ->assertSessionHas('notificationTest', fn (array $test): bool => $test['ok'] === false
                && str_starts_with((string) $test['message'], 'No se pudo conectar con el servidor SMTP 127.0.0.1:1'));
        $this->assertSame('FAILED', AuditLog::query()->where('action', 'NOTIFICATION_TEST_EMAIL')->sole()->payload['outcome'] ?? null);
        $this->actingAs($admin)->get(route('admin.notifications.edit'))->assertOk();
    }

    public function test_transport_errors_are_translated_without_the_password(): void
    {
        $settings = new NotificationSettings(['smtp_host' => 'smtp.example.edu', 'smtp_port' => 587, 'smtp_encryption' => SmtpEncryption::STARTTLS,
            'smtp_password' => self::SECRET, 'from_address' => 'a@example.edu', 'from_name' => 'A']);
        $mailer = app(PlatformMailer::class);
        $cases = [
            'Failed to authenticate on SMTP server with username "u" using the following authenticators: "LOGIN". Authenticator "LOGIN" returned "Expected response code "235" but got code "535"' => 'rechazó el usuario o la contraseña',
            'Connection could not be established with host "smtp.example.edu:587": stream_socket_client(): php_network_getaddresses: getaddrinfo failed' => 'No se pudo conectar',
            'TLS required but neither TLS or STARTTLS are in use.' => 'no ofrece STARTTLS',
            'Connection to "smtp.example.edu:587" timed out.' => 'no respondió a tiempo',
            'Expected response code "250" but got code "553", with message "553 sender rejected"' => 'rechazó el remitente',
            'Unexpected reply '.self::SECRET => 'devolvió un error',
        ];
        foreach ($cases as $message => $expected) {
            $text = $mailer->describe(new RuntimeException($message), $settings);
            $this->assertStringContainsString($expected, $text);
            $this->assertStringNotContainsString(self::SECRET, $text);
        }
    }

    public function test_mailer_follows_the_encryption_and_falls_back_to_the_application_mailer(): void
    {
        $mailer = app(PlatformMailer::class);
        $this->assertNull($mailer->mailer());
        $settings = NotificationSettings::query()->create($this->stored(['smtp_encryption' => SmtpEncryption::TLS]));

        $this->assertSame(PlatformMailer::MAILER, $mailer->mailer());
        $this->assertSame('smtps', config('mail.mailers.'.PlatformMailer::MAILER.'.scheme'));
        $settings->update(['smtp_encryption' => SmtpEncryption::STARTTLS]);
        $this->assertTrue($mailer->configuration($settings->refresh())['require_tls']);
        $settings->update(['smtp_encryption' => SmtpEncryption::NONE]);
        $this->assertFalse($mailer->configuration($settings->refresh())['auto_tls']);
        $this->assertSame('smtp', $mailer->configuration($settings)['scheme']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
    }

    /**
     * @param  array<string, bool>  $groups
     * @return array<string, mixed>
     */
    private function recipient(string $email, array $groups = []): array
    {
        return ['email' => $email, ...NotificationRecipient::DEFAULTS, ...$groups];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function valid(array $overrides = []): array
    {
        return [
            'enabled' => true, 'smtp_host' => 'smtp.example.edu', 'smtp_port' => 587, 'smtp_encryption' => 'STARTTLS',
            'smtp_username' => 'mailer-user', 'password_action' => 'REPLACE', 'smtp_password' => self::SECRET,
            'from_address' => 'avisos@example.edu', 'from_name' => 'Avisos de la plataforma', 'progress_interval_minutes' => null,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function stored(array $overrides = []): array
    {
        return ['enabled' => true, 'smtp_host' => 'smtp.example.edu', 'smtp_port' => 587, 'smtp_encryption' => SmtpEncryption::STARTTLS,
            'smtp_username' => 'mailer-user', 'smtp_password' => self::SECRET, 'from_address' => 'avisos@example.edu', 'from_name' => 'Avisos', ...$overrides];
    }
}
