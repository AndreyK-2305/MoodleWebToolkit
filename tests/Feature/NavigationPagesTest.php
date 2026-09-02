<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NavigationPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function pages(): array
    {
        return [
            'projects.index' => 'projects/index',
            'manuals.index' => 'manuals',
            'about' => 'about',
        ];
    }

    public function test_guests_cannot_open_navigation_pages(): void
    {
        foreach ($this->pages() as $routeName => $component) {
            $this->get(route($routeName))->assertRedirect(route('login'));
        }
    }

    public function test_authenticated_users_can_open_navigation_pages(): void
    {
        $user = User::factory()->create();

        foreach ($this->pages() as $routeName => $component) {
            $this->actingAs($user)
                ->get(route($routeName))
                ->assertOk()
                ->assertInertia(
                    fn (Assert $page) => $page->component($component),
                );
        }
    }
}
