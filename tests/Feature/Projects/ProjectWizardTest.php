<?php

namespace Tests\Feature\Projects;

use App\Domain\Projects\ProjectAssignmentManager;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_creates_and_configures_all_three_project_types(): void
    {
        $initialProjects = Project::query()->count();
        $initialExecutions = DB::table('executions')->count();
        $initialCommands = DB::table('execution_commands')->count();
        $initialJobs = DB::table('jobs')->count();
        $operator = $this->user(UserRole::OPERATOR);

        foreach (ProjectType::cases() as $type) {
            $project = $this->createProject($operator, $type);
            $this->configure($operator, $project, 'SUCCESS');
            $version = $project->fresh()->configuration->version;

            $this->actingAs($operator)
                ->post(route('projects.wizard.confirm', $project->uuid), [
                    'configuration_version' => $version,
                    'accepted_warning_ids' => [],
                ])
                ->assertRedirect(route('projects.show', $project->uuid))
                ->assertSessionHasNoErrors();

            $this->assertSame(ProjectStatus::READY, $project->fresh()->status);
            $this->assertSame(
                match ($type) {
                    ProjectType::COLLECT, ProjectType::INTEGRATE => 1,
                    ProjectType::CONSOLIDATE => 2,
                },
                $project->moodleInstances()->where('role', 'SOURCE')->count(),
            );
            $this->assertSame(
                $type === ProjectType::COLLECT ? 0 : 1,
                $project->moodleInstances()->where('role', 'DESTINATION')->count(),
            );
        }

        $this->assertSame($initialProjects + 3, Project::query()->count());
        $this->assertSame($initialExecutions, DB::table('executions')->count());
        $this->assertSame($initialCommands, DB::table('execution_commands')->count());
        $this->assertSame($initialJobs, DB::table('jobs')->count());
    }

    public function test_operator_is_assigned_to_the_project_atomically_without_gaining_assignment_management(): void
    {
        $operator = $this->user(UserRole::OPERATOR);
        $otherOperator = $this->user(UserRole::OPERATOR);
        $project = $this->createProject($operator, ProjectType::COLLECT);

        $this->assertDatabaseHas('project_assignments', [
            'project_id' => $project->getKey(),
            'user_id' => $operator->getKey(),
            'assigned_by' => $operator->getKey(),
        ]);
        $this->assertDatabaseCount('project_assignments', 1);
        $this->assertTrue($operator->can('update', $project));
        $this->assertFalse($operator->can('manageAssignments', $project));
        $this->assertFalse($otherOperator->can('view', $project));

        $this->actingAs($otherOperator)
            ->patch(route('projects.wizard.basics', $project->uuid), $this->basics(ProjectType::COLLECT))
            ->assertForbidden();

        $this->assertSame('Proyecto COLLECT', $project->fresh()->name);
    }

    public function test_admin_creation_does_not_add_redundant_assignment(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $project = $this->createProject($admin, ProjectType::COLLECT);

        $this->assertDatabaseMissing('project_assignments', ['project_id' => $project->getKey()]);
        $this->assertTrue($admin->can('view', $project));
        $this->assertTrue($admin->can('update', $project));
    }

    public function test_saved_progress_is_restored_when_the_project_is_opened_again(): void
    {
        $operator = $this->user(UserRole::OPERATOR);
        $project = $this->createProject($operator, ProjectType::INTEGRATE);
        $this->configure($operator, $project, 'WARNING');

        $this->actingAs($operator)
            ->get(route('projects.show', $project->uuid))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/show')
                ->where('project.uuid', $project->uuid)
                ->where('project.current_step', 5)
                ->where('project.options.simulation_scenario', 'WARNING')
                ->where('project.instances.0.validated', true)
                ->where('project.preflight.configuration_version', 3)
                ->has('project.instances', 2)
                ->has('project.preflight.checks', 7));
    }

    public function test_draft_can_be_incomplete_but_invalid_cardinalities_are_rejected(): void
    {
        $operator = $this->user(UserRole::OPERATOR);
        $project = $this->createProject($operator, ProjectType::COLLECT);

        $this->actingAs($operator)
            ->put(route('projects.wizard.instances', $project->uuid), ['instances' => []])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('moodle_instances', 0);

        $this->actingAs($operator)
            ->put(route('projects.wizard.instances', $project->uuid), [
                'instances' => [
                    $this->instancePayload('SOURCE', 1),
                    $this->instancePayload('SOURCE', 2),
                ],
            ])
            ->assertSessionHasErrors('instances');

        $this->assertDatabaseCount('moodle_instances', 0);
    }

    public function test_incompatible_destination_and_cross_project_references_are_rejected(): void
    {
        $operator = $this->user(UserRole::OPERATOR);
        $first = $this->createProject($operator, ProjectType::COLLECT);

        $this->actingAs($operator)
            ->put(route('projects.wizard.instances', $first->uuid), [
                'instances' => [$this->instancePayload('SOURCE', 1)],
            ])
            ->assertSessionHasNoErrors();
        $foreign = $first->moodleInstances()->with('server')->firstOrFail();
        $second = $this->createProject($operator, ProjectType::COLLECT);

        $this->actingAs($operator)
            ->put(route('projects.wizard.instances', $second->uuid), [
                'instances' => [[
                    ...$this->instancePayload('SOURCE', 3),
                    'uuid' => $foreign->uuid,
                    'server_uuid' => $foreign->server->uuid,
                ]],
            ])
            ->assertSessionHasErrors('instances.0.uuid');

        $consolidation = $this->createProject($operator, ProjectType::CONSOLIDATE);
        $invalidDestination = $this->instancePayload('DESTINATION', 9, 'EXISTING_CONSOLIDATED');

        $this->actingAs($operator)
            ->put(route('projects.wizard.instances', $consolidation->uuid), [
                'instances' => [
                    $this->instancePayload('SOURCE', 7),
                    $this->instancePayload('SOURCE', 8),
                    $invalidDestination,
                ],
            ])
            ->assertSessionHasErrors('instances.2.destination_kind');

        $this->assertSame(0, $second->moodleInstances()->count());
        $this->assertSame(0, $consolidation->moodleInstances()->count());
    }

    public function test_auditor_can_consult_assigned_project_but_cannot_mutate_or_confirm(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $auditor = $this->user(UserRole::AUDITOR);
        $unassigned = $this->user(UserRole::AUDITOR);
        $project = $this->createProject($admin, ProjectType::COLLECT);
        app(ProjectAssignmentManager::class)->assign($project, $auditor, $admin);

        $this->actingAs($auditor)
            ->get(route('projects.show', $project->uuid))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/show')
                ->where('project.can_edit', false));

        $this->actingAs($auditor)
            ->patch(route('projects.wizard.basics', $project->uuid), $this->basics(ProjectType::COLLECT))
            ->assertForbidden();
        $this->actingAs($auditor)
            ->post(route('projects.wizard.preflight', $project->uuid))
            ->assertForbidden();
        $this->actingAs($auditor)
            ->post(route('projects.wizard.confirm', $project->uuid), [
                'configuration_version' => 1,
                'accepted_warning_ids' => [],
            ])
            ->assertForbidden();
        $this->actingAs($unassigned)
            ->get(route('projects.show', $project->uuid))
            ->assertForbidden();

        $this->assertSame(ProjectStatus::CONFIGURING, $project->fresh()->status);
    }

    public function test_error_preflight_blocks_confirmation(): void
    {
        $operator = $this->user(UserRole::OPERATOR);
        $project = $this->createProject($operator, ProjectType::COLLECT);
        $this->configure($operator, $project, 'ERROR');
        $version = $project->fresh()->configuration->version;

        $this->actingAs($operator)
            ->post(route('projects.wizard.confirm', $project->uuid), [
                'configuration_version' => $version,
                'accepted_warning_ids' => [],
            ])
            ->assertSessionHasErrors('preflight');

        $this->assertSame(ProjectStatus::CONFIGURING, $project->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'project_id' => $project->getKey(),
            'action' => 'PROJECT_CONFIGURATION_CONFIRMED',
        ]);
    }

    public function test_warning_requires_explicit_acceptance_and_creates_audit_evidence(): void
    {
        $operator = $this->user(UserRole::OPERATOR);
        $project = $this->createProject($operator, ProjectType::CONSOLIDATE);
        $this->configure($operator, $project, 'WARNING');
        $version = $project->fresh()->configuration->version;

        $this->actingAs($operator)
            ->post(route('projects.wizard.confirm', $project->uuid), [
                'configuration_version' => $version,
                'accepted_warning_ids' => [],
            ])
            ->assertSessionHasErrors('accepted_warning_ids');

        $this->actingAs($operator)
            ->post(route('projects.wizard.confirm', $project->uuid), [
                'configuration_version' => $version,
                'accepted_warning_ids' => ['simulation.capacity'],
            ])
            ->assertSessionHasNoErrors();

        $audit = AuditLog::query()
            ->where('project_id', $project->getKey())
            ->where('action', 'PROJECT_WARNINGS_ACCEPTED')
            ->sole();

        $this->assertSame($operator->getKey(), $audit->actor_id);
        $this->assertSame($version, $audit->payload['configuration_version']);
        $this->assertSame(['simulation.capacity'], $audit->payload['checks']);
        $this->assertSame(ProjectStatus::READY, $project->fresh()->status);
    }

    public function test_configuration_change_invalidates_preflight_and_old_warning_acceptance(): void
    {
        $operator = $this->user(UserRole::OPERATOR);
        $project = $this->createProject($operator, ProjectType::COLLECT);
        $this->configure($operator, $project, 'WARNING');
        $oldVersion = $project->fresh()->configuration->version;

        $this->actingAs($operator)->post(route('projects.wizard.confirm', $project->uuid), [
            'configuration_version' => $oldVersion,
            'accepted_warning_ids' => ['simulation.capacity'],
        ])->assertSessionHasNoErrors();

        $this->actingAs($operator)
            ->put(route('projects.wizard.options', $project->uuid), $this->optionsPayload(ProjectType::COLLECT, 'SUCCESS'))
            ->assertSessionHasNoErrors();

        $configuration = $project->fresh()->configuration;
        $this->assertSame($oldVersion + 1, $configuration->version);
        $this->assertNull($configuration->settings['preflight']);
        $this->assertNull($configuration->settings['confirmation']);
        $this->assertSame(ProjectStatus::CONFIGURING, $project->fresh()->status);

        $this->actingAs($operator)
            ->post(route('projects.wizard.confirm', $project->uuid), [
                'configuration_version' => $oldVersion,
                'accepted_warning_ids' => ['simulation.capacity'],
            ])
            ->assertSessionHasErrors('configuration_version');
    }

    public function test_confirmation_rejects_preflight_when_data_changes_without_a_version_bump(): void
    {
        $operator = $this->user(UserRole::OPERATOR);
        $project = $this->createProject($operator, ProjectType::COLLECT);
        $this->configure($operator, $project, 'SUCCESS');
        $configuration = $project->fresh()->configuration;
        $instance = $project->moodleInstances()->firstOrFail();
        $instance->update(['base_url' => 'https://changed-outside-wizard.test']);

        $this->actingAs($operator)
            ->post(route('projects.wizard.confirm', $project->uuid), [
                'configuration_version' => $configuration->version,
                'accepted_warning_ids' => [],
            ])
            ->assertSessionHasErrors('preflight');

        $this->assertSame(ProjectStatus::CONFIGURING, $project->fresh()->status);
    }

    public function test_valid_confirmation_is_idempotent_and_never_creates_1d_records(): void
    {
        $initialProjects = Project::query()->count();
        $initialExecutions = DB::table('executions')->count();
        $initialCommands = DB::table('execution_commands')->count();
        $initialJobs = DB::table('jobs')->count();
        $operator = $this->user(UserRole::OPERATOR);
        $project = $this->createProject($operator, ProjectType::INTEGRATE);
        $this->configure($operator, $project, 'SUCCESS');
        $version = $project->fresh()->configuration->version;
        $payload = [
            'configuration_version' => $version,
            'accepted_warning_ids' => [],
        ];

        $this->actingAs($operator)
            ->post(route('projects.wizard.confirm', $project->uuid), $payload)
            ->assertSessionHasNoErrors();
        $confirmedSettings = $project->fresh()->configuration->settings;

        $this->actingAs($operator)
            ->post(route('projects.wizard.preflight', $project->uuid))
            ->assertSessionHasNoErrors();

        $this->assertSame(ProjectStatus::READY, $project->fresh()->status);
        $this->assertSame(
            $confirmedSettings['confirmation'],
            $project->fresh()->configuration->settings['confirmation'],
        );
        $this->actingAs($operator)
            ->post(route('projects.wizard.confirm', $project->uuid), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame(ProjectStatus::READY, $project->fresh()->status);
        $this->assertSame(1, AuditLog::query()
            ->where('project_id', $project->getKey())
            ->where('action', 'PROJECT_CONFIGURATION_CONFIRMED')
            ->count());
        $this->assertSame($initialProjects + 1, Project::query()->count());
        $this->assertSame($initialExecutions, DB::table('executions')->count());
        $this->assertSame($initialCommands, DB::table('execution_commands')->count());
        $this->assertSame($initialJobs, DB::table('jobs')->count());
    }

    public function test_review_and_completed_projects_remain_protected_from_wizard_writes(): void
    {
        $operator = $this->user(UserRole::OPERATOR);

        foreach ([ProjectStatus::REVIEW, ProjectStatus::COMPLETED] as $status) {
            $project = $this->createProject($operator, ProjectType::COLLECT);
            DB::table('projects')->where('id', $project->getKey())->update(['status' => $status->value]);

            $this->actingAs($operator)
                ->patch(route('projects.wizard.basics', $project->uuid), [
                    ...$this->basics(ProjectType::COLLECT),
                    'name' => 'Cambio prohibido',
                ])
                ->assertForbidden();
            $this->actingAs($operator)
                ->post(route('projects.wizard.preflight', $project->uuid))
                ->assertForbidden();
            $this->actingAs($operator)
                ->post(route('projects.wizard.confirm', $project->uuid), [
                    'configuration_version' => 1,
                    'accepted_warning_ids' => [],
                ])
                ->assertForbidden();

            $this->assertSame('Proyecto COLLECT', $project->fresh()->name);
            $this->assertSame($status, $project->fresh()->status);
        }
    }

    public function test_project_list_contains_only_visible_projects_and_is_read_only_for_auditor(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        $operator = $this->user(UserRole::OPERATOR);
        $auditor = $this->user(UserRole::AUDITOR);
        $operatorProject = $this->createProject($operator, ProjectType::COLLECT);
        $adminProject = $this->createProject($admin, ProjectType::INTEGRATE);
        app(ProjectAssignmentManager::class)->assign($adminProject, $auditor, $admin);
        $adminProjectCount = Project::query()->count();

        $this->actingAs($operator)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/index')
                ->where('canCreate', true)
                ->has('projects.data', 1)
                ->where('projects.data.0.uuid', $operatorProject->uuid));

        $this->actingAs($auditor)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/index')
                ->where('canCreate', false)
                ->has('projects.data', 1)
                ->where('projects.data.0.uuid', $adminProject->uuid)
                ->where('projects.data.0.can_edit', false));

        $this->actingAs($admin)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->has('projects.data', $adminProjectCount));
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function createProject(User $actor, ProjectType $type): Project
    {
        $this->actingAs($actor)
            ->post(route('projects.store'), $this->basics($type))
            ->assertSessionHasNoErrors();

        return Project::query()->where('created_by', $actor->getKey())->latest('id')->firstOrFail();
    }

    /** @return array{name: string, type: string, description: string} */
    private function basics(ProjectType $type): array
    {
        return [
            'name' => "Proyecto {$type->value}",
            'type' => $type->value,
            'description' => 'Configuración persistente de aceptación.',
        ];
    }

    private function configure(User $actor, Project $project, string $scenario): void
    {
        $instances = match ($project->type) {
            ProjectType::COLLECT => [$this->instancePayload('SOURCE', $project->getKey())],
            ProjectType::CONSOLIDATE => [
                $this->instancePayload('SOURCE', $project->getKey() * 10 + 1),
                $this->instancePayload('SOURCE', $project->getKey() * 10 + 2),
                $this->instancePayload('DESTINATION', $project->getKey() * 10 + 3, 'PREPARED'),
            ],
            ProjectType::INTEGRATE => [
                $this->instancePayload('SOURCE', $project->getKey() * 10 + 1),
                $this->instancePayload('DESTINATION', $project->getKey() * 10 + 2, 'EXISTING_CONSOLIDATED'),
            ],
        };

        $this->actingAs($actor)
            ->put(route('projects.wizard.instances', $project->uuid), ['instances' => $instances])
            ->assertSessionHasNoErrors();
        $this->actingAs($actor)
            ->put(route('projects.wizard.options', $project->uuid), $this->optionsPayload($project->type, $scenario))
            ->assertSessionHasNoErrors();
        $this->actingAs($actor)
            ->post(route('projects.wizard.preflight', $project->uuid))
            ->assertSessionHasNoErrors();
    }

    /** @return array<string, mixed> */
    private function optionsPayload(ProjectType $type, string $scenario): array
    {
        return match ($type) {
            ProjectType::COLLECT => [
                'simulation_scenario' => $scenario,
                'artifact_name' => 'paquete-estructurado',
            ],
            ProjectType::CONSOLIDATE => [
                'simulation_scenario' => $scenario,
                'category_strategy' => 'PRESERVE',
                'user_conflict_strategy' => 'REVIEW',
                'admin_strategy' => 'EXCLUDE_SOURCE_ADMINS',
                'include_archived_courses' => false,
            ],
            ProjectType::INTEGRATE => [
                'simulation_scenario' => $scenario,
                'conflict_strategy' => 'REVIEW',
                'preserve_destination_admins' => true,
            ],
        };
    }

    /** @return array<string, mixed> */
    private function instancePayload(string $role, int $sequence, ?string $destinationKind = null): array
    {
        return [
            'uuid' => null,
            'server_uuid' => null,
            'role' => $role,
            'server_name' => "Servidor {$sequence}",
            'server_host' => "moodle-{$sequence}.test",
            'name' => "Moodle {$sequence}",
            'base_url' => "https://moodle-{$sequence}.test",
            'moodle_version' => '4.5',
            'validated' => true,
            'destination_kind' => $destinationKind,
        ];
    }
}
