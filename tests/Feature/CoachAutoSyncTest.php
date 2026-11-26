<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Coach\Jobs\SyncGarminJob;
use App\Modules\Coach\Jobs\SyncGoogleHealthJob;
use App\Modules\Coach\Models\GarminAccount;
use App\Modules\Coach\Models\GoogleHealthAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CoachAutoSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $athlete;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([SyncGarminJob::class, SyncGoogleHealthJob::class]);
        $this->athlete = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create();
    }

    public function test_opening_the_coach_resyncs_stale_accounts_only_once(): void
    {
        $garmin = $this->garmin(['last_synced_at' => now()->subHour()]);
        $google = $this->google(['last_synced_at' => now()->subHour()]);

        $this->actingAs($this->athlete)->get('/coach')->assertOk();
        $this->actingAs($this->athlete)->get('/coach/profil')->assertOk(); // la synchro lancée n'a pas encore fini

        Queue::assertPushed(SyncGarminJob::class, fn (SyncGarminJob $job) => $job->accountId === $garmin->id);
        Queue::assertPushed(SyncGoogleHealthJob::class, fn (SyncGoogleHealthJob $job) => $job->accountId === $google->id);
        Queue::assertPushed(SyncGarminJob::class, 1);
        Queue::assertPushed(SyncGoogleHealthJob::class, 1);
    }

    public function test_recent_or_unusable_accounts_are_not_resynced(): void
    {
        $this->garmin(['last_synced_at' => now()->subMinutes(10)]);
        $this->google(['last_synced_at' => now()->subHour(), 'refresh_token' => null]); // autorisation expirée

        $this->actingAs($this->athlete)->get('/coach')->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_an_expired_garmin_key_or_a_running_first_import_is_left_alone(): void
    {
        $this->garmin(['last_synced_at' => now()->subHour(), 'key_expires_on' => today()->subDay()]);
        $this->google(['backfilled_at' => null, 'last_synced_at' => null]);

        $this->actingAs($this->athlete)->get('/coach')->assertOk();

        Queue::assertNothingPushed();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function garmin(array $attributes): GarminAccount
    {
        $account = (new GarminAccount($attributes + ['api_key' => 'gk_live_0123456789abcdefWXYZ', 'backfilled_at' => now()->subWeek()]))->user()->associate($this->athlete);
        $account->save();

        return $account;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function google(array $attributes): GoogleHealthAccount
    {
        $account = (new GoogleHealthAccount($attributes + [
            'health_user_id' => '4437698108461627695', 'access_token' => 'acces', 'refresh_token' => 'renouv',
            'expires_at' => now()->addHour(), 'backfilled_at' => now()->subWeek(),
        ]))->user()->associate($this->athlete);
        $account->save();

        return $account;
    }
}
