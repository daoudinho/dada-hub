<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Coach\Jobs\ImportGarminActivityJob;
use App\Modules\Coach\Jobs\SyncGarminJob;
use App\Modules\Coach\Models\BestEffort;
use App\Modules\Coach\Models\Conversation;
use App\Modules\Coach\Models\DailyWellness;
use App\Modules\Coach\Models\GarminAccount;
use App\Modules\Coach\Models\Profile;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Models\RunningSession;
use App\Modules\Coach\Services\AthleteContext;
use App\Modules\Coach\Services\Garmin\GarminImporter;
use App\Modules\Coach\Services\TrainingLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CoachGarminTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'gk_live_0123456789abcdefWXYZ';

    private const API = 'https://personal.connectapi.garmin.com';

    private User $athlete;

    protected function setUp(): void
    {
        parent::setUp();

        $this->athlete = User::factory()->withModules('coach')->withFeatures(['coach' => ['running']])->create();
    }

    public function test_athlete_saves_an_encrypted_key_that_starts_the_first_sync(): void
    {
        Queue::fake([SyncGarminJob::class]);

        $this->actingAs($this->athlete)->put('/coach/garmin', ['api_key' => '  '.self::KEY.' ', 'key_expires_on' => today()->addYear()->toDateString()])
            ->assertRedirect(route('coach.profile.edit'));

        $account = GarminAccount::ownedBy($this->athlete)->sole();
        $this->assertSame(self::KEY, $account->api_key);
        $this->assertNotSame(self::KEY, DB::table('coach_garmin_accounts')->value('api_key'));
        Queue::assertPushed(SyncGarminJob::class, fn (SyncGarminJob $job) => $job->accountId === $account->id);
        $this->actingAs($this->athlete)->get('/coach/profil')
            ->assertSee('Clé enregistrée')
            ->assertSee('…WXYZ')
            ->assertDontSee(self::KEY);
    }

    public function test_replacing_the_key_keeps_one_account_and_drops_the_old_token(): void
    {
        Queue::fake([SyncGarminJob::class]);
        $this->actingAs($this->athlete)->put('/coach/garmin', ['api_key' => self::KEY]);
        GarminAccount::ownedBy($this->athlete)->sole()->update(['access_token' => 'ancien', 'token_expires_at' => now()->addHours(5)]);

        $this->actingAs($this->athlete)->put('/coach/garmin', ['api_key' => 'gk_live_nouvelle_cle_9876543210']);

        $account = GarminAccount::ownedBy($this->athlete)->sole();
        $this->assertSame(['gk_live_nouvelle_cle_9876543210', null], [$account->api_key, $account->access_token]);
    }

    public function test_key_must_be_complete_and_without_spaces(): void
    {
        Queue::fake([SyncGarminJob::class]);

        $this->actingAs($this->athlete)->put('/coach/garmin', ['api_key' => 'trop court'])
            ->assertSessionHasErrors(['api_key' => 'La clé ne doit pas contenir d\'espace : colle-la telle que Garmin l\'affiche.']);
        $this->actingAs($this->athlete)->put('/coach/garmin', ['api_key' => 'abc123'])
            ->assertSessionHasErrors(['api_key' => 'Cette clé semble incomplète : copie-la en entier depuis Garmin Connect.']);

        $this->assertDatabaseCount('coach_garmin_accounts', 0);
        Queue::assertNothingPushed();
    }

    public function test_profile_warns_when_the_key_expires_soon_or_has_expired(): void
    {
        $this->travelTo('2026-11-23 10:00');
        $this->account(['key_expires_on' => '2026-11-30']);

        $this->actingAs($this->athlete)->get('/coach/profil')->assertSee('Ta clé expire le 30/11/2026');

        $this->travelTo('2026-12-02 10:00');
        $this->actingAs($this->athlete)->get('/coach/profil')->assertSee('Ta clé a expiré le 30/11/2026');
    }

    public function test_each_athlete_manages_only_their_own_key(): void
    {
        Queue::fake([SyncGarminJob::class]);
        $other = User::factory()->withModules('coach')->create();
        (new GarminAccount(['api_key' => 'gk_live_cle_de_quelquun_dautre']))->user()->associate($other)->save();

        $this->actingAs($this->athlete)->put('/coach/garmin', ['api_key' => self::KEY]);
        $this->actingAs($this->athlete)->delete('/coach/garmin');

        $this->assertSame(0, GarminAccount::ownedBy($this->athlete)->count());
        $this->assertSame('gk_live_cle_de_quelquun_dautre', GarminAccount::ownedBy($other)->sole()->api_key);
    }

    public function test_first_sync_fills_the_profile_and_recovery_and_queues_two_years_of_runs(): void
    {
        $this->travelTo('2026-11-23 10:00');
        Http::preventStrayRequests();
        Queue::fake([ImportGarminActivityJob::class]);
        $account = $this->account();
        $this->fakeGarmin([
            '/biometric-service/heartRateZones/v2*' => [[
                'sport' => 'RUNNING', 'maxHeartRateUsed' => 192, 'restingHeartRateUsed' => 54, 'lactateThresholdHeartRateUsed' => 172,
                'zone1Floor' => 100, 'zone2Floor' => 125, 'zone3Floor' => 145, 'zone4Floor' => 165, 'zone5Floor' => 180,
            ]],
            '/metrics-service/metrics/maxmet/daily/*' => [['generic' => ['calendarDate' => '2026-11-20', 'vo2MaxPreciseValue' => 49.4]]],
            '/usersummary-service/v2/dailysummaries*' => ['data' => [[
                'calendarDate' => '2026-11-22', 'heartRate' => ['restingValue' => 57],
                'bodyBattery' => ['minValue' => 22, 'maxValue' => 57, 'chargedValue' => 17, 'drainedValue' => 42], 'stress' => ['avgLevel' => 23],
            ]]],
            '/sleep-service/sleep/dailySleepSummariesByDate*' => [
                ['calendarDate' => '2026-11-22', 'sleepTimeSeconds' => 25200, 'deepSleepSeconds' => 5400, 'sleepScores' => ['overall' => ['value' => 81]]],
                ['calendarDate' => null, 'sleepTimeSeconds' => null],
            ],
            '/hrv-service/hrv/daily/*' => ['hrvSummaries' => [['calendarDate' => '2026-11-22', 'lastNightAvg' => 62, 'weeklyAvg' => 80, 'status' => 'UNBALANCED']]],
            '/activitylist-service/activities/search/activities*' => [
                $this->summary(['activityId' => 1, 'startTimeLocal' => '2025-02-01 09:00:00']),
                $this->summary(['activityId' => 2, 'activityType' => ['typeKey' => 'yoga']]),
                $this->summary(['activityId' => 3, 'activityType' => ['typeKey' => 'trail_running']]),
            ],
        ]);

        (new SyncGarminJob($account->id))->handle(app(GarminImporter::class));

        $profile = Profile::ownedBy($this->athlete)->sole();
        $this->assertSame([192, 54, 172, [100, 125, 145, 165, 180], 49.4], [$profile->max_hr, $profile->resting_hr, $profile->threshold_hr, $profile->hr_zone_floors, $profile->vo2max]);
        $day = DailyWellness::ownedBy($this->athlete)->sole();
        $this->assertSame([57, 57, 23, 25200, 81, 62, 'UNBALANCED'], [$day->resting_hr, $day->body_battery_max, $day->stress_avg, $day->sleep_seconds, $day->sleep_score, $day->hrv_last_night, $day->hrv_status]);
        Queue::assertPushed(ImportGarminActivityJob::class, 2);
        Queue::assertNotPushed(ImportGarminActivityJob::class, fn (ImportGarminActivityJob $job) => $job->summary['activityId'] === 2 || $job->notify);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'activities/search/activities') && str_contains($request->url(), 'startDate=2024-11-23'));
        $this->assertSame(1, Http::recorded(fn (Request $request) => str_contains($request->url(), 'personalapitoken-service'))->count()); // un seul échange de clé
        $this->assertNotNull($account->fresh()->backfilled_at);
        $this->assertSame(2, $account->fresh()->history_total); // pour l'avancement de l'import
        $this->assertNull($account->fresh()->last_error);
    }

    public function test_import_builds_the_run_with_laps_track_dynamics_and_a_debrief(): void
    {
        $this->travelTo('2026-11-20 19:00');
        Http::preventStrayRequests();
        $account = $this->account();
        (new Profile(['max_hr' => 192, 'hr_zone_floors' => [100, 125, 145, 165, 180]]))->user()->associate($this->athlete)->save();
        $session = RunningSession::factory()->for($this->athlete)->create(['scheduled_on' => '2026-11-20', 'title' => 'Footing 5 km']);
        $this->fakeGarmin(['/activity-service/activity/25143155630/details*' => $this->details(1800, 2.8)]);

        $activity = app(GarminImporter::class)->import($account, $this->summary());

        $this->assertSame(['garmin', '25143155630', 5022, 1745, 150, 160, $session->id], [
            $activity->source, $activity->external_id, $activity->distance_m, $activity->moving_seconds, $activity->avg_hr, $activity->avg_cadence, $activity->session_id,
        ]);
        $this->assertSame('2026-11-20 17:11:07', $activity->started_at->toDateTimeString());
        $this->assertCount(5, $activity->laps); // 5 040 m : 5 tours de 1 km, les 40 m restants (< 100 m) ignorés
        $this->assertEqualsWithDelta(1000, $activity->laps[0]['distance_m'], 3); // passage du km entre deux mesures
        $this->assertGreaterThan(1, count($activity->polyline));
        $this->assertSame([172.4, 'TEMPO', [18, 98, 799, 830, 0]], [$activity->dynamics['stride_length_cm'] ?? null, $activity->dynamics['effect_label'], $activity->dynamics['garmin_zone_seconds']]);
        $this->assertArrayHasKey('zone_seconds', $activity->analysis);
        $this->assertSame(['1K', '5K'], BestEffort::where('activity_id', $activity->id)->orderBy('distance_m')->pluck('name')->all());
        $this->assertSame('done', $session->fresh()->status);
        $this->assertSame('debrief', Conversation::sole()->kind);
        $this->assertArrayHasKey('activityId', $activity->raw_summary);
        $this->assertStringContainsString('Garmin', BestEffort::where('activity_id', $activity->id)->first()->label());
    }

    public function test_a_synced_run_that_beats_a_record_is_flagged_for_the_athlete_and_the_coach(): void
    {
        $this->travelTo('2026-11-20 19:00');
        Http::preventStrayRequests();
        $account = $this->account();
        $old = RunningActivity::factory()->for($this->athlete)->create(['name' => 'Ancien 5 km', 'distance_m' => 5100, 'started_at' => '2026-10-01 08:00']);
        (new BestEffort(['activity_id' => $old->id, 'name' => '5K', 'distance_m' => 5000, 'elapsed_seconds' => 1900, 'achieved_on' => '2026-10-01']))->user()->associate($this->athlete)->save();
        $this->fakeGarmin(['/activity-service/activity/25143155630/details*' => $this->details(1800, 2.8)]);

        $activity = app(GarminImporter::class)->import($account, $this->summary());

        $records = collect($activity->fresh()->new_records)->keyBy('key');
        $this->assertSame(['value' => '29:46', 'previous' => '31:40'], collect($records['5K'])->only(['value', 'previous'])->all());
        $this->assertArrayNotHasKey('longest', $records->all()); // 5,04 km < 5,10 km
        $this->actingAs($this->athlete)->get('/coach/running/courses')->assertSee('Nouveau record : Meilleur 5 kilomètres en 29:46');
        $this->actingAs($this->athlete)->get('/coach')->assertSee('🏆 Record');
        $context = app(AthleteContext::class)->build($this->athlete, Conversation::sole());
        $this->assertStringContainsString('🏆 Nouveau record — Meilleur 5 kilomètres : 29:46 (ancien record 31:40)', $context);
    }

    public function test_history_imports_and_a_very_first_run_are_not_flagged_as_records(): void
    {
        Http::preventStrayRequests();
        $account = $this->account();
        $this->fakeGarmin(['/activity-service/activity/*' => $this->details(1800, 2.8)]);

        $first = app(GarminImporter::class)->import($account, $this->summary()); // première course : rien à battre
        $backfilled = app(GarminImporter::class)->import($account, $this->summary(['activityId' => 2, 'distance' => 9000.0]), notify: false);

        $this->assertNull($first->fresh()->new_records);
        $this->assertNull($backfilled->fresh()->new_records);
    }

    public function test_an_implausible_best_effort_from_garmin_is_ignored(): void
    {
        Http::preventStrayRequests();
        $account = $this->account();
        $this->fakeGarmin(['/activity-service/activity/25143155630/details*' => []]);

        // 5 km « en 31:57 » dans une course de 5,02 km bouclée en 29 min : impossible.
        $activity = app(GarminImporter::class)->import($account, $this->summary(['fastestSplit_5000' => 1917.0]), notify: false);

        $this->assertSame(['1K'], BestEffort::where('activity_id', $activity->id)->pluck('name')->all());
    }

    public function test_activity_page_shows_the_route_map_and_running_dynamics(): void
    {
        $activity = RunningActivity::factory()->for($this->athlete)->create([
            'source' => 'garmin', 'external_id' => '42',
            'polyline' => [[48.85, 2.35], [48.86, 2.36]],
            'dynamics' => ['ground_contact_ms' => 245, 'aerobic_effect' => 3.1, 'anaerobic_effect' => 0.4, 'effect_label' => 'TEMPO'],
        ]);

        $this->actingAs($this->athlete)->get("/coach/running/courses/{$activity->id}")
            ->assertOk()
            ->assertSee('leaflet', false)
            ->assertSee('contact au sol 245 ms')
            ->assertSee('https://connect.garmin.com/modern/activity/42', false);
    }

    public function test_activity_page_compares_the_run_at_equal_heart_rate_and_shows_the_day_context(): void
    {
        $startedAt = now()->subDay()->setTime(8, 0);
        foreach ([5, 6] as $weeksAgo) {
            RunningActivity::factory()->for($this->athlete)->create([
                'started_at' => $startedAt->copy()->subWeeks($weeksAgo), 'distance_m' => 8000, 'moving_seconds' => 8 * 370, 'avg_hr' => 146, 'analysis' => null,
            ]);
        }
        $activity = RunningActivity::factory()->for($this->athlete)->create([
            'started_at' => $startedAt, 'distance_m' => 8000, 'moving_seconds' => 8 * 355, 'avg_hr' => 145, 'analysis' => null,
        ]);
        (new DailyWellness(['day' => $startedAt->toDateString(), 'hrv_last_night' => 42, 'hrv_weekly_avg' => 48, 'hrv_status' => 'UNBALANCED', 'body_battery_max' => 61]))
            ->user()->associate($this->athlete)->save();

        $this->actingAs($this->athlete)->get(route('coach.running.activities.show', $activity))
            ->assertOk()
            ->assertSee('À FC comparable (~145 bpm) : 5:55 /km contre 6:10 /km sur 2 courses')
            ->assertSee('15 s/km plus rapide')
            ->assertSee('VFC 42 ms (moyenne 7 j : 48, déséquilibrée)')
            ->assertSee('Body Battery max 61')
            ->assertSee('Donne d\'abord ton ressenti', false);
    }

    public function test_refused_key_is_reported_on_the_profile(): void
    {
        Http::preventStrayRequests();
        $account = $this->account();
        Http::fake([self::API.'/personalapitoken-service/*' => Http::response('{"error":"Unauthorized"}', 401)]);

        (new SyncGarminJob($account->id))->handle(app(GarminImporter::class));

        $this->assertStringContainsString('Garmin refuse la clé', $account->fresh()->last_error);
        $this->actingAs($this->athlete)->get('/coach/profil')->assertSee('Dernière synchronisation impossible');
    }

    public function test_recovery_signals_flag_a_rising_resting_heart_rate(): void
    {
        $this->travelTo('2026-11-23 10:00');
        foreach (range(8, 30) as $daysAgo) {
            (new DailyWellness(['day' => today()->subDays($daysAgo), 'resting_hr' => 52]))->user()->associate($this->athlete)->save();
        }
        foreach ([0, 1, 2] as $daysAgo) {
            (new DailyWellness(['day' => today()->subDays($daysAgo), 'resting_hr' => 59, 'body_battery_max' => 30]))->user()->associate($this->athlete)->save();
        }

        $signals = implode("\n", app(TrainingLoad::class)->recoverySignals($this->athlete));

        $this->assertStringContainsString('FC de repos à 59 en moyenne ces derniers jours, contre 52', $signals);
        $this->assertStringContainsString('Body Battery qui ne remonte pas au-dessus de 40', $signals);
        $this->actingAs($this->athlete)->get('/coach')->assertSee('Récupération')->assertSee('FC de repos à 59');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function account(array $attributes = []): GarminAccount
    {
        $account = (new GarminAccount($attributes + ['api_key' => self::KEY]))->user()->associate($this->athlete);
        $account->save();

        return $account;
    }

    /**
     * Réponses Garmin simulées ; l'échange de clé renvoie un jeton en texte brut (JWT), comme l'API réelle.
     *
     * @param  array<string, mixed>  $routes  chemin → corps JSON
     */
    private function fakeGarmin(array $routes): void
    {
        $payload = rtrim(strtr(base64_encode(json_encode(['exp' => now()->addDay()->timestamp])), '+/', '-_'), '=');
        $fakes = [self::API.'/personalapitoken-service/*' => Http::response('eyJhbGciOiJSUzI1NiJ9.'.$payload.'.signature', 200, ['Content-Type' => 'text/plain'])];
        foreach ($routes as $path => $body) {
            $fakes[self::API.$path] = Http::response($body);
        }
        Http::fake($fakes);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function summary(array $overrides = []): array
    {
        return $overrides + [
            'activityId' => 25143155630, 'activityName' => 'Footing du soir', 'startTimeLocal' => '2026-11-20 17:11:07',
            'activityType' => ['typeKey' => 'running'], 'distance' => 5022.4, 'duration' => 1745.2, 'movingDuration' => 1745.0, 'elapsedDuration' => 1790.3,
            'averageHR' => 150.0, 'maxHR' => 171.0, 'averageRunningCadenceInStepsPerMinute' => 160.2, 'elevationGain' => 12.0,
            'avgStrideLength' => 172.42, 'avgGroundContactTime' => 251.3, 'aerobicTrainingEffect' => 3.2, 'trainingEffectLabel' => 'TEMPO',
            'hrTimeInZone_1' => 18.0, 'hrTimeInZone_2' => 97.5, 'hrTimeInZone_3' => 799.1, 'hrTimeInZone_4' => 830.0, 'hrTimeInZone_5' => 0.0,
            'fastestSplit_1000' => 330.5, 'fastestSplit_5000' => 1735.8,
        ];
    }

    /**
     * Mesures seconde par seconde : vitesse constante, FC qui monte, petit déplacement GPS.
     *
     * @return array<string, mixed>
     */
    private function details(int $seconds, float $speed): array
    {
        $keys = ['sumElapsedDuration', 'sumDistance', 'directHeartRate', 'directSpeed', 'directRunCadence', 'directElevation', 'directLatitude', 'directLongitude'];

        return [
            'metricDescriptors' => array_map(fn (string $key, int $index) => ['key' => $key, 'metricsIndex' => $index], $keys, array_keys($keys)),
            'activityDetailMetrics' => array_map(fn (int $t) => ['metrics' => [
                $t, $t * $speed, 140 + 20 * $t / $seconds, $speed, 160, 35.0, 48.85 + $t * 0.00001, 2.35 + $t * 0.00001,
            ]], range(0, $seconds)),
        ];
    }
}
