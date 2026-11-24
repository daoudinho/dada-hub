<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_only_reaches_the_modules_open_to_them(): void
    {
        $member = User::factory()->withModules('recettes')->create();

        $this->actingAs($member)->get('/recettes')->assertOk();
        $this->actingAs($member)->get('/agenda')->assertForbidden();
        $this->actingAs($member)->get('/dashboard')->assertSee('Mes Recettes')->assertDontSee('Agenda Barcelone');
    }

    public function test_admin_reaches_every_module(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/agenda')->assertOk();
        $this->actingAs($admin)->get('/recettes')->assertOk();
    }

    public function test_only_admin_can_manage_access(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->get('/admin/acces')->assertForbidden();
        $this->actingAs($member)->put("/admin/acces/{$member->id}", ['role' => 'admin'])->assertForbidden();
        $this->assertFalse($member->fresh()->isAdmin());
    }

    public function test_admin_sets_role_and_modules_of_a_user(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->withModules('agenda')->create();

        $this->actingAs($admin)->get('/admin/acces')->assertOk()->assertSee($member->email);

        $this->actingAs($admin)
            ->put("/admin/acces/{$member->id}", ['role' => 'member', 'modules' => ['recettes']])
            ->assertSessionHasNoErrors();

        $member = $member->fresh();
        $this->assertSame(['recettes'], $member->moduleSlugs());
        $this->actingAs($member)->get('/agenda')->assertForbidden();
    }

    public function test_admin_cannot_demote_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put("/admin/acces/{$admin->id}", ['role' => 'member'])
            ->assertSessionHasErrors('role');

        $this->assertTrue($admin->fresh()->isAdmin());
    }
}
