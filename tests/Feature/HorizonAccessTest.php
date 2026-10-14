<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class HorizonAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/horizon')->assertRedirect(route('login'));
    }

    public function test_any_user_can_view_horizon_when_no_admin_is_configured(): void
    {
        config(['horizon.admins' => []]);

        $this->assertTrue(Gate::forUser(User::factory()->create())->allows('viewHorizon'));
    }

    public function test_only_listed_admins_can_view_horizon(): void
    {
        config(['horizon.admins' => ['admin@dada-hub.test']]);

        $this->assertTrue(Gate::forUser(User::factory()->create(['email' => 'admin@dada-hub.test']))->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('viewHorizon'));
    }
}
