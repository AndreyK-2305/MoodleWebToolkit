<?php

namespace Tests\Feature\Auth;

use App\Enums\ExecutionStatus;
use App\Enums\ExecutionStepStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\UserRole;
use App\Models\Execution;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Laravel\Fortify\Features;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ActionConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tracking_for_more_than_24_hours_does_not_renew_modification_permission(): void
    {
        Queue::fake();
        [$admin, $project, $execution] = $this->runningExecution();
        $confirmedAt = now()->timestamp;
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => $confirmedAt]);

        foreach (range(1, 13) as $_) {
            $this->travel(119)->minutes();
            $this->getJson(route('projects.executions.events', [$project->uuid, $execution->uuid]))->assertOk();
        }

        $this->assertSame($confirmedAt, session('auth.password_confirmed_at'));
        $this->postJson(
            route('projects.executions.cancel', [$project->uuid, $execution->uuid]),
            [],
            ['Idempotency-Key' => 'expired-cancel-0001'],
        )->assertStatus(423)->assertJson(['code' => 'PASSWORD_CONFIRMATION_REQUIRED']);
        $this->assertSame(ExecutionStatus::RUNNING, $execution->fresh()->status);
        $this->assertDatabaseCount('execution_commands', 0);
    }

    public function test_missing_full_authentication_evidence_blocks_a_direct_mutation(): void
    {
        Queue::fake();
        [$admin, $project, $execution] = $this->runningExecution();
        $this->app->make('auth')->guard('web')->setUser($admin);

        $this->postJson(
            route('projects.executions.cancel', [$project->uuid, $execution->uuid]),
            [],
            ['Idempotency-Key' => 'missing-confirmation-0001'],
        )->assertStatus(423);
        $this->assertSame(ExecutionStatus::RUNNING, $execution->fresh()->status);
    }

    #[DataProvider('rememberChoices')]
    public function test_full_login_initializes_the_standard_confirmation_timestamp(bool $remember): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => $remember,
        ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('auth.password_confirmed_at');
    }

    /** @return iterable<string, array{bool}> */
    public static function rememberChoices(): iterable
    {
        yield 'without remember' => [false];
        yield 'with remember' => [true];
    }

    public function test_remember_cookie_restores_read_only_access_without_renewing_modification_permission(): void
    {
        Queue::fake();
        [$admin, $project, $execution] = $this->runningExecution();

        $login = $this->post('/login', [
            'email' => $admin->email,
            'password' => 'password',
            'remember' => true,
        ])->assertRedirect(route('dashboard'));
        $recallerName = Auth::guard('web')->getRecallerName();
        $recaller = $login->getCookie($recallerName);
        $this->assertNotNull($recaller);

        $this->app['session']->flush();
        Auth::forgetGuards();

        $this->withCredentials()
            ->withCookie($recallerName, $recaller->getValue())
            ->getJson(route('projects.executions.events', [$project->uuid, $execution->uuid]))
            ->assertOk();
        $this->assertAuthenticatedAs($admin);
        $this->assertTrue(Auth::guard('web')->viaRemember());
        $this->assertNull(session('auth.password_confirmed_at'));

        $this->postJson(
            route('projects.executions.cancel', [$project->uuid, $execution->uuid]),
            [],
            ['Idempotency-Key' => 'remembered-cancel-0001'],
        )->assertStatus(423);
        $this->assertSame(ExecutionStatus::RUNNING, $execution->fresh()->status);

        $this->postJson(route('action-password.confirm'), ['password' => 'incorrect'])
            ->assertUnprocessable();
        $this->assertNull(session('auth.password_confirmed_at'));

        $this->postJson(route('action-password.confirm'), ['password' => 'password'])
            ->assertOk();
        $this->assertIsInt(session('auth.password_confirmed_at'));
    }

    public function test_two_factor_challenge_does_not_confirm_the_password_until_it_succeeds(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);
        $user = User::factory()->withTwoFactor()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ])->assertRedirect(route('two-factor.login'))
            ->assertSessionMissing('auth.password_confirmed_at');
        $this->assertGuest();

        $this->post(route('two-factor.login.store'), [
            'recovery_code' => 'recovery-code-1',
        ])->assertRedirect(route('dashboard'))
            ->assertSessionHas('auth.password_confirmed_at');
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_keeps_lock_and_correct_password_renews_it(): void
    {
        Queue::fake();
        [$admin, $project, $execution] = $this->runningExecution();
        $expired = now()->subMinutes(121)->timestamp;
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => $expired]);

        $this->postJson(route('action-password.confirm'), ['password' => 'incorrect'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
        $this->assertSame($expired, session('auth.password_confirmed_at'));

        $confirmation = $this->postJson(route('action-password.confirm'), ['password' => 'password'])
            ->assertOk();
        $this->assertGreaterThan($expired, $confirmation->json('confirmed_at'));

        $this->postJson(
            route('projects.executions.cancel', [$project->uuid, $execution->uuid]),
            [],
            ['Idempotency-Key' => 'confirmed-cancel-0001'],
        )->assertAccepted()->assertJson(['status' => 'CANCELLING']);
        $this->assertSame(ExecutionStatus::CANCELLING, $execution->fresh()->status);
    }

    public function test_wrong_password_confirmation_is_rate_limited(): void
    {
        [$admin] = $this->runningExecution();
        $this->actingAs($admin)->withSession([
            'auth.password_confirmed_at' => now()->subMinutes(121)->timestamp,
        ]);

        foreach (range(1, 5) as $_) {
            $this->postJson(route('action-password.confirm'), ['password' => 'incorrect'])
                ->assertUnprocessable();
        }

        $this->postJson(route('action-password.confirm'), ['password' => 'incorrect'])
            ->assertTooManyRequests();
    }

    public function test_confirmation_does_not_grant_an_auditor_control_permission(): void
    {
        Queue::fake();
        [$admin, $project, $execution] = $this->runningExecution();
        $auditor = User::factory()->create(['role' => UserRole::AUDITOR, 'is_active' => true]);
        $project->assignments()->create(['user_id' => $auditor->getKey(), 'assigned_by' => $admin->getKey()]);

        $this->actingAs($auditor)
            ->withSession(['auth.password_confirmed_at' => now()->subMinutes(121)->timestamp])
            ->postJson(route('action-password.confirm'), ['password' => 'password'])
            ->assertOk();
        $this->postJson(
            route('projects.executions.cancel', [$project->uuid, $execution->uuid]),
            [],
            ['Idempotency-Key' => 'auditor-cancel-0001'],
        )->assertForbidden();
        $this->assertSame(ExecutionStatus::RUNNING, $execution->fresh()->status);
    }

    public function test_confirmation_does_not_grant_an_unassigned_operator_control_permission(): void
    {
        Queue::fake();
        [, $project, $execution] = $this->runningExecution();
        $operator = User::factory()->create(['role' => UserRole::OPERATOR, 'is_active' => true]);

        $this->actingAs($operator)
            ->withSession(['auth.password_confirmed_at' => now()->subMinutes(121)->timestamp])
            ->postJson(route('action-password.confirm'), ['password' => 'password'])
            ->assertOk();
        $this->postJson(
            route('projects.executions.cancel', [$project->uuid, $execution->uuid]),
            [],
            ['Idempotency-Key' => 'unassigned-cancel-0001'],
        )->assertForbidden();
        $this->assertSame(ExecutionStatus::RUNNING, $execution->fresh()->status);
        $this->assertDatabaseCount('execution_commands', 0);
    }

    public function test_pending_action_is_revalidated_after_password_confirmation(): void
    {
        Queue::fake();
        [$admin, $project, $execution] = $this->runningExecution();
        $this->actingAs($admin)->withSession([
            'auth.password_confirmed_at' => now()->subMinutes(121)->timestamp,
        ]);

        $this->postJson(
            route('projects.executions.cancel', [$project->uuid, $execution->uuid]),
            [],
            ['Idempotency-Key' => 'stale-cancel-0001'],
        )->assertStatus(423);

        Execution::withoutEvents(fn () => $execution
            ->forceFill(['status' => ExecutionStatus::CANCELLED, 'finished_at' => now()])
            ->save());
        Project::withoutEvents(fn () => $project
            ->forceFill(['status' => ProjectStatus::CANCELLED])
            ->save());

        $this->postJson(route('action-password.confirm'), ['password' => 'password'])->assertOk();
        $this->postJson(
            route('projects.executions.cancel', [$project->uuid, $execution->uuid]),
            [],
            ['Idempotency-Key' => 'stale-cancel-0001'],
        )->assertUnprocessable();
        $this->assertDatabaseCount('execution_commands', 0);
    }

    public function test_logout_and_loss_of_authenticated_access_do_not_change_execution(): void
    {
        [$admin, $project, $execution] = $this->runningExecution();
        $this->actingAs($admin)->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->getJson(route('projects.executions.events', [$project->uuid, $execution->uuid]))->assertUnauthorized();
        $this->assertSame(ExecutionStatus::RUNNING, $execution->fresh()->status);
        $this->assertSame(ProjectStatus::RUNNING, $project->fresh()->status);
    }

    public function test_reauthentication_recovers_event_catch_up_without_changing_execution(): void
    {
        [$admin, $project, $execution] = $this->runningExecution();
        $execution->events()->create([
            'sequence' => 1,
            'type' => 'phase.progress',
            'severity' => 'INFO',
            'progress' => 25,
            'message' => 'Evidencia persistida antes de perder la sesión.',
            'payload' => [],
            'occurred_at' => now(),
        ]);

        $this->actingAs($admin)->post('/logout')->assertRedirect('/');
        $this->getJson(route('projects.executions.events', [$project->uuid, $execution->uuid]))
            ->assertUnauthorized();

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
        $this->getJson(route('projects.executions.events', [$project->uuid, $execution->uuid]))
            ->assertOk()
            ->assertJsonPath('events.0.sequence', 1)
            ->assertJsonPath('execution.status', ExecutionStatus::RUNNING->value);
        $this->assertSame(ProjectStatus::RUNNING, $project->fresh()->status);
    }

    /** @return array{User, Project, Execution} */
    private function runningExecution(): array
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN, 'is_active' => true]);
        $project = Project::query()->create([
            'name' => 'Seguimiento 1E',
            'type' => ProjectType::COLLECT,
            'status' => ProjectStatus::RUNNING,
            'created_by' => $admin->getKey(),
        ]);
        $execution = Execution::query()->create([
            'project_id' => $project->getKey(),
            'attempt' => 1,
            'status' => ExecutionStatus::RUNNING,
            'progress' => 25,
            'created_by' => $admin->getKey(),
            'started_at' => now(),
        ]);
        $execution->steps()->create([
            'step_key' => 'operation',
            'attempt' => 1,
            'name' => 'Procesamiento simulado',
            'position' => 1,
            'status' => ExecutionStepStatus::RUNNING,
            'progress' => 25,
            'started_at' => now(),
        ]);

        return [$admin, $project, $execution];
    }
}
