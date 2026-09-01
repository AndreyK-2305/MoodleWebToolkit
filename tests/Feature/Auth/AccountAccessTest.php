<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_user_cannot_log_in(): void
    {
        User::factory()->create(['email' => 'inactive@example.com', 'is_active' => false]);

        $this->post(route('login.store'), [
            'email' => 'inactive@example.com',
            'password' => 'password',
        ]);

        $this->assertGuest();
    }

    public function test_temporary_password_blocks_the_application_until_changed(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('security.edit'));
        $this->actingAs($user)->get(route('security.edit'))->assertOk();
    }
}
