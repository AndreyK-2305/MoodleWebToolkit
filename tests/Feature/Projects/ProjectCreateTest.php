<?php

namespace Tests\Feature\Projects;

use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\ServerRole;
use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_index_is_a_history_without_a_creation_form(): void
    {
        $this->actingAs($this->user(UserRole::OPERATOR))
            ->get(route('projects.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/index')
                ->where('canCreate', true)
                ->has('projects.data', 0)
                ->missing('projectTypes'));
    }

    public function test_the_new_project_page_is_step_one_of_the_wizard(): void
    {
        $this->get(route('projects.create'))->assertRedirect(route('login'));

        $this->actingAs($this->user(UserRole::OPERATOR))
            ->get(route('projects.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/create')
                ->where('projectTypes', [['value' => 'COLLECT', 'label' => 'Recolectar'], ['value' => 'CONSOLIDATE', 'label' => 'Consolidar']])
                ->where('destinationLabel', false));

        config(['toolkit.features.remote_agent.enabled' => true]);
        $this->actingAs($this->user(UserRole::ADMIN))
            ->get(route('projects.create'))
            ->assertInertia(fn (Assert $page) => $page->where('destinationLabel', true));
    }

    public function test_an_auditor_can_neither_open_nor_save_a_new_project(): void
    {
        $auditor = $this->user(UserRole::AUDITOR);

        $this->actingAs($auditor)->get(route('projects.create'))->assertForbidden();
        $this->confirmed($auditor)->post(route('projects.store'), ['name' => 'Ajeno', 'type' => 'COLLECT'])->assertForbidden();
        $this->assertSame(0, Project::query()->count());
    }

    public function test_saving_step_one_creates_the_project_and_continues_at_step_two(): void
    {
        $operator = $this->user(UserRole::OPERATOR);

        $this->confirmed($operator)->post(route('projects.store'), ['name' => '', 'type' => 'INTEGRATE'])
            ->assertSessionHasErrors(['name', 'type']);
        $this->assertSame(0, Project::query()->count());

        $response = $this->confirmed($operator)->post(route('projects.store'), [
            'name' => ' Recolección 2026 ',
            'type' => 'COLLECT',
            'description' => 'Facultades',
        ]);

        $project = Project::query()->sole();
        $response->assertRedirect(route('projects.show', $project->uuid))->assertSessionHasNoErrors();
        $this->assertSame(['Recolección 2026', ProjectType::COLLECT, ProjectStatus::CONFIGURING, 'Facultades'],
            [$project->name, $project->type, $project->status, $project->description]);
        $this->assertSame(2, $project->configuration->settings['wizard_step']);
        $this->assertSame(1, $project->configuration->version);
        // An operator is assigned the project they create.
        $this->assertTrue($project->assignments()->where('user_id', $operator->getKey())->exists());
        $this->assertSame(0, $project->servers()->count());
    }

    public function test_a_remote_consolidation_registers_its_destination_when_created(): void
    {
        config(['toolkit.features.remote_agent.enabled' => true]);
        $operator = $this->user(UserRole::OPERATOR);

        $this->confirmed($operator)->post(route('projects.store'), ['name' => 'Campus', 'type' => 'CONSOLIDATE', 'destination_label' => str_repeat('x', 121)])
            ->assertSessionHasErrors('destination_label');
        $this->confirmed($operator)->post(route('projects.store'), ['name' => 'Campus', 'type' => 'CONSOLIDATE', 'destination_label' => 'EC2 campus'])
            ->assertSessionHasNoErrors();
        $this->confirmed($operator)->post(route('projects.store'), ['name' => 'Facultades', 'type' => 'CONSOLIDATE'])
            ->assertSessionHasNoErrors();

        $names = Project::query()->orderBy('id')->get()
            ->map(fn (Project $project): array => $project->servers()->get()->map(fn ($server): array => [$server->role, $server->name])->all())
            ->all();
        $this->assertSame([[[ServerRole::DESTINATION, 'EC2 campus']], [[ServerRole::DESTINATION, 'Facultades']]], $names);
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function confirmed(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    }
}
