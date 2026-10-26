<?php

namespace App\Domain\Notifications;

use App\Domain\Remote\RemoteConsolidationInterventions;
use App\Domain\Remote\RemoteConsolidationPreparation;
use App\Enums\ExecutionStatus;
use App\Enums\NotificationGroup;
use App\Enums\ProjectType;
use App\Enums\UserRole;
use App\Models\Execution;
use App\Models\ExecutionEvent;
use App\Models\ExecutionNotification;
use App\Models\NotificationSettings;
use App\Models\SourcePackage;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\ExecutionNotice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Email notifications of an Execution, as a transactional outbox.
 *
 * The event recorder captures a notifiable event in its own commit, keyed by a
 * stable event key (the conflict key, the source package, the backup digest or
 * the outcome itself), so a retried worker or a replayed observation writes
 * nothing new. The scheduler dispatches each row once: one delivery per user,
 * sent as a queued notification after the commit.
 */
class ExecutionNotifications
{
    /** A row nobody dispatched for this long is closed without sending: it is no longer news. */
    public const STALE_AFTER_HOURS = 12;

    /** Statuses that count as running for the progress summary. */
    private const RUNNING = [ExecutionStatus::RUNNING, ExecutionStatus::VERIFYING];

    private const FAILURES = ['collector.failed', 'consolidator.failed', 'tool.failed', 'execution.abandoned'];

    /** Event types that may notify; any other event costs nothing more than this lookup. */
    private const NOTIFIED = [...self::FAILURES, 'consolidator.intervention_opened', 'collector.package_registered',
        'consolidator.closed', 'execution.completed', 'consolidator.backup_registered', 'consolidator.backup_failed'];

    /** Called inside the recorder's transaction, right after the event is written. */
    public function capture(Execution $execution, ExecutionEvent $event): void
    {
        $notice = $this->classify($execution, $event);
        if ($notice === null) {
            return;
        }
        ExecutionNotification::query()->insertOrIgnore([
            'execution_id' => $execution->getKey(), 'execution_event_id' => $event->getKey(), ...$notice,
            'created_at' => now()->utc(),
        ]);
    }

    /**
     * What an event means for the people following the project, or null when it is
     * not one of the notified moments. The line is written here, not copied from the
     * event, so a reason with technical detail never reaches an inbox.
     *
     * @return array{dedupe_key: string, group: string, kind: string, title: string, line: string}|null
     */
    public function classify(Execution $execution, ExecutionEvent $event): ?array
    {
        if (! in_array($event->type, self::NOTIFIED, true)) {
            return null;
        }
        $payload = $event->payload ?? [];
        $collect = $execution->project->type === ProjectType::COLLECT;
        $work = $collect ? 'recolección' : 'consolidación';

        return match (true) {
            $event->type === 'consolidator.intervention_opened' && is_string($payload['conflict_key'] ?? null) => $this->notice(
                'intervention.'.$payload['conflict_key'], NotificationGroup::INTERVENTIONS, 'intervention_waiting',
                'Intervención pendiente: '.$this->interventionTitle($payload['conflict_type'] ?? null),
                'El Consolidador está detenido en «'.$this->stageName($event->step_key).'» y espera una decisión en la plataforma: '
                    .$this->interventionTitle($payload['conflict_type'] ?? null).'.'),
            $event->type === 'collector.package_registered' && is_string($payload['source_package_uuid'] ?? null) => $this->notice(
                'collection.package.'.$payload['source_package_uuid'], NotificationGroup::OUTCOMES, 'collection_source_completed',
                'Recolección completada: '.$this->packageName($payload['source_package_uuid']),
                'El paquete del origen «'.$this->packageName($payload['source_package_uuid']).'» quedó verificado y disponible para revisión.'),
            in_array($event->type, self::FAILURES, true) => $this->notice(
                'outcome.failed', NotificationGroup::OUTCOMES, $collect ? 'collection_failed' : 'consolidation_failed',
                'La '.$work.' falló',
                'La '.$work.' terminó sin completarse. Su evidencia se conserva y el detalle está en la página de la ejecución.'),
            $event->type === 'consolidator.closed' => $this->notice(
                'outcome.review', NotificationGroup::OUTCOMES, 'consolidation_review', 'Consolidación lista para revisión',
                'La consolidación quedó verificada y cerrada por el Consolidador'.$this->closureSummary($payload['closure'] ?? null)
                    .'. Revise el resultado y finalícelo en la plataforma.'),
            $event->type === 'execution.completed' && ! $collect => $this->notice(
                'outcome.completed', NotificationGroup::OUTCOMES, 'consolidation_completed', 'Consolidación completada',
                'La consolidación fue finalizada y sus artefactos quedaron verificados.'),
            $event->type === 'consolidator.backup_registered' => $this->notice(
                'backup.verified.'.(is_string($payload['sha256'] ?? null) ? $payload['sha256'] : $event->sequence), NotificationGroup::BACKUPS,
                'backup_verified', 'Respaldo verificado en S3', 'La copia integral del destino quedó respaldada y verificada en el almacenamiento de la plataforma.'),
            $event->type === 'consolidator.backup_failed' => $this->notice(
                'backup.failed.'.($event->remote_operation_id ?? 'e'.$event->sequence), NotificationGroup::BACKUPS, 'backup_failed',
                'El respaldo en S3 falló', 'El respaldo de la copia integral no terminó; puede intentarse de nuevo desde la página de la ejecución.'),
            default => null,
        };
    }

