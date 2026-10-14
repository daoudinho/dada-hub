<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Agenda\Models\Event;
use App\Modules\Agenda\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_empty_database_and_creates_admin_from_config(): void
    {
        config(['hub.admin' => ['email' => 'admin@dada-hub.test', 'password' => 'secret-password', 'name' => 'Admin']]);

        $this->seed();

        $this->assertGreaterThan(0, Source::count());
        $this->assertGreaterThan(0, Event::count());
        $admin = User::where('email', 'admin@dada-hub.test')->sole();
        $this->assertTrue(Hash::check('secret-password', $admin->password));
        $this->assertNotNull($admin->email_verified_at);
    }

    public function test_running_again_changes_nothing(): void
    {
        config(['hub.admin' => ['email' => 'admin@dada-hub.test', 'password' => 'secret-password', 'name' => null]]);
        $this->seed();
        Source::first()->update(['active' => false]);
        Event::first()->delete();
        $before = [Source::count(), Source::where('active', false)->count(), Event::count()];

        config(['hub.admin.password' => 'autre-mot-de-passe']);
        $this->seed();

        $this->assertSame($before, [Source::count(), Source::where('active', false)->count(), Event::count()]);
        $this->assertTrue(Hash::check('secret-password', User::sole()->password));
    }

    public function test_no_admin_without_credentials(): void
    {
        config(['hub.admin' => ['email' => null, 'password' => null, 'name' => null]]);

        $this->seed();

        $this->assertSame(0, User::count());
    }
}
