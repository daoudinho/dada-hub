<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Coach\Jobs\ImportGoogleHealthActivityJob;
use App\Modules\Coach\Jobs\SyncGoogleHealthJob;
use App\Modules\Coach\Models\Conversation;
use App\Modules\Coach\Models\GoogleHealthAccount;
use App\Modules\Coach\Models\Profile;
use App\Modules\Coach\Models\RunningSession;
use App\Modules\Coach\Services\GoogleHealth\GoogleHealthClient;
use App\Modules\Coach\Services\GoogleHealth\GoogleHealthImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CoachGoogleHealthTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://health.googleapis.com/v4';

    private const BOTH_SCOPES = GoogleHealthClient::ACTIVITY_SCOPE.' '.GoogleHealthClient::HEART_RATE_SCOPE;

    private User $athlete;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google_health.client_id' => 'client-123', 'services.google_health.client_secret' => 'secret']);
        $this->athlete = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create();
    }

    public function test_connect_sends_the_athlete_to_google_for_offline_access_with_a_state(): void
    {
        $response = $this->actingAs($this->athlete)->get('/coach/google-health/connexion');

        $location = $response->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(GoogleHealthClient::AUTHORIZE_URL.'?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(['client-123', self::BOTH_SCOPES, route('coach.google-health.callback'), 'offline', 'consent'], [
            $query['client_id'], $query['scope'], $query['redirect_uri'], $query['access_type'], $query['prompt'],
        ]);
        $this->assertSame(session('google_health_state'), $query['state']);
    }

    public function test_callback_links_the_account_with_encrypted_tokens_and_starts_the_import(): void
    {
        Queue::fake([SyncGoogleHealthJob::class]);
        Http::preventStrayRequests();
        Http::fake([
            GoogleHealthClient::TOKEN_URL => Http::response(['access_token' => 'acces-1', 'refresh_token' => 'renouv-1', 'expires_in' => 3599, 'scope' => self::BOTH_SCOPES]),
            self::API.'/users/me/identity' => Http::response(['name' => 'users/me/identity', 'healthUserId' => '4437698108461627695']),
        ]);

        $this->actingAs($this->athlete)->withSession(['google_health_state' => 'etat'])
            ->get('/coach/google-health/retour?state=etat&code=abc')
            ->assertRedirect(route('coach.profile.edit'));

        $account = GoogleHealthAccount::ownedBy($this->athlete)->sole();
        $this->assertSame(['4437698108461627695', 'acces-1', 'renouv-1'], [$account->health_user_id, $account->access_token, $account->refresh_token]);
        $this->assertTrue($account->canReadHeartRate());
        $this->assertNotSame('renouv-1', DB::table('coach_google_health_accounts')->value('refresh_token'));
        Queue::assertPushed(SyncGoogleHealthJob::class, fn (SyncGoogleHealthJob $job) => $job->accountId === $account->id);
        Http::assertSent(fn (Request $request) => $request->url() === GoogleHealthClient::TOKEN_URL
            && $request['code'] === 'abc' && $request['redirect_uri'] === route('coach.google-health.callback'));
    }

    public function test_callback_rejects_a_forged_state(): void
    {
        Http::preventStrayRequests();

        $this->actingAs($this->athlete)->withSession(['google_health_state' => 'etat'])
            ->get('/coach/google-health/retour?state=autre&code=abc')
            ->assertForbidden();

        $this->assertDatabaseCount('coach_google_health_accounts', 0);
    }

    public function test_callback_requires_access_to_activities(): void
    {
        Http::preventStrayRequests();
        Http::fake([GoogleHealthClient::TOKEN_URL => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3599, 'scope' => GoogleHealthClient::HEART_RATE_SCOPE])]);

        $this->actingAs($this->athlete)->withSession(['google_health_state' => 'etat'])
            ->get('/coach/google-health/retour?state=etat&code=abc')
            ->assertSessionHas('status', 'Pour que le coach voie tes courses, laisse cochée l\'autorisation « activité et forme physique ».');

        $this->assertDatabaseCount('coach_google_health_accounts', 0);
    }

    public function test_callback_refuses_a_google_account_already_linked_to_someone_else(): void
    {
        Queue::fake([SyncGoogleHealthJob::class]);
        $this->account(['health_user_id' => '42'], User::factory()->withModules('coach')->create());
        Http::fake([
            GoogleHealthClient::TOKEN_URL => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3599, 'scope' => self::BOTH_SCOPES]),
            self::API.'/users/me/identity' => Http::response(['healthUserId' => '42']),
        ]);

        $this->actingAs($this->athlete)->withSession(['google_health_state' => 'etat'])
            ->get('/coach/google-health/retour?state=etat&code=abc')
            ->assertSessionHas('status', 'Ce compte Google est déjà relié à un autre compte du hub.');

        $this->assertSame(0, GoogleHealthAccount::ownedBy($this->athlete)->count());
        Queue::assertNothingPushed();
    }

    public function test_first_sync_queues_only_the_runs_of_the_history_without_debriefs(): void
    {
        $this->travelTo('2026-11-25 22:00');
        Http::preventStrayRequests();
        Queue::fake([ImportGoogleHealthActivityJob::class]);
        $account = $this->account();
        Http::fake([self::API.'/users/me/dataTypes/exercise/dataPoints*' => Http::sequence()
            ->push(['dataPoints' => [$this->point(['exerciseType' => 'RUNNING']), $this->point(['exerciseType' => 'WALKING'], id: '2')], 'nextPageToken' => 'page-2'])
            ->push(['dataPoints' => [$this->point(['exerciseType' => 'TREADMILL'], id: '3')]]),
        ]);

        (new SyncGoogleHealthJob($account->id))->handle(app(GoogleHealthImporter::class));

        Queue::assertPushed(ImportGoogleHealthActivityJob::class, 2);
        Queue::assertNotPushed(ImportGoogleHealthActivityJob::class, fn (ImportGoogleHealthActivityJob $job) => str_ends_with($job->point['name'], '/2') || $job->notify);
        Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'exercise.interval.civil_start_time >= "2024-11-25"'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'pageToken=page-2'));
        $this->assertNotNull($account->fresh()->backfilled_at);
    }

    public function test_import_builds_the_run_with_its_heart_rate_curve_and_opens_a_debrief(): void
    {
        $this->travelTo('2026-11-25 22:00');
        Http::preventStrayRequests();
        $account = $this->account();
        (new Profile(['max_hr' => 192, 'hr_zone_floors' => [100, 125, 145, 165, 180]]))->user()->associate($this->athlete)->save();
        $session = RunningSession::factory()->for($this->athlete)->create(['scheduled_on' => '2026-11-25', 'title' => 'Footing 7 km']);
        $samples = collect(range(0, 2580, 5))->map(fn (int $second) => ['heartRate' => [
            'sampleTime' => ['physicalTime' => now()->parse('2026-11-25T17:54:41Z')->addSeconds($second)->format('Y-m-d\TH:i:s\Z'), 'utcOffset' => '3600s'],
            'beatsPerMinute' => (string) (140 + intdiv($second, 100)),
        ]])->all();
        Http::fake([self::API.'/users/me/dataTypes/heart-rate/dataPoints*' => Http::response(['dataPoints' => $samples])]);

        $activity = app(GoogleHealthImporter::class)->import($account, $this->point());

        $this->assertSame(['google_health', '2797943045155539016', 'RUNNING', 6938, 2591, 163, 165, $session->id], [
            $activity->source, $activity->external_id, $activity->sport_type, $activity->distance_m, $activity->moving_seconds, $activity->avg_hr, $activity->max_hr, $activity->session_id,
        ]);
        $this->assertSame('2026-11-25 18:54:41', $activity->started_at->toDateTimeString()); // heure locale (UTC+1)
        $this->assertSame(2591, $activity->elapsed_seconds);
        $this->assertSame([0, 5], array_slice($activity->streams['time'], 0, 2));
        $this->assertArrayHasKey('zone_seconds', $activity->analysis);
        $this->assertSame('Google Health', $activity->sourceLabel());
        $this->assertSame('done', $session->fresh()->status);
        $this->assertSame('debrief', Conversation::sole()->kind);
        Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'heart_rate.sample_time.physical_time >= "2026-11-25T17:54:41Z"'));
    }

    public function test_reimporting_a_run_updates_it_instead_of_duplicating_it(): void
    {
        $this->travelTo('2026-11-25 22:00');
        Http::preventStrayRequests();
        $account = $this->account(['scopes' => GoogleHealthClient::ACTIVITY_SCOPE]); // sans la FC : aucune requête

        $importer = app(GoogleHealthImporter::class);
        $first = $importer->import($account, $this->point());
        $second = $importer->import($account, $this->point(['metricsSummary' => ['distanceMillimeters' => 7000000]]));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(7000, $second->fresh()->distance_m);
        $this->assertNull($second->streams);
        $this->assertSame(1, Conversation::count());
    }

    public function test_expired_authorization_asks_the_athlete_to_reconnect(): void
    {
        Http::preventStrayRequests();
        Http::fake([GoogleHealthClient::TOKEN_URL => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);
        $account = $this->account(['expires_at' => now()->subMinute()]);

        (new SyncGoogleHealthJob($account->id))->handle(app(GoogleHealthImporter::class));

        $account->refresh();
        $this->assertTrue($account->needsReconnect());
        $this->assertNull($account->last_synced_at);
        $this->actingAs($this->athlete)->get('/coach/profil')
            ->assertSee('L\'autorisation Google a expiré', false)
            ->assertSee(route('coach.google-health.connect'));
        $this->actingAs($this->athlete)->post('/coach/google-health/synchroniser')->assertRedirect(route('coach.profile.edit'));
    }

    public function test_disconnect_revokes_the_access_and_keeps_imported_runs(): void
    {
        Http::fake([GoogleHealthClient::REVOKE_URL => Http::response()]);
        $account = $this->account(['scopes' => GoogleHealthClient::ACTIVITY_SCOPE]);
        $this->travelTo('2026-11-25 22:00');
        app(GoogleHealthImporter::class)->import($account, $this->point());

        $this->actingAs($this->athlete)->delete('/coach/google-health')->assertRedirect(route('coach.profile.edit'));

        $this->assertDatabaseCount('coach_google_health_accounts', 0);
        $this->assertDatabaseCount('coach_running_activities', 1);
        Http::assertSent(fn (Request $request) => $request->url() === GoogleHealthClient::REVOKE_URL && $request['token'] === 'renouv');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function account(array $attributes = [], ?User $user = null): GoogleHealthAccount
    {
        $account = (new GoogleHealthAccount($attributes + [
            'health_user_id' => '4437698108461627695', 'access_token' => 'acces', 'refresh_token' => 'renouv',
            'expires_at' => now()->addHour(), 'scopes' => self::BOTH_SCOPES,
        ]))->user()->associate($user ?? $this->athlete);
        $account->save();

        return $account;
    }

    /**
     * Séance « exercise » telle que Google Health la renvoie pour une course Samsung Health.
     *
     * @param  array<string, mixed>  $exercise  champs de la séance remplacés
     * @return array<string, mixed>
     */
    private function point(array $exercise = [], string $id = '2797943045155539016'): array
    {
        return [
            'name' => 'users/4437698108461627695/dataTypes/exercise/dataPoints/'.$id,
            'exercise' => $exercise + [
                'displayName' => 'Course',
                'exerciseType' => 'RUNNING',
                'interval' => ['startUtcOffset' => '3600s', 'endUtcOffset' => '3600s', 'startTime' => '2026-11-25T17:54:41.760Z', 'endTime' => '2026-11-25T18:37:52.430Z'],
                'exerciseMetadata' => [],
                'metricsSummary' => ['distanceMillimeters' => 6938004, 'averageHeartRateBeatsPerMinute' => '163', 'averagePaceSecondsPerMeter' => 0.3733062131414165],
                'activeDuration' => '2590.670s',
            ],
            'dataSource' => ['device' => [], 'recordingMethod' => 'UNKNOWN', 'platform' => 'HEALTH_CONNECT', 'application' => ['packageName' => 'com.sec.android.app.shealth']],
        ];
    }
}
