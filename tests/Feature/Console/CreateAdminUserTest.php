<?php

namespace Tests\Feature\Console;

use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateAdminUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_the_initial_admin(): void
    {
        $this->artisan('app:create-admin', [
            '--name' => 'Administrador',
            '--email' => 'admin@example.com',
            '--password' => 'AdminTemporal123',
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'email' => 'admin@example.com',
            'role' => UserRole::ADMIN->value,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }
}
