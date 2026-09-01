<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_can_list_users(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $operator = User::factory()->create(['role' => UserRole::OPERATOR]);

        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($operator)->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_admin_can_create_a_user_with_a_temporary_password(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Operador Moodle',
            'email' => 'operador@example.com',
            'role' => UserRole::OPERATOR->value,
            'password' => 'Temporal12345',
            'password_confirmation' => 'Temporal12345',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'operador@example.com',
            'role' => UserRole::OPERATOR->value,
            'is_active' => true,
            'must_change_password' => true,
        ]);
    }

    public function test_admin_cannot_deactivate_or_demote_their_own_account(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        $this->actingAs($admin)
            ->patch(route('admin.users.status', $admin), ['is_active' => false])
            ->assertUnprocessable();
        $this->actingAs($admin)
            ->patch(route('admin.users.role', $admin), ['role' => UserRole::AUDITOR->value])
            ->assertUnprocessable();
    }
}
