<?php

namespace Tests\Feature\Auth;

use App\Domain\Executions\Contracts\ExecutionProvider;
use App\Domain\Executions\StartProjectExecution;
use App\Domain\Tools\Contracts\ToolAdapter;
use App\Enums\ExecutionCommandType;
use App\Enums\ExecutionStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Jobs\RunExecutionUnit;
use App\Models\Execution;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\QualityFixtures;
use Tests\TestCase;

class FinalizationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_finalize_has_no_effects_and_confirmation_reuses_one_command(): void
    {
        Storage::fake('local');
        [$admin, $project, $execution] = $this->reviewedExecution();
        Queue::fake();
        $this->actingAs($admin)->withSession([
            'auth.password_confirmed_at' => now()->subHours(3)->timestamp,
        ]);
        $url = route('projects.executions.finalize', [$project->uuid, $execution->uuid]);
        $payload = [];
        $headers = ['Idempotency-Key' => 'expired-finalize-retry-0001'];
        $before = $this->effects($project, $execution);

        $this->getJson(route('projects.executions.events', [$project->uuid, $execution->uuid]))
            ->assertOk()->assertJsonPath('execution.status', 'REVIEW');
        $this->postJson($url, $payload, $headers)
            ->assertStatus(423)->assertJson(['code' => 'PASSWORD_CONFIRMATION_REQUIRED']);
        $this->assertSame($before, $this->effects($project, $execution));
        Queue::assertNothingPushed();

        $this->postJson(route('action-password.confirm'), ['password' => 'wrong-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson($url, $payload, $headers)->assertStatus(423);
        $this->assertSame($before, $this->effects($project, $execution));
        Queue::assertNothingPushed();

        $this->postJson(route('action-password.confirm'), ['password' => 'password'])->assertOk();
        $this->postJson($url, $payload, $headers)->assertAccepted()->assertJson(['created' => true]);
        $this->postJson($url, $payload, $headers)->assertOk()->assertJson(['created' => false]);
        $this->assertSame(1, $execution->commands()->where('command_type', ExecutionCommandType::FINALIZE)->count());
        $this->assertSame(1, $execution->finalization()->count());
        $this->assertSame(1, DB::table('idempotency_receipts')->where('execution_id', $execution->getKey())->where('action', 'FINALIZE')->count());
        $this->assertSame(ExecutionStatus::REVIEW, $execution->fresh()->status);
        $this->assertSame(ProjectStatus::REVIEW, $project->fresh()->status);
        $this->assertSame($before['artifacts'], $execution->artifacts()->get()->toArray());
        Queue::assertPushed(RunExecutionUnit::class, 1);
    }

    /** @return array{User, Project, Execution} */
    private function reviewedExecution(): array
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => UserRole::ADMIN, 'is_active' => true]);
        $project = QualityFixtures::ready($admin);
        $started = app(StartProjectExecution::class)->start($project, $admin, 'authorization-start-0001', $project->configuration->version);
        $execution = $started->execution;
        $units = 0;
        while ($command = $execution->commands()->whereNull('processed_at')->orderBy('id')->first()) {
            $this->assertLessThan(20, ++$units, 'The valid REVIEW fixture did not converge.');
            (new RunExecutionUnit((int) $command->getKey()))->handle(app(ExecutionProvider::class), app(ToolAdapter::class));
        }
        $this->assertSame(ExecutionStatus::REVIEW, $execution->fresh()->status);
        $this->assertTrue($execution->verifications()->sole()->approved);

        return [$admin, $project->fresh(), $execution->fresh()];
    }

    /** @return array<string, mixed> */
    private function effects(Project $project, Execution $execution): array
    {
        return [
            'project' => $project->fresh()->getAttributes(),
            'execution' => $execution->fresh()->getAttributes(),
            'commands' => $execution->commands()->orderBy('id')->get()->toArray(),
            'events' => $execution->events()->orderBy('sequence')->get()->toArray(),
            'artifacts' => $execution->artifacts()->get()->toArray(),
            'finalization' => $execution->finalization()->get()->toArray(),
            'receipts' => DB::table('idempotency_receipts')->where('execution_id', $execution->getKey())->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'audit' => DB::table('audit_logs')->where('execution_id', $execution->getKey())->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'files' => Storage::disk('local')->allFiles(),
        ];
    }
}
