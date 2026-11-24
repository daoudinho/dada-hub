<?php

namespace App\Modules\Coach\Services\Garmin;

use App\Modules\Coach\Models\BestEffort;
use App\Modules\Coach\Models\DailyWellness;
use App\Modules\Coach\Models\GarminAccount;
use App\Modules\Coach\Models\Profile;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Services\CoachConversation;
use App\Modules\Coach\Services\ReferencePaces;
use App\Modules\Coach\Services\RunningRecords;
use App\Modules\Coach\Services\SessionMatcher;
use App\Modules\Coach\Services\Strava\ActivityAnalysis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Import des données Garmin : courses (résumé, courbes seconde par seconde, tours au km calculés, tracé GPS, dynamique,
 * analyse), zones cardiaques et VO2 max du profil, récupération quotidienne. Seules les courses à pied sont importées.
 */
class GarminImporter
{
    /** Nombre maximal de mesures demandées par course (environ 2 h 45 à une mesure par seconde). */
    private const MAX_CHART_SIZE = 10000;

    /** Points gardés pour le tracé GPS. */
    private const POLYLINE_POINTS = 600;

    /** Meilleurs temps fournis par Garmin → noms utilisés par l'application. */
    private const BEST_EFFORTS = ['fastestSplit_1000' => ['1K', 1000], 'fastestSplit_5000' => ['5K', 5000], 'fastestSplit_10000' => ['10K', 10000]];

    public function __construct(
        private GarminClient $client,
        private ActivityAnalysis $analysis,
        private ReferencePaces $referencePaces,
        private CoachConversation $coach,
        private RunningRecords $records,
    ) {}

    /**
     * Résumés des courses d'une période (jours inclus).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function runsBetween(GarminAccount $account, Carbon $from, Carbon $to): Collection
    {
        $runs = collect();
        for ($start = 0; $start < 3000; $start += 100) {
            $page = $this->client->get($account, '/activitylist-service/activities/search/activities', [
                'start' => $start, 'limit' => 100, 'startDate' => $from->toDateString(), 'endDate' => $to->toDateString(),
            ]);
            $runs = $runs->concat(array_filter($page, fn (array $activity) => self::isRun($activity)));
            if (count($page) < 100) {
                break;
            }
        }

        return $runs->values();
    }

    /**
     * Importe (ou met à jour) une course à partir de son résumé Garmin.
     *
     * @param  array<string, mixed>  $summary  élément de la liste des activités
     */
    public function import(GarminAccount $account, array $summary, bool $notify = true): ?RunningActivity
    {
        if (! self::isRun($summary)) {
            return null;
        }

        $user = $account->user;
        $id = (string) $summary['activityId'];
        $startedAt = Carbon::parse($summary['startTimeLocal']);
        $streams = $this->streams($account, $id);
        $laps = $this->kilometreLaps($streams);
        $existing = RunningActivity::ownedBy($user)->where('source', 'garmin')->where('external_id', $id)->first();

        return DB::transaction(function () use ($user, $summary, $id, $startedAt, $streams, $laps, $existing, $notify) {
            [$session, $suggestedId] = SessionMatcher::forImport($user, $startedAt, $existing, $notify);

            $activity = $existing ?? (new RunningActivity(['source' => 'garmin', 'external_id' => $id]))->user()->associate($user);
            $activity->fill([
                'session_id' => $session?->id,
                'suggested_session_id' => $suggestedId,
                'name' => mb_substr((string) ($summary['activityName'] ?? ''), 0, 150) ?: null,
                'sport_type' => $summary['activityType']['typeKey'] ?? null,
                'started_at' => $startedAt,
                'distance_m' => (int) round($summary['distance'] ?? 0),
                'moving_seconds' => max(60, (int) round($summary['movingDuration'] ?? $summary['duration'] ?? 0)),
                'elapsed_seconds' => isset($summary['elapsedDuration']) ? (int) round($summary['elapsedDuration']) : null,
                'avg_hr' => isset($summary['averageHR']) ? (int) round($summary['averageHR']) : null,
                'max_hr' => isset($summary['maxHR']) ? (int) round($summary['maxHR']) : null,
                'avg_cadence' => isset($summary['averageRunningCadenceInStepsPerMinute']) ? (int) round($summary['averageRunningCadenceInStepsPerMinute']) : null,
                'elevation_gain_m' => isset($summary['elevationGain']) ? (int) round($summary['elevationGain']) : null,
                'laps' => $laps ?: null,
                'streams' => collect($streams)->except(['latitude', 'longitude'])->all() ?: null,
                'polyline' => $this->polyline($streams) ?: null,
                'dynamics' => $this->dynamics($summary) ?: null,
                'raw_summary' => $summary,
            ]);
            $activity->analysis = $this->analysis->analyzeActivity($activity, $session, $this->referencePaces->zonesFor($user));
            $activity->save();

            $session?->update(['status' => 'done']);
            $this->saveBestEfforts($activity, $summary, $startedAt);

            $isRecent = $startedAt->isAfter(now()->subHours((int) config('coach.strava.debrief_within_hours')));
            // Nouvelle course (hors historique importé) : comparée aux records avant le débrief.
            if (! $existing && $notify) {
                $this->records->markNewRecords($activity);
            }
            if (! $existing && $notify && $isRecent) {
                $this->coach->startDebrief($user, $activity);
            }

            return $activity;
        });
    }

