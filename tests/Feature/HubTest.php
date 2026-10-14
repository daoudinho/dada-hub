<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HubTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_to_dashboard(): void
    {
        $this->get('/')->assertRedirect('/dashboard');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_dashboard_lists_enabled_modules(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Agenda Barcelone')
            ->assertSee(route('agenda.index'), false);
    }

    public function test_hub_user_command_creates_a_verified_user(): void
    {
        $this->artisan('hub:user', ['email' => 'dada@example.com', '--name' => 'Dada', '--password' => 'secret-password'])
            ->assertSuccessful();

        $user = User::where('email', 'dada@example.com')->firstOrFail();
        $this->assertSame('Dada', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('secret-password', $user->password));
    }

    public function test_hub_user_command_rejects_short_password(): void
    {
        $this->artisan('hub:user', ['email' => 'dada@example.com', '--name' => 'Dada', '--password' => 'short'])
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'dada@example.com']);
    }
}
