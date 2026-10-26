<?php

namespace App\Domain\Notifications;

use App\Enums\SmtpEncryption;
use App\Mail\SmtpTestMessage;
use App\Models\NotificationSettings;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The mailer the platform sends with. The SMTP configuration saved by an
 * administrator is turned into a mailer at send time; only when none exists does
 * the application's own MAIL_* configuration apply. Nothing of it reaches the servers.
 */
class PlatformMailer
{
    public const MAILER = 'platform-smtp';

    /** The mailer name to send with, freshly configured from the saved settings. */
    public function mailer(?NotificationSettings $settings = null): ?string
    {
        $settings ??= NotificationSettings::current();
        if ($settings === null) {
            return null;
        }
        config(['mail.mailers.'.self::MAILER => $this->configuration($settings)]);
        // A queue worker lives for hours: drop the previous transport so a change applies at once.
        $manager = Mail::getFacadeRoot();
        if ($manager instanceof MailManager) {
            $manager->purge(self::MAILER);
        }

        return self::MAILER;
    }

    /** @return array{address: string, name: string}|null */
    public function from(?NotificationSettings $settings = null): ?array
    {
        $settings ??= NotificationSettings::current();

        return $settings === null ? null : ['address' => $settings->from_address, 'name' => $settings->from_name];
    }

    /** @return array<string, mixed> */
    public function configuration(NotificationSettings $settings): array
    {
        return [
            'transport' => 'smtp',
            'scheme' => $settings->smtp_encryption === SmtpEncryption::TLS ? 'smtps' : 'smtp',
            'host' => $settings->smtp_host,
            'port' => $settings->smtp_port,
            'username' => $settings->smtp_username,
            'password' => $settings->smtp_password,
            'timeout' => 20,
            'auto_tls' => $settings->smtp_encryption !== SmtpEncryption::NONE,
            'require_tls' => $settings->smtp_encryption === SmtpEncryption::STARTTLS,
        ];
    }

    /**
     * Sends the test message with the saved settings, right away (not queued).
     * Returns null on success, or the reason in plain Spanish.
     */
    public function sendTest(NotificationSettings $settings, string $to): ?string
    {
        try {
            Mail::mailer($this->mailer($settings))->to($to)->send(new SmtpTestMessage($settings->from_address, $settings->from_name));

            return null;
        } catch (Throwable $exception) {
            return $this->describe($exception, $settings);
        }
    }

    /** Explains a transport failure without echoing the password or the raw exchange. */
    public function describe(Throwable $exception, NotificationSettings $settings): string
    {
        $raw = $exception->getMessage();
        $text = mb_strtolower($raw);
        $where = $settings->smtp_host.':'.$settings->smtp_port;

        return match (true) {
            str_contains($text, 'failed to authenticate') || preg_match('/\b(535|534|530)\b/', $raw) === 1 => 'El servidor SMTP rechazó el usuario o la contraseña.',
            str_contains($text, 'starttls') || str_contains($text, 'tls') && str_contains($text, 'not support') => 'El servidor SMTP no ofrece STARTTLS. Revise el tipo de cifrado o el puerto.',
            str_contains($text, 'ssl') || str_contains($text, 'certificate') || str_contains($text, 'crypto') => 'No se pudo establecer la conexión cifrada con '.$where.'. Revise el tipo de cifrado, el puerto y el certificado del servidor.',
            str_contains($text, 'timed out') || str_contains($text, 'timeout') => 'El servidor SMTP '.$where.' no respondió a tiempo.',
            str_contains($text, 'connection could not be established') || str_contains($text, 'refused') || str_contains($text, 'getaddrinfo')
                || str_contains($text, 'name or service not known') || str_contains($text, 'no such host') => 'No se pudo conectar con el servidor SMTP '.$where.'. Revise el host, el puerto y el acceso de red.',
            preg_match('/\b(550|551|553|554)\b/', $raw) === 1 => 'El servidor SMTP rechazó el remitente o el destinatario. Revise la dirección del remitente.',
            default => 'El servidor SMTP devolvió un error: '.$this->redacted($raw, $settings),
        };
    }

    private function redacted(string $message, NotificationSettings $settings): string
    {
        $line = trim((string) strtok($message, "\n"));
        if (is_string($settings->smtp_password) && $settings->smtp_password !== '') {
            $line = str_replace($settings->smtp_password, '[oculto]', $line);
        }

        return mb_substr($line, 0, 200);
    }
}