    /**
     * Opens a progress summary for each running Execution once per interval of its run.
     * Rows are keyed by the interval number, so a late or repeated pass sends nothing twice.
     */
    public function captureProgress(): int
    {
        $settings = NotificationSettings::current();
        $interval = $settings?->enabled === true ? (int) $settings->progress_interval_minutes : 0;
        if ($interval <= 0) {
            return 0;
        }
        $captured = 0;
        $executions = Execution::query()->with('project')->whereIn('status', array_map(fn (ExecutionStatus $status): string => $status->value, self::RUNNING))
            ->whereNotNull('started_at')->limit(200)->get();
        foreach ($executions as $execution) {
            $bucket = intdiv((int) $execution->started_at?->diffInMinutes(now()), $interval);
            if ($bucket < 1) {
                continue;
            }
            $step = $execution->steps()->where('status', 'RUNNING')->orderByDesc('position')->value('name');
            $captured += ExecutionNotification::query()->insertOrIgnore([
                'execution_id' => $execution->getKey(), 'execution_event_id' => null,
                ...$this->notice('progress.'.$bucket, NotificationGroup::PROGRESS, 'progress_summary', 'Resumen de avance',
                    'La ejecución sigue en curso'.($execution->progress !== null ? ' con un avance del '.$execution->progress.' %' : '')
                        .(is_string($step) ? '; etapa actual: «'.mb_substr($step, 0, 120).'»' : '').'.'),
                'created_at' => now()->utc(),
            ]);
        }

        return $captured;
    }

    /** One bounded pass over the outbox; returns the rows it closed. */
    public function dispatchPending(int $limit = 50): int
    {
        $ids = ExecutionNotification::query()->whereNull('dispatched_at')->orderBy('id')->limit(min(500, max(1, $limit)))->pluck('id');
        foreach ($ids as $id) {
            $this->dispatch((int) $id);
        }

        return $ids->count();
    }

    public function dispatch(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $row = ExecutionNotification::query()->with('execution.project')->lockForUpdate()->find($id);
            if ($row === null || $row->dispatched_at !== null) {
                return;
            }
            $settings = NotificationSettings::current();
            $outcome = match (true) {
                $settings !== null && ! $settings->enabled => 'DISABLED',
                $row->created_at->lt(now()->subHours(self::STALE_AFTER_HOURS)) => 'STALE',
                default => 'SENT',
            };
            $sent = 0;
            if ($outcome === 'SENT') {
                foreach ($this->recipients($row->execution, $row->group) as $user) {
                    $inserted = DB::table('execution_notification_deliveries')->insertOrIgnore([
                        'execution_notification_id' => $row->getKey(), 'user_id' => $user->getKey(), 'created_at' => now()->utc(),
                    ]);
                    if ($inserted === 1) {
                        // Queued after the commit: a rolled-back pass leaves nothing in the queue.
                        $user->notify(new ExecutionNotice($row->getKey()));
                        $sent++;
                    }
                }
            }
            $row->forceFill(['dispatched_at' => now()->utc(), 'recipients' => $sent, 'outcome' => $outcome])->save();
        }, attempts: 3);
    }

    /**
     * Who hears about a project: the active people who can open it (administrators and
     * assigned users) and opted in to the group. Without a preference row the defaults apply.
     *
     * @return Collection<int, User>
     */
    public function recipients(Execution $execution, NotificationGroup $group): Collection
    {
        $column = $group->preference();
        $default = UserNotificationPreference::defaultFor($group);

        return User::query()->where('is_active', true)
            ->where(fn (Builder $query) => $query->where('role', UserRole::ADMIN->value)
                ->orWhereHas('projectAssignments', fn (Builder $assigned) => $assigned->where('project_id', $execution->project_id)))
            ->where(function (Builder $query) use ($column, $default): void {
                $query->whereHas('notificationPreference', fn (Builder $preference) => $preference->where('receive_email', true)->where($column, true));
                if ($default) {
                    $query->orWhereDoesntHave('notificationPreference');
                }
            })
            ->orderBy('id')->get();
    }

    /** @return array{dedupe_key: string, group: string, kind: string, title: string, line: string} */
    private function notice(string $key, NotificationGroup $group, string $kind, string $title, string $line): array
    {
        return ['dedupe_key' => mb_substr($key, 0, 191), 'group' => $group->value, 'kind' => $kind,
            'title' => mb_substr($title, 0, 191), 'line' => mb_substr($line, 0, 500)];
    }

    private function interventionTitle(mixed $type): string
    {
        $title = is_string($type) ? (RemoteConsolidationInterventions::TITLES[$type] ?? null) : null;

        return $title ?? RemoteConsolidationInterventions::TITLES[RemoteConsolidationInterventions::GENERIC];
    }

    private function stageName(?string $stage): string
    {
        return $stage !== null ? (RemoteConsolidationPreparation::STAGES[$stage] ?? 'una etapa del Consolidador') : 'una etapa del Consolidador';
    }

    private function packageName(string $uuid): string
    {
        return mb_substr((string) (SourcePackage::query()->where('uuid', $uuid)->value('name') ?? 'origen'), 0, 120);
    }

    private function closureSummary(mixed $closure): string
    {
        if (! is_array($closure)) {
            return '';
        }
        $verified = $closure['total_courses_verified'] ?? $closure['courses_verified'] ?? null;
        $failed = $closure['failed_courses'] ?? null;

        return is_int($verified) ? ': '.$verified.' cursos verificados'.(is_int($failed) ? ' y '.$failed.' diferencias' : '') : '';
    }
}