    /** Zones cardiaques (FC max, repos, seuil, planchers des zones) et VO2 max Garmin : elles remplissent le profil. */
    public function syncProfile(GarminAccount $account): void
    {
        $zones = collect($this->client->get($account, '/biometric-service/heartRateZones/v2'));
        $running = $zones->firstWhere('sport', 'RUNNING') ?? $zones->firstWhere('sport', 'DEFAULT') ?? $zones->first();

        $vo2 = collect($this->client->get($account, '/metrics-service/metrics/maxmet/daily/'.today()->subDays(90)->toDateString().'/'.today()->toDateString()))
            ->map(fn (array $day) => $day['generic'] ?? null)->filter(fn ($generic) => isset($generic['vo2MaxPreciseValue']))
            ->sortBy('calendarDate')->last();

        $fields = array_filter([
            'max_hr' => $running['maxHeartRateUsed'] ?? null,
            'resting_hr' => $running['restingHeartRateUsed'] ?? null,
            'threshold_hr' => $running['lactateThresholdHeartRateUsed'] ?? null,
            'hr_zone_floors' => isset($running['zone1Floor']) ? array_map(fn (int $zone) => (int) $running['zone'.$zone.'Floor'], range(1, 5)) : null,
            'vo2max' => $vo2['vo2MaxPreciseValue'] ?? null,
            'vo2max_on' => $vo2['calendarDate'] ?? null,
        ], fn ($value) => $value !== null);

        if ($fields) {
            $profile = Profile::ownedBy($account->user)->first() ?? (new Profile)->user()->associate($account->user);
            $profile->fill($fields)->save();
        }
    }

