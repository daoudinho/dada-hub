<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Coach\Jobs\ImportStravaActivityJob;
use App\Modules\Coach\Jobs\SyncStravaJob;
use App\Modules\Coach\Models\BestEffort;
use App\Modules\Coach\Models\Conversation;
use App\Modules\Coach\Models\Gear;
use App\Modules\Coach\Models\Profile;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Models\RunningSession;
use App\Modules\Coach\Models\StravaAccount;
use App\Modules\Coach\Services\AthleteContext;
use App\Modules\Coach\Services\Strava\StravaImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CoachStravaTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIVITY_URL = 'https://www.strava.com/api/v3/activities/987';

    private User $athlete;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.strava.client_id' => '12345',
            'services.strava.client_secret' => 'secret',
            'services.strava.webhook_verify_token' => 'jeton-verif',
            'services.strava.subscription_id' => '777',
        ]);
        $this->athlete = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create();
    }

    public function test_connect_sends_the_athlete_to_strava_with_a_state(): void
    {
        $response = $this->actingAs($this->athlete)->get('/coach/strava/connexion');

        $location = $response->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://www.strava.com/oauth/authorize?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(['12345', 'read,activity:read_all,profile:read_all', route('coach.strava.callback')], [$query['client_id'], $query['scope'], $query['redirect_uri']]);
        $this->assertSame(session('strava_state'), $query['state']);
    }

    public function test_callback_links_the_account_with_encrypted_tokens_and_starts_the_import(): void
    {
        Queue::fake([SyncStravaJob::class]);
        Http::preventStrayRequests();
        Http::fake(['https://www.strava.com/oauth/token' => Http::response([
            'access_token' => 'acces-1', 'refresh_token' => 'renouv-1', 'expires_at' => now()->addHours(6)->timestamp,
            'athlete' => ['id' => 4242, 'firstname' => 'Dada', 'lastname' => 'Runner'],
        ])]);

        $this->actingAs($this->athlete)->withSession(['strava_state' => 'etat'])
            ->get('/coach/strava/retour?state=etat&code=abc&scope=read,activity:read_all,profile:read_all')
            ->assertRedirect(route('coach.profile.edit'));

        $account = StravaAccount::ownedBy($this->athlete)->sole();
        $this->assertSame([4242, 'Dada Runner', 'acces-1'], [$account->athlete_id, $account->athlete_name, $account->access_token]);
        $this->assertNotSame('acces-1', DB::table('coach_strava_accounts')->value('access_token'));
        Queue::assertPushed(SyncStravaJob::class, fn (SyncStravaJob $job) => $job->accountId === $account->id);
        Http::assertSent(fn (Request $request) => $request['code'] === 'abc' && $request['grant_type'] === 'authorization_code');
    }

    public function test_callback_rejects_a_forged_state(): void
    {
        Http::preventStrayRequests();

        $this->actingAs($this->athlete)->withSession(['strava_state' => 'etat'])
            ->get('/coach/strava/retour?state=autre&code=abc&scope=read,activity:read_all')
            ->assertForbidden();

        $this->assertDatabaseCount('coach_strava_accounts', 0);
    }

    public function test_callback_requires_access_to_activities(): void
    {
        Http::preventStrayRequests();

        $this->actingAs($this->athlete)->withSession(['strava_state' => 'etat'])
            ->get('/coach/strava/retour?state=etat&code=abc&scope=read')
            ->assertSessionHas('status', 'Pour que le coach voie tes courses, laisse cochée l\'autorisation « voir tes activités », y compris privées.');

        $this->assertDatabaseCount('coach_strava_accounts', 0);
    }

    public function test_callback_refuses_a_strava_account_already_linked_to_someone_else(): void
    {
        Queue::fake([SyncStravaJob::class]);
        $this->account(User::factory()->withModules('coach')->create(), 4242);
        Http::fake(['https://www.strava.com/oauth/token' => Http::response([
            'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addHours(6)->timestamp, 'athlete' => ['id' => 4242],
        ])]);

        $this->actingAs($this->athlete)->withSession(['strava_state' => 'etat'])
            ->get('/coach/strava/retour?state=etat&code=abc&scope=read,activity:read_all')
            ->assertSessionHas('status', 'Ce compte Strava est déjà relié à un autre compte du hub.');

        $this->assertSame(0, StravaAccount::ownedBy($this->athlete)->count());
        Queue::assertNothingPushed();
    }

    public function test_webhook_subscription_is_validated_only_with_the_right_token(): void
    {
        $this->get('/coach/strava/webhook?hub.mode=subscribe&hub.verify_token=jeton-verif&hub.challenge=xyz')
            ->assertOk()->assertExactJson(['hub.challenge' => 'xyz']);

        $this->get('/coach/strava/webhook?hub.mode=subscribe&hub.verify_token=faux&hub.challenge=xyz')->assertForbidden();
    }

    public function test_webhook_queues_the_import_of_a_new_activity(): void
    {
        Queue::fake([ImportStravaActivityJob::class]);
        $account = $this->account($this->athlete, 4242);

        $this->postJson('/coach/strava/webhook', $this->event(['aspect_type' => 'create', 'object_id' => 987]))->assertNoContent();

        Queue::assertPushed(ImportStravaActivityJob::class, fn (ImportStravaActivityJob $job) => [$job->accountId, $job->stravaId, $job->notify] === [$account->id, 987, true]);
    }

    public function test_webhook_ignores_events_from_another_subscription_or_an_unknown_athlete(): void
    {
        Queue::fake([ImportStravaActivityJob::class]);
        $this->account($this->athlete, 4242);

        $this->postJson('/coach/strava/webhook', $this->event(['subscription_id' => 1]))->assertForbidden();
        $this->postJson('/coach/strava/webhook', $this->event(['owner_id' => 999]))->assertNoContent();

        Queue::assertNothingPushed();
    }

    public function test_webhook_removes_a_deleted_activity_and_reopens_its_session(): void
    {
        $this->account($this->athlete, 4242);
        $session = RunningSession::factory()->for($this->athlete)->done()->create();
        $activity = RunningActivity::factory()->for($this->athlete)->create(['source' => 'strava', 'external_id' => '987', 'session_id' => $session->id]);

        $this->postJson('/coach/strava/webhook', $this->event(['aspect_type' => 'delete', 'object_id' => 987]))->assertNoContent();

        $this->assertModelMissing($activity);
        $this->assertSame('planned', $session->fresh()->status);
    }

    public function test_webhook_forgets_the_account_when_the_athlete_revokes_access(): void
    {
        $account = $this->account($this->athlete, 4242);

        $this->postJson('/coach/strava/webhook', $this->event(['object_type' => 'athlete', 'object_id' => 4242, 'aspect_type' => 'update', 'updates' => ['authorized' => 'false']]))
            ->assertNoContent();

        $this->assertModelMissing($account);
    }

    public function test_import_creates_the_run_completes_the_session_and_opens_a_debrief(): void
    {
        $this->travelTo('2026-11-17 19:00');
        Http::preventStrayRequests();
        $account = $this->account($this->athlete, 4242);
        (new Profile(['max_hr' => 190, 'resting_hr' => 50]))->user()->associate($this->athlete)->save();
        $session = RunningSession::factory()->for($this->athlete)->create(['scheduled_on' => '2026-11-17', 'title' => 'Footing 1h', 'pace_from' => 330, 'pace_to' => 345]);
        Http::fake([
            self::ACTIVITY_URL.'/streams*' => Http::response($this->streams(3600)),
            self::ACTIVITY_URL.'*' => Http::response($this->activity()),
        ]);

        $activity = app(StravaImporter::class)->import($account, 987);

        $this->assertSame([10000, 3600, 170, 'Run', 'g123', $session->id], [$activity->distance_m, $activity->moving_seconds, $activity->avg_cadence, $activity->sport_type, $activity->gear_id, $activity->session_id]);
        $this->assertSame('2026-11-17 18:00:00', $activity->started_at->toDateTimeString()); // heure locale
        $this->assertSame('done', $session->fresh()->status);
        $this->assertGreaterThan(5, $activity->analysis['drift_percent']);
        $this->assertSame(['actual_pace' => 360, 'gap' => 15], $activity->analysis['target']);
        $this->assertArrayHasKey('zone_seconds', $activity->analysis);
        $this->assertSame(['5K'], BestEffort::ownedBy($this->athlete)->pluck('name')->all()); // « 1K » ignoré
        $this->assertSame([655, true], [Gear::ownedBy($this->athlete)->sole()->km(), Gear::ownedBy($this->athlete)->sole()->primary]);
        $debrief = Conversation::sole();
        $this->assertSame(['debrief', 'pending', $activity->id], [$debrief->kind, $debrief->status, $debrief->activity_id]);
    }

    public function test_import_of_an_old_activity_or_an_update_opens_no_debrief(): void
    {
        $this->travelTo('2026-11-25 10:00'); // course du 17/11 : plus de 72 h
        $account = $this->account($this->athlete, 4242);
        Http::fake([self::ACTIVITY_URL.'/streams*' => Http::response([]), self::ACTIVITY_URL.'*' => Http::response($this->activity())]);

        app(StravaImporter::class)->import($account, 987);
        app(StravaImporter::class)->import($account, 987); // mise à jour

        $this->assertSame(1, RunningActivity::count());
        $this->assertDatabaseCount('coach_conversations', 0);
    }

    public function test_import_skips_activities_that_are_not_runs(): void
    {
        $account = $this->account($this->athlete, 4242);
        Http::fake([self::ACTIVITY_URL.'*' => Http::response(['sport_type' => 'Ride'] + $this->activity())]);

        $this->assertNull(app(StravaImporter::class)->import($account, 987));

        $this->assertDatabaseCount('coach_running_activities', 0);
        Http::assertSentCount(1); // pas de flux demandés
    }

    public function test_expired_token_is_renewed_before_calling_strava(): void
    {
        $account = $this->account($this->athlete, 4242, expiresAt: now()->subMinute());
        Http::fake([
            'https://www.strava.com/oauth/token' => Http::response(['access_token' => 'neuf', 'refresh_token' => 'renouv-2', 'expires_at' => now()->addHours(6)->timestamp]),
            self::ACTIVITY_URL.'*' => Http::response(['sport_type' => 'Ride']),
        ]);

        app(StravaImporter::class)->import($account, 987);

        $this->assertSame(['neuf', 'renouv-2'], [$account->fresh()->access_token, $account->fresh()->refresh_token]);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), self::ACTIVITY_URL) && $request->hasHeader('Authorization', 'Bearer neuf'));
    }

    public function test_rate_limited_import_is_retried_at_the_next_quarter_hour(): void
    {
        $this->travelTo('2026-11-17 10:07:00');
        $account = $this->account($this->athlete, 4242);
        Http::fake([self::ACTIVITY_URL.'*' => Http::response(['message' => 'Rate Limit Exceeded'], 429)]);
        Queue::fake([ImportStravaActivityJob::class]);

        (new ImportStravaActivityJob($account->id, 987))->handle(app(StravaImporter::class));

        Queue::assertPushed(ImportStravaActivityJob::class, fn (ImportStravaActivityJob $job) => $job->stravaId === 987
            && $job->delay->toDateTimeString() === '2026-11-17 10:15:30');
        $this->assertDatabaseCount('coach_running_activities', 0);
    }

    public function test_first_sync_imports_twelve_weeks_of_runs_without_debriefs(): void
    {
        $this->travelTo('2026-11-17 10:00');
        $account = $this->account($this->athlete, 4242);
        Queue::fake([ImportStravaActivityJob::class]);
        Http::fake([
            'https://www.strava.com/api/v3/athlete/activities*' => Http::response([
                ['id' => 1, 'sport_type' => 'Run'], ['id' => 2, 'sport_type' => 'Ride'], ['id' => 3, 'sport_type' => 'TrailRun'],
            ]),
            'https://www.strava.com/api/v3/athlete' => Http::response(['shoes' => [['id' => 'g9', 'name' => 'Pegasus', 'distance' => 120000, 'primary' => true]]]),
        ]);

        (new SyncStravaJob($account->id))->handle(app(StravaImporter::class));

        Queue::assertPushed(ImportStravaActivityJob::class, 2);
        Queue::assertPushed(ImportStravaActivityJob::class, fn (ImportStravaActivityJob $job) => in_array($job->stravaId, [1, 3], true) && $job->notify === false);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'after='.today()->subWeeks(12)->timestamp));
        $this->assertNotNull($account->fresh()->backfilled_at);
        $this->assertSame('Pegasus', Gear::ownedBy($this->athlete)->sole()->name);
    }

    public function test_athlete_can_disconnect_strava_and_keep_imported_runs(): void
    {
        $account = $this->account($this->athlete, 4242);
        $run = RunningActivity::factory()->for($this->athlete)->create(['source' => 'strava', 'external_id' => '1']);
        Http::fake(['https://www.strava.com/oauth/deauthorize' => Http::response([])]);

        $this->actingAs($this->athlete)->delete('/coach/strava')->assertRedirect(route('coach.profile.edit'));

        $this->assertModelMissing($account);
        $this->assertModelExists($run);
    }

    public function test_coach_is_told_not_to_ask_for_data_strava_collects(): void
    {
        $conversation = Conversation::factory()->for($this->athlete)->create();
        $session = RunningSession::factory()->for($this->athlete)->create(['scheduled_on' => today()->addDay()]);

        $this->actingAs($this->athlete)->get('/coach/running/calendrier')->assertSee('Je l\'ai faite', false);

        $this->account($this->athlete, 4242);
        $context = app(AthleteContext::class)->build($this->athlete, $conversation);
        $this->assertStringContainsString('Ne demande donc jamais à l\'athlète de relever ou de noter ces données', $context);
        $this->actingAs($this->athlete)->get('/coach/running/calendrier')
            ->assertSee('dès que ta course arrive de ta montre, la séance est validée')
            ->assertDontSee('Je l\'ai faite', false)
            ->assertSee(route('coach.running.activities.create', ['seance' => $session->id]), false);
    }

    public function test_runs_are_listed_newest_first_with_a_detail_page(): void
    {
        $older = RunningActivity::factory()->for($this->athlete)->create(['name' => 'Sortie de dimanche', 'started_at' => now()->subWeeks(5)]);
        $recent = RunningActivity::factory()->for($this->athlete)->create([
            'name' => 'Fractionné du mardi', 'source' => 'strava', 'external_id' => '555', 'started_at' => now()->subDay(),
            'laps' => [
                ['distance_m' => 1000, 'moving_s' => 300, 'avg_speed' => 3.333, 'avg_hr' => 150, 'max_hr' => 160],
                ['distance_m' => 1000, 'moving_s' => 240, 'avg_speed' => 4.167, 'avg_hr' => 172, 'max_hr' => 178],
            ],
            'analysis' => ['drift_percent' => 6.2],
        ]);
        RunningActivity::factory()->create(['name' => 'Course de quelqu\'un d\'autre']);

        $this->actingAs($this->athlete)->get('/coach/running/courses')
            ->assertOk()
            ->assertSeeInOrder(['Fractionné du mardi', 'Sortie de dimanche'])
            ->assertDontSee('Course de quelqu');

        $this->actingAs($this->athlete)->get("/coach/running/courses/{$recent->id}")
            ->assertOk()
            ->assertSee('4:00 /km')
            ->assertSee('Dérive cardiaque 6,2 %')
            ->assertSee('https://www.strava.com/activities/555', false)
            ->assertSee(route('coach.running.feedback.store', $recent), false); // pas encore de ressenti : formulaire proposé
        $this->assertModelExists($older);
    }

    public function test_dashboard_shows_the_five_latest_runs_with_links(): void
    {
        foreach (range(1, 6) as $daysAgo) {
            RunningActivity::factory()->for($this->athlete)->create(['name' => 'Course J-'.$daysAgo, 'started_at' => now()->subDays($daysAgo)]);
        }
        $latest = RunningActivity::ownedBy($this->athlete)->where('name', 'Course J-1')->sole();

        $this->actingAs($this->athlete)->get('/coach')
            ->assertSeeInOrder(['Historique', 'Course J-1', 'Course J-5'])
            ->assertDontSee('Course J-6')
            ->assertSee(route('coach.running.activities.show', $latest), false)
            ->assertSee(route('coach.running.activities.index'), false);
    }

    public function test_athlete_cannot_open_another_athletes_run(): void
    {
        $run = RunningActivity::factory()->create();

        $this->actingAs($this->athlete)->get("/coach/running/courses/{$run->id}")->assertNotFound();
    }

    public function test_profile_shows_the_strava_connection_state(): void
    {
        $this->actingAs($this->athlete)->get('/coach/profil')->assertSee('Relier Strava');

        $this->account($this->athlete, 4242);
        $this->actingAs($this->athlete)->get('/coach/profil')->assertSee('Relié au compte')->assertDontSee('Relier Strava');
    }

    private function account(User $user, int $athleteId, mixed $expiresAt = null): StravaAccount
    {
        $account = new StravaAccount([
            'athlete_id' => $athleteId, 'athlete_name' => 'Dada', 'access_token' => 'acces', 'refresh_token' => 'renouv',
            'expires_at' => $expiresAt ?? now()->addHours(5), 'last_synced_at' => now(),
        ]);
        $account->user()->associate($user)->save();

        return $account;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function event(array $overrides = []): array
    {
        return $overrides + ['object_type' => 'activity', 'object_id' => 987, 'aspect_type' => 'create', 'owner_id' => 4242, 'subscription_id' => 777, 'event_time' => now()->timestamp];
    }

    /**
     * @return array<string, mixed>
     */
    private function activity(): array
    {
        return [
            'id' => 987, 'name' => 'Footing du soir', 'sport_type' => 'Run', 'type' => 'Run',
            'start_date' => '2026-11-17T17:00:00Z', 'start_date_local' => '2026-11-17T18:00:00Z',
            'distance' => 10000.0, 'moving_time' => 3600, 'elapsed_time' => 3700, 'total_elevation_gain' => 42.4,
            'average_heartrate' => 147.6, 'max_heartrate' => 162.0, 'average_cadence' => 85.0, 'gear_id' => 'g123',
            'gear' => ['id' => 'g123', 'name' => 'Clifton 9', 'distance' => 655000.0, 'primary' => true],
            'laps' => [['distance' => 10000.0, 'moving_time' => 3600, 'average_speed' => 2.78, 'average_heartrate' => 147.6, 'max_heartrate' => 162.0]],
            'best_efforts' => [
                ['name' => '1K', 'distance' => 1000, 'elapsed_time' => 340],
                ['name' => '5K', 'distance' => 5000, 'elapsed_time' => 1790],
            ],
        ];
    }

    /**
     * Course régulière (allure constante) dont la FC monte de 140 à 160 : dérive cardiaque nette.
     *
     * @return array<string, array{data: list<float|int|bool>}>
     */
    private function streams(int $seconds): array
    {
        $range = range(0, $seconds - 1);

        return [
            'time' => ['data' => $range],
            'heartrate' => ['data' => array_map(fn (int $t) => 140 + 20 * $t / $seconds, $range)],
            'velocity_smooth' => ['data' => array_fill(0, $seconds, 2.78)],
            'moving' => ['data' => array_fill(0, $seconds, true)],
        ];
    }
}
