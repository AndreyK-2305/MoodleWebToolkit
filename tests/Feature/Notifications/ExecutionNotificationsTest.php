<?php

namespace Tests\Feature\Notifications;

use App\Domain\Executions\ExecutionEventRecorder;
use App\Domain\Notifications\ExecutionNotifications;
use App\Domain\Notifications\PlatformMailer;
use App\Enums\EventSeverity;
use App\Enums\ExecutionStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\SmtpEncryption;
use App\Enums\UserRole;
use App\Models\Execution;
use App\Models\ExecutionNotification;
use App\Models\NotificationSettings;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\ExecutionNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Domain\DomainFixtures;
use Tests\TestCase;

class ExecutionNotificationsTest extends TestCase
{
    use DomainFixtures, RefreshDatabase;

    private User $admin;

    private User $operator;

    private User $optedOut;

    private User $outsider;

    private User $inactiveAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        NotificationSettings::query()->create(['enabled' => true, 'smtp_host' => 'smtp.example.edu', 'smtp_port' => 587,
            'smtp_encryption' => SmtpEncryption::STARTTLS, 'smtp_username' => 'mailer', 'smtp_password' => 'smtp-secret',
            'from_address' => 'avisos@example.edu', 'from_name' => 'Avisos']);
        $this->admin = $this->user(UserRole::ADMIN);
        $this->operator = $this->user();
        $this->optedOut = $this->user(UserRole::AUDITOR);
        $this->outsider = $this->user();
        $this->inactiveAdmin = $this->user(UserRole::ADMIN, false);
        UserNotificationPreference::query()->create(['user_id' => $this->optedOut->id, 'receive_email' => false]);
    }

    /**
     * @return iterable<string, array{0: ProjectType, 1: string, 2: string|null, 3: array<string, mixed>, 4: string}>
     */
    public static function notifiedEvents(): iterable
    {
        yield 'intervention' => [ProjectType::CONSOLIDATE, 'consolidator.intervention_opened', '02-oauth2',
            ['conflict_key' => 'consolidator.02-oauth2.7', 'conflict_type' => 'OAUTH2_CONFIGURATION'], 'intervention_waiting'];
        yield 'source collected' => [ProjectType::COLLECT, 'collector.package_registered', 'verification',
            ['source_package_uuid' => '7f1c0c4e-8d0a-4c55-9d0e-2b8b4b4b0001'], 'collection_source_completed'];
        yield 'collection failed' => [ProjectType::COLLECT, 'collector.failed', 'collection', [], 'collection_failed'];
        yield 'consolidation review' => [ProjectType::CONSOLIDATE, 'consolidator.closed', 'finalization',
            ['closure' => ['total_courses_verified' => 12, 'failed_courses' => 0]], 'consolidation_review'];
        yield 'consolidation completed' => [ProjectType::CONSOLIDATE, 'execution.completed', 'finalization', [], 'consolidation_completed'];
        yield 'consolidation failed' => [ProjectType::CONSOLIDATE, 'consolidator.failed', null, [], 'consolidation_failed'];
        yield 'backup verified' => [ProjectType::CONSOLIDATE, 'consolidator.backup_registered', 'finalization', ['sha256' => str_repeat('a', 64)], 'backup_verified'];
        yield 'backup failed' => [ProjectType::CONSOLIDATE, 'consolidator.backup_failed', 'finalization', [], 'backup_failed'];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('notifiedEvents')]
    public function test_each_event_sends_exactly_one_email_to_each_opted_in_member(ProjectType $type, string $event, ?string $step, array $payload, string $kind): void
    {
        $execution = $this->execution($this->memberProject($type));

        $this->record($execution, $event, $step, $payload);
        $this->artisan('notifications:dispatch')->assertSuccessful();

        $row = ExecutionNotification::query()->sole();
        $this->assertSame($kind, $row->kind);
        $this->assertSame('SENT', $row->outcome);
        $this->assertSame(2, $row->recipients);
        Notification::assertSentToTimes($this->admin, ExecutionNotice::class, 1);
        Notification::assertSentToTimes($this->operator, ExecutionNotice::class, 1);
        Notification::assertNotSentTo([$this->optedOut, $this->outsider, $this->inactiveAdmin], ExecutionNotice::class);
    }

    public function test_events_that_are_not_notified_open_nothing(): void
    {
        $execution = $this->execution($this->memberProject());
        foreach (['consolidator.progress', 'consolidator.resumed', 'collector.export_completed', 'consolidator.cancelled', 'consolidator.backup_requested'] as $event) {
            $this->record($execution, $event);
        }
        $this->record($this->execution($this->memberProject(ProjectType::COLLECT)), 'execution.completed');

        $this->artisan('notifications:dispatch')->assertSuccessful();
        $this->assertDatabaseCount('execution_notifications', 0);
        Notification::assertNothingSent();
    }

    public function test_replays_and_repeated_dispatches_send_nothing_twice(): void
    {
        $execution = $this->execution($this->memberProject());
        $intervention = ['conflict_key' => 'consolidator.03-plugins.9', 'conflict_type' => 'PLUGIN_INTERVENTION'];

        $this->record($execution, 'consolidator.intervention_opened', '03-plugins', $intervention);
        $this->artisan('notifications:dispatch')->assertSuccessful();
        // A replayed observation records the same moment again, and the scheduler passes again.
        $this->record($execution, 'consolidator.intervention_opened', '03-plugins', $intervention);
        $this->artisan('notifications:dispatch')->assertSuccessful();
        app(ExecutionNotifications::class)->dispatch(ExecutionNotification::query()->sole()->id);
        // A failure closed twice (worker and reconciler) is still one outcome.
        $this->record($execution, 'consolidator.failed');
        $this->record($execution, 'tool.failed');
        $this->artisan('notifications:dispatch')->assertSuccessful();
        $this->artisan('notifications:dispatch')->assertSuccessful();

        $this->assertSame(['intervention.consolidator.03-plugins.9', 'outcome.failed'],
            ExecutionNotification::query()->orderBy('id')->pluck('dedupe_key')->all());
        $this->assertDatabaseCount('execution_notification_deliveries', 4);
        Notification::assertSentToTimes($this->admin, ExecutionNotice::class, 2);
        Notification::assertSentToTimes($this->operator, ExecutionNotice::class, 2);
        // A reopened intervention is a new stop with its own key, so it is announced again.
        $this->record($execution, 'consolidator.intervention_opened', '03-plugins', [...$intervention, 'conflict_key' => 'consolidator.03-plugins.9.r1']);
        $this->artisan('notifications:dispatch')->assertSuccessful();
        Notification::assertSentToTimes($this->operator, ExecutionNotice::class, 3);
    }

    public function test_preferences_choose_the_groups_each_member_receives(): void
    {
        UserNotificationPreference::query()->create(['user_id' => $this->operator->id, 'receive_email' => true,
            'interventions' => true, 'outcomes' => false, 'backups' => false, 'progress' => false]);
        $execution = $this->execution($this->memberProject());

        $this->record($execution, 'consolidator.intervention_opened', '05b-readiness', ['conflict_key' => 'k1', 'conflict_type' => 'THEME_SELECTION']);
        $this->record($execution, 'consolidator.backup_failed', 'finalization');
        $this->record($execution, 'consolidator.failed');
        $this->artisan('notifications:dispatch')->assertSuccessful();

        Notification::assertSentToTimes($this->operator, ExecutionNotice::class, 1);
        Notification::assertSentTo($this->operator, ExecutionNotice::class,
            fn (ExecutionNotice $notice): bool => ExecutionNotification::query()->findOrFail($notice->notificationId)->kind === 'intervention_waiting');
        Notification::assertSentToTimes($this->admin, ExecutionNotice::class, 3);
        Notification::assertNotSentTo($this->optedOut, ExecutionNotice::class);
    }

    public function test_disabled_or_stale_notifications_are_closed_without_sending(): void
    {
        $execution = $this->execution($this->memberProject());
        $this->record($execution, 'consolidator.closed', 'finalization');
        $this->travel(13)->hours();
        $this->record($execution, 'consolidator.backup_registered', 'finalization', ['sha256' => str_repeat('b', 64)]);
        $this->artisan('notifications:dispatch')->assertSuccessful();
        NotificationSettings::query()->update(['enabled' => false]);
        $this->record($execution, 'execution.completed', 'finalization');
        $this->artisan('notifications:dispatch')->assertSuccessful();

        $this->assertSame(['outcome.review' => 'STALE', 'backup.verified.'.str_repeat('b', 64) => 'SENT', 'outcome.completed' => 'DISABLED'],
            ExecutionNotification::query()->orderBy('id')->pluck('outcome', 'dedupe_key')->all());
        Notification::assertSentToTimes($this->operator, ExecutionNotice::class, 1);
    }

    public function test_progress_summary_is_off_by_default_and_sent_once_per_interval(): void
    {
        $execution = $this->execution($this->memberProject(), ExecutionStatus::RUNNING);
        $execution->forceFill(['started_at' => now()->subMinutes(65), 'progress' => 40])->save();
        UserNotificationPreference::query()->create(['user_id' => $this->operator->id, 'progress' => true]);

        $this->artisan('notifications:dispatch')->assertSuccessful();
        $this->assertDatabaseCount('execution_notifications', 0);

        NotificationSettings::query()->update(['progress_interval_minutes' => 30]);
        $this->artisan('notifications:dispatch')->assertSuccessful();
        $this->artisan('notifications:dispatch')->assertSuccessful();
        $this->travel(30)->minutes();
        $this->artisan('notifications:dispatch')->assertSuccessful();

        $rows = ExecutionNotification::query()->orderBy('id')->get();
        $this->assertSame(['progress.2', 'progress.3'], $rows->pluck('dedupe_key')->all());
        $this->assertStringContainsString('40 %', $rows[0]->line);
        // Only the member who asked for it; the admin keeps the default (off).
        Notification::assertSentToTimes($this->operator, ExecutionNotice::class, 2);
        Notification::assertNotSentTo($this->admin, ExecutionNotice::class);
    }

    public function test_email_carries_project_event_line_and_link_without_private_details(): void
    {
        $project = $this->memberProject();
        $execution = $this->execution($project);
        app(ExecutionEventRecorder::class)->record($execution, 'consolidator.failed', severity: EventSeverity::WARNING,
            message: 'Falló /var/lib/moodle-toolkit/secret-run con la clave AKIAEXAMPLE para profesor@example.edu');
        $this->artisan('notifications:dispatch')->assertSuccessful();

        $mail = (new ExecutionNotice(ExecutionNotification::query()->sole()->id))->toMail($this->operator);
        $this->assertSame('[Migración de prueba] La consolidación falló', $mail->subject);
        $this->assertSame(PlatformMailer::MAILER, $mail->mailer);
        $this->assertSame(['avisos@example.edu', 'Avisos'], $mail->from);
        $this->assertSame(route('projects.executions.show', ['project' => $project->uuid, 'execution' => $execution->uuid]), $mail->actionUrl);
        $text = implode("\n", [...$mail->introLines, ...$mail->outroLines]);
        $this->assertStringContainsString('Proyecto: Migración de prueba', $text);
        foreach (['/var/lib', 'AKIAEXAMPLE', 'profesor@example.edu', $this->operator->email, $this->operator->name, 'smtp-secret'] as $private) {
            $this->assertStringNotContainsString($private, $text.$mail->subject);
        }
    }

    public function test_without_saved_settings_the_application_mailer_is_used(): void
    {
        NotificationSettings::query()->delete();
        $execution = $this->execution($this->memberProject());
        $this->record($execution, 'consolidator.closed', 'finalization');
        $this->artisan('notifications:dispatch')->assertSuccessful();

        $row = ExecutionNotification::query()->sole();
        $this->assertSame('SENT', $row->outcome);
        $mail = (new ExecutionNotice($row->id))->toMail($this->operator);
        $this->assertNull($mail->mailer);
        $this->assertSame([], $mail->from);
    }

    public function test_users_manage_their_own_preferences(): void
    {
        $choices = ['receive_email' => true, 'interventions' => false, 'outcomes' => true, 'backups' => false, 'progress' => true];
        // Without a confirmed password nothing changes.
        $this->be($this->outsider);
        $this->put(route('notifications.update'), $choices)->assertRedirect();
        $this->assertSame(UserNotificationPreference::DEFAULTS, UserNotificationPreference::for($this->outsider));

        $this->actingAs($this->operator)->get(route('notifications.edit'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('settings/notifications')
                ->where('preferences', UserNotificationPreference::DEFAULTS));

        $this->actingAs($this->operator)->put(route('notifications.update'), $choices)->assertRedirect(route('notifications.edit'));
        $this->assertSame($choices, UserNotificationPreference::for($this->operator));
        $this->actingAs($this->operator)->put(route('notifications.update'), ['receive_email' => 'quizás'])
            ->assertSessionHasErrors(['receive_email', 'interventions']);
        $this->assertSame(1, UserNotificationPreference::query()->where('user_id', $this->operator->id)->count());
    }

    private function memberProject(ProjectType $type = ProjectType::CONSOLIDATE): Project
    {
        $project = Project::query()->create(['name' => 'Migración de prueba', 'type' => $type, 'status' => ProjectStatus::DRAFT,
            'created_by' => $this->admin->id]);
        foreach ([$this->operator, $this->optedOut] as $member) {
            ProjectAssignment::query()->create(['project_id' => $project->id, 'user_id' => $member->id, 'assigned_by' => $this->admin->id]);
        }

        return $project;
    }

    /** @param array<string, mixed> $payload */
    private function record(Execution $execution, string $type, ?string $step = null, array $payload = []): void
    {
        app(ExecutionEventRecorder::class)->record($execution, $type, $step, EventSeverity::INFO, payload: $payload === [] ? null : $payload);
    }
}
