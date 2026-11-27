<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Coach\Models\Conversation;
use App\Modules\Coach\Models\GoogleHealthAccount;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Models\RunningSession;
use App\Modules\Coach\Models\SyncAccount;
use App\Modules\Coach\Services\GoogleHealth\GoogleHealthClient;
use App\Modules\Coach\Services\GoogleHealth\GoogleHealthImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CoachDadaSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $athlete;

    /** Clé Dada Sync de l'athlète, créée au premier envoi. */
    private ?string $token = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->athlete = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create(['name' => 'Dada Test']);
    }

    public function test_profile_shows_a_new_key_only_once_and_a_new_key_replaces_the_old_one(): void
    {
        $this->actingAs($this->athlete)->post(route('coach.sync.store'))->assertRedirect(route('coach.profile.edit').'#dada-sync');
        $first = session('sync_token');
        $this->actingAs($this->athlete)->get(route('coach.profile.edit'))->assertSee($first);
        $this->actingAs($this->athlete)->get(route('coach.profile.edit'))->assertDontSee($first)->assertSee('…'.substr($first, -4));

        $this->actingAs($this->athlete)->post(route('coach.sync.store'));
        $second = session('sync_token');

        $this->assertNotSame($first, $second);
        $this->assertSame(1, SyncAccount::count());
        $this->getJson(route('coach.sync.api.status'), ['Authorization' => 'Bearer '.$first])->assertUnauthorized();
        $this->getJson(route('coach.sync.api.status'), ['Authorization' => 'Bearer '.$second])->assertOk()->assertJsonPath('athlete', 'Dada');
    }

    public function test_api_refuses_a_missing_or_revoked_key(): void
    {
        $token = SyncAccount::issue($this->athlete);

        $this->getJson(route('coach.sync.api.status'))->assertUnauthorized();
        $this->getJson(route('coach.sync.api.status'), ['Authorization' => 'Bearer dsync_inconnue'])->assertUnauthorized();

        $this->actingAs($this->athlete)->delete(route('coach.sync.destroy'));

        $this->postJson(route('coach.sync.api.runs'), ['runs' => [$this->payload()]], ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        $this->assertDatabaseCount('coach_running_activities', 0);
    }

    public function test_new_run_gets_its_route_heart_rate_and_laps_validates_the_session_and_opens_a_debrief(): void
    {
        $this->travelTo('2026-11-25 22:00');
        $session = RunningSession::factory()->for($this->athlete)->create(['scheduled_on' => '2026-11-25', 'title' => 'Footing 7 km']);

        $this->sendRuns([$this->payload()], version: '0.2')
            ->assertOk()
            ->assertJson(['received' => 1, 'created' => 1, 'merged' => 0, 'updated' => 0]);

        $activity = RunningActivity::ownedBy($this->athlete)->sole();
        $this->assertSame(['samsung_health', 'run-1', 'RUNNING', 6938, 2591, $session->id], [
            $activity->source, $activity->external_id, $activity->sport_type, $activity->distance_m, $activity->moving_seconds, $activity->session_id,
        ]);
        $this->assertSame('2026-11-25 18:54:41', $activity->started_at->toDateTimeString()); // heure locale de l'athlète
        $this->assertSame([0, 5], array_slice($activity->streams['time'], 0, 2));
        $this->assertSame(140, $activity->streams['heartrate'][0]);
        $this->assertSame([152, 165], [$activity->avg_hr, $activity->max_hr]);
        $this->assertCount(8, $activity->laps); // 7,7 km de tracé : 7 tours de 1 km et un dernier de 750 m
        $this->assertSame([48.8566, 2.3522], $activity->polyline[0]);
        $this->assertNotNull($activity->device_synced_at);
        $this->assertSame('Samsung Health', $activity->sourceLabel());
        $this->assertSame('done', $session->fresh()->status);
        $this->assertSame('debrief', Conversation::sole()->kind);
        $this->assertSame('0.2', SyncAccount::sole()->app_version);
    }

    public function test_sending_a_run_again_updates_it_without_a_second_debrief(): void
    {
        $this->travelTo('2026-11-25 22:00');

        $this->sendRuns([$this->payload()]);
        $this->sendRuns([$this->payload(['distance_m' => 7000])])->assertJson(['created' => 0, 'updated' => 1]);

        $this->assertSame(7000, RunningActivity::ownedBy($this->athlete)->sole()->distance_m);
        $this->assertSame(1, Conversation::count());
    }

    public function test_run_already_imported_from_google_is_completed_and_keeps_its_review(): void
    {
        $this->travelTo('2026-11-25 22:00');
        $google = RunningActivity::factory()->for($this->athlete)->create([
            'source' => 'google_health', 'external_id' => 'g-1', 'started_at' => '2026-11-25 18:55:30',
            'distance_m' => 6900, 'avg_hr' => null, 'max_hr' => null, 'streams' => null, 'polyline' => null,
            'review' => ['verdict' => 'reached', 'summary' => 'Belle séance.', 'next' => 'Rien.'],
        ]);

        $this->sendRuns([$this->payload()])->assertJson(['created' => 0, 'merged' => 1]);

        $activity = RunningActivity::ownedBy($this->athlete)->sole();
        $this->assertTrue($activity->is($google));
        $this->assertSame(['google_health', 6900, 152, 165], [$activity->source, $activity->distance_m, $activity->avg_hr, $activity->max_hr]);
        $this->assertNotEmpty($activity->polyline);
        $this->assertNotEmpty($activity->streams['heartrate']);
        $this->assertSame('Belle séance.', $activity->review['summary']);
        $this->assertSame(0, Conversation::count());
    }

    public function test_google_health_no_longer_rewrites_or_duplicates_a_run_received_from_dada_sync(): void
    {
        $this->travelTo('2026-11-25 22:00');
        Http::preventStrayRequests();
        $this->sendRuns([$this->payload()]);
        $account = (new GoogleHealthAccount(['health_user_id' => '443769', 'access_token' => 'a', 'refresh_token' => 'r',
            'expires_at' => now()->addHour(), 'scopes' => GoogleHealthClient::ACTIVITY_SCOPE]))->user()->associate($this->athlete);
        $account->save();

        app(GoogleHealthImporter::class)->import($account, [
            'name' => 'users/443769/dataTypes/exercise/dataPoints/g-2',
            'exercise' => [
                'exerciseType' => 'RUNNING',
                'interval' => ['startUtcOffset' => '3600s', 'endUtcOffset' => '3600s', 'startTime' => '2026-11-25T17:55:00Z', 'endTime' => '2026-11-25T18:37:52Z'],
                'metricsSummary' => ['distanceMillimeters' => 5000000],
            ],
        ]);

        $activity = RunningActivity::ownedBy($this->athlete)->sole();
        $this->assertSame(['samsung_health', 6938], [$activity->source, $activity->distance_m]);
    }

    public function test_history_upload_opens_no_debrief(): void
    {
        $this->travelTo('2026-11-25 22:00');

        $this->sendRuns([$this->payload()], history: true)->assertJson(['created' => 1]);

        $this->assertSame(0, Conversation::count());
    }

    public function test_minute_by_minute_speed_gives_no_distance_curve_for_records(): void
    {
        $this->travelTo('2026-11-25 22:00');
        $samples = array_map(fn (int $minute) => [$minute * 60, null, 2.8, null], range(0, 23));

        $this->sendRuns([$this->payload(['route' => [], 'samples' => $samples])])->assertOk();

        $streams = RunningActivity::ownedBy($this->athlete)->sole()->streams;
        $this->assertCount(24, $streams['velocity_smooth']);
        $this->assertArrayNotHasKey('distance', $streams);
    }

    public function test_runs_are_validated_and_only_reach_the_key_owner(): void
    {
        $this->travelTo('2026-11-25 22:00');
        $other = User::factory()->withModules('coach')->create();
        $othersRun = RunningActivity::factory()->for($other)->create(['started_at' => '2026-11-25 18:54:41', 'polyline' => null]);

        $this->sendRuns([$this->payload(['type' => 'WALKING'])])->assertUnprocessable()->assertJsonValidationErrors('runs.0.type');
        $this->sendRuns([$this->payload(['end' => '2026-11-25T18:00:00+01:00'])])->assertUnprocessable()->assertJsonValidationErrors('runs.0.end');
        $this->sendRuns([$this->payload()])->assertJson(['created' => 1, 'merged' => 0]);

        $this->assertNull($othersRun->fresh()->polyline);
        $this->assertSame(1, RunningActivity::ownedBy($this->athlete)->count());
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     */
    private function sendRuns(array $runs, string $version = '0.1', bool $history = false): TestResponse
    {
        $this->token ??= SyncAccount::issue($this->athlete);

        return $this->postJson(route('coach.sync.api.runs'), ['history' => $history, 'runs' => $runs], ['Authorization' => 'Bearer '.$this->token, 'X-App-Version' => $version]);
    }

    /**
     * Course telle que l'app l'envoie : 43 min, tracé vers le nord (un point toutes les 5 s, 15 m chaque fois),
     * journal de fréquence cardiaque (140 à 165 bpm) et de vitesse.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function payload(array $changes = []): array
    {
        $seconds = range(0, 2580, 5);

        return $changes + [
            'id' => 'run-1',
            'type' => 'RUNNING',
            'start' => '2026-11-25T18:54:41+01:00',
            'end' => '2026-11-25T19:37:52+01:00',
            'duration_s' => 2591,
            'distance_m' => 6938,
            'route' => array_map(fn (int $second) => [$second, 48.8566 + $second / 5 * 0.000135, 2.3522, 35.0], $seconds),
            'samples' => array_map(fn (int $second) => [$second, 140 + intdiv($second * 25, 2580), 3.0, 170], $seconds),
        ];
    }
}