    /** Récupération quotidienne d'une période : FC de repos, Body Battery, stress, sommeil, HRV. */
    public function syncWellness(GarminAccount $account, Carbon $from, Carbon $to): void
    {
        for ($start = $from->copy(); $start->lte($to); $start->addDays(28)) {
            $end = $start->copy()->addDays(27)->min($to);
            [$first, $last] = [$start->toDateString(), $end->toDateString()];
            $days = [];

            foreach ($this->client->get($account, '/usersummary-service/v2/dailysummaries', ['startDate' => $first, 'endDate' => $last])['data'] ?? [] as $day) {
                $days[$day['calendarDate']] = [
                    'resting_hr' => $day['heartRate']['restingValue'] ?? null,
                    'body_battery_min' => $day['bodyBattery']['minValue'] ?? null,
                    'body_battery_max' => $day['bodyBattery']['maxValue'] ?? null,
                    'body_battery_charged' => $day['bodyBattery']['chargedValue'] ?? null,
                    'body_battery_drained' => $day['bodyBattery']['drainedValue'] ?? null,
                    'stress_avg' => isset($day['stress']['avgLevel']) && $day['stress']['avgLevel'] >= 0 ? $day['stress']['avgLevel'] : null,
                ];
            }
            foreach ($this->client->get($account, '/sleep-service/sleep/dailySleepSummariesByDate', ['startDate' => $first, 'endDate' => $last]) as $night) {
                if (! empty($night['calendarDate']) && ! empty($night['sleepTimeSeconds'])) {
                    $days[$night['calendarDate']] = ($days[$night['calendarDate']] ?? []) + [
                        'sleep_seconds' => $night['sleepTimeSeconds'],
                        'deep_sleep_seconds' => $night['deepSleepSeconds'] ?? null,
                        'sleep_score' => $night['sleepScores']['overall']['value'] ?? null,
                    ];
                }
            }
            foreach ($this->client->get($account, '/hrv-service/hrv/daily/'.$first.'/'.$last)['hrvSummaries'] ?? [] as $hrv) {
                $days[$hrv['calendarDate']] = ($days[$hrv['calendarDate']] ?? []) + [
                    'hrv_last_night' => $hrv['lastNightAvg'] ?? null,
                    'hrv_weekly_avg' => $hrv['weeklyAvg'] ?? null,
                    'hrv_status' => isset($hrv['status']) && $hrv['status'] !== 'NONE' ? $hrv['status'] : null,
                ];
            }

            foreach ($days as $date => $values) {
                $values = array_filter($values, fn ($value) => $value !== null);
                if (! $values) {
                    continue;
                }
                $wellness = DailyWellness::ownedBy($account->user)->whereDate('day', $date)->first()
                    ?? (new DailyWellness(['day' => $date]))->user()->associate($account->user);
                $wellness->fill($values)->save();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    public static function isRun(array $activity): bool
    {
        return str_contains((string) ($activity['activityType']['typeKey'] ?? ''), 'run');
    }

    /**
     * Courbes seconde par seconde, au format des flux de l'analyse (time, heartrate, velocity_smooth, distance…).
     *
     * @return array<string, list<float|int|bool|null>>
     */
    private function streams(GarminAccount $account, string $id): array
    {
        $details = $this->client->get($account, '/activity-service/activity/'.$id.'/details', ['maxChartSize' => self::MAX_CHART_SIZE]);
        $index = collect($details['metricDescriptors'] ?? [])->mapWithKeys(fn (array $descriptor) => [$descriptor['key'] => $descriptor['metricsIndex']]);
        $rows = collect($details['activityDetailMetrics'] ?? [])->pluck('metrics');
        if ($rows->isEmpty() || ! $index->has('sumElapsedDuration')) {
            return [];
        }

        $column = fn (string $key) => $index->has($key) ? $rows->map(fn (array $row) => $row[$index[$key]] ?? null)->all() : null;
        $speed = $column('directSpeed') ?? [];

        return array_filter([
            'time' => array_map(fn ($seconds) => (int) round((float) $seconds), $column('sumElapsedDuration')),
            'distance' => $column('sumDistance'),
            'heartrate' => $column('directHeartRate'),
            'velocity_smooth' => $speed ?: null,
            'cadence' => $column('directRunCadence'),
            'altitude' => $column('directElevation'),
            'moving' => $speed ? array_map(fn ($value) => $value !== null && $value > 0.5, $speed) : null,
            'latitude' => $column('directLatitude'),
            'longitude' => $column('directLongitude'),
        ], fn ($values) => $values !== null);
    }

    /**
     * Tours de 1 km calculés à partir des courbes (dernier tour partiel gardé s'il fait au moins 100 m).
     *
     * @param  array<string, list<float|int|bool|null>>  $streams
     * @return list<array{distance_m: int, moving_s: int, avg_speed: float, avg_hr: ?int, max_hr: ?int}>
     */
    private function kilometreLaps(array $streams): array
    {
        $time = $streams['time'] ?? [];
        $distance = $streams['distance'] ?? [];
        if (count($time) < 2 || count($distance) !== count($time)) {
            return [];
        }

        $laps = [];
        $lap = ['start' => 0.0, 'moving' => 0, 'hr' => 0.0, 'hr_time' => 0, 'max_hr' => null];
        for ($i = 1; $i < count($time); $i++) {
            $dt = $time[$i] - $time[$i - 1];
            if ($dt <= 0 || $distance[$i] === null) {
                continue;
            }
            if (($streams['moving'][$i] ?? true) && $dt <= RunningRecords::PAUSE_SECONDS) {
                $lap['moving'] += $dt;
            }
            if (! empty($streams['heartrate'][$i])) {
                $lap['hr'] += $streams['heartrate'][$i] * $dt;
                $lap['hr_time'] += $dt;
                $lap['max_hr'] = max($lap['max_hr'] ?? 0, (int) $streams['heartrate'][$i]);
            }
            if ($distance[$i] - $lap['start'] >= 1000) {
                $laps[] = $this->closeLap($lap, $distance[$i] - $lap['start']);
                $lap = ['start' => (float) $distance[$i], 'moving' => 0, 'hr' => 0.0, 'hr_time' => 0, 'max_hr' => null];
            }
        }
        $rest = (float) end($distance) - $lap['start'];
        if ($rest >= 100 && $lap['moving'] > 0) {
            $laps[] = $this->closeLap($lap, $rest);
        }

        return $laps;
    }

    /**
     * @param  array{start: float, moving: int, hr: float, hr_time: int, max_hr: ?int}  $lap
     * @return array{distance_m: int, moving_s: int, avg_speed: float, avg_hr: ?int, max_hr: ?int}
     */
    private function closeLap(array $lap, float $meters): array
    {
        return [
            'distance_m' => (int) round($meters),
            'moving_s' => $lap['moving'],
            'avg_speed' => $lap['moving'] > 0 ? round($meters / $lap['moving'], 3) : 0.0,
            'avg_hr' => $lap['hr_time'] > 0 ? (int) round($lap['hr'] / $lap['hr_time']) : null,
            'max_hr' => $lap['max_hr'],
        ];
    }

    /**
     * Tracé GPS simplifié (au plus 600 points).
     *
     * @param  array<string, list<float|int|bool|null>>  $streams
     * @return list<array{0: float, 1: float}>
     */
    private function polyline(array $streams): array
    {
        $points = [];
        foreach ($streams['latitude'] ?? [] as $i => $latitude) {
            $longitude = $streams['longitude'][$i] ?? null;
            if ($latitude !== null && $longitude !== null && ($latitude != 0 || $longitude != 0)) {
                $points[] = [round((float) $latitude, 5), round((float) $longitude, 5)];
            }
        }
        if (count($points) <= self::POLYLINE_POINTS) {
            return $points;
        }

        $step = count($points) / self::POLYLINE_POINTS;
        $simplified = [];
        for ($i = 0; $i < self::POLYLINE_POINTS; $i++) {
            $simplified[] = $points[(int) floor($i * $step)];
        }
        $simplified[] = end($points);

        return $simplified;
    }

    /**
     * Dynamique de course et effet d'entraînement.
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function dynamics(array $summary): array
    {
        $zones = array_map(fn (int $zone) => isset($summary['hrTimeInZone_'.$zone]) ? (int) round($summary['hrTimeInZone_'.$zone]) : null, range(1, 5));

        return array_filter([
            'power_avg' => isset($summary['avgPower']) ? (int) round($summary['avgPower']) : null,
            'stride_length_cm' => isset($summary['avgStrideLength']) ? round($summary['avgStrideLength'], 1) : null,
            'vertical_oscillation_cm' => isset($summary['avgVerticalOscillation']) ? round($summary['avgVerticalOscillation'], 1) : null,
            'ground_contact_ms' => isset($summary['avgGroundContactTime']) ? (int) round($summary['avgGroundContactTime']) : null,
            'vertical_ratio' => isset($summary['avgVerticalRatio']) ? round($summary['avgVerticalRatio'], 1) : null,
            'aerobic_effect' => isset($summary['aerobicTrainingEffect']) ? round($summary['aerobicTrainingEffect'], 1) : null,
            'anaerobic_effect' => isset($summary['anaerobicTrainingEffect']) ? round($summary['anaerobicTrainingEffect'], 1) : null,
            'effect_label' => $summary['trainingEffectLabel'] ?? null,
            'garmin_zone_seconds' => array_filter($zones, fn ($value) => $value !== null) ? $zones : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function saveBestEfforts(RunningActivity $activity, array $summary, Carbon $startedAt): void
    {
        BestEffort::where('activity_id', $activity->id)->delete();

        // Avec les courbes : meilleurs temps calculés sur toutes les distances (1 km, 5 km, 10 km, semi, marathon).
        if (RunningRecords::bestTimes($activity->streams ?? [])) {
            RunningRecords::saveBestTimes($activity);

            return;
        }

        // Sans courbes : les meilleurs temps fournis par Garmin.
        $averagePace = $activity->distance_m > 0 ? $activity->moving_seconds / ($activity->distance_m / 1000) : null;

        foreach (self::BEST_EFFORTS as $field => [$name, $meters]) {
            // Un meilleur temps plus lent que l'allure moyenne de la course est incohérent (valeur Garmin erronée) : écarté.
            $plausible = $averagePace && ! empty($summary[$field]) && $averagePace * 1.1 >= $summary[$field] / ($meters / 1000);
            if ($plausible) {
                (new BestEffort([
                    'activity_id' => $activity->id,
                    'name' => $name,
                    'distance_m' => $meters,
                    'elapsed_seconds' => (int) round($summary[$field]),
                    'achieved_on' => $startedAt->toDateString(),
                ]))->user()->associate($activity->user_id)->save();
            }
        }
    }
}
