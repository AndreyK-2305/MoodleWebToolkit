<?php

namespace App\Notifications;

use App\Domain\Notifications\PlatformMailer;
use App\Models\ExecutionNotification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email for one notified moment of an Execution: the project, the event, one
 * plain line and the link to the execution page. It carries only the outbox id,
 * so the queue payload holds no text, and builds the mailer when it is sent.
 */
class ExecutionNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /** An SMTP server that is briefly unavailable gets two more chances. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $notificationId)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(User|AnonymousNotifiable $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User|AnonymousNotifiable $notifiable): MailMessage
    {
        $row = ExecutionNotification::query()->with('execution.project')->findOrFail($this->notificationId);
        $execution = $row->execution;
        $project = $execution->project;
        $platform = app(PlatformMailer::class);
        $message = (new MailMessage)
            ->subject('['.$project->name.'] '.$row->title)
            ->greeting($row->title)
            ->line('Proyecto: '.$project->name)
            ->line($row->line)
            ->action('Abrir la ejecución', route('projects.executions.show', ['project' => $project->uuid, 'execution' => $execution->uuid]))
            ->line($notifiable instanceof User
                ? 'Recibe este correo porque participa en el proyecto. Puede elegir qué avisos recibir en Configuración → Notificaciones.'
                : 'Recibe este correo porque un administrador de la plataforma lo designó como destinatario de estos avisos.')
            ->salutation('MoodleWebToolkit');
        $mailer = $platform->mailer();
        if ($mailer !== null) {
            $from = $platform->from();
            $message->mailer($mailer);
            if ($from !== null) {
                $message->from($from['address'], $from['name']);
            }
        }

        return $message;
    }

    /**
     * The id is in the queue; the delivery row was written before it, so a retried
     * job sends the same email and a replayed event sends none.
     *
     * @return array<string, mixed>
     */
    public function toArray(User|AnonymousNotifiable $notifiable): array
    {
        return ['execution_notification_id' => $this->notificationId];
    }
}
