<?php

namespace App\Modules\Coach\Services\GoogleHealth;

use App\Modules\Coach\Models\GoogleHealthAccount;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Services\CoachConversation;
use App\Modules\Coach\Services\ReferencePaces;
use App\Modules\Coach\Services\RunningRecords;
use App\Modules\Coach\Services\SessionMatcher;
use App\Modules\Coach\Services\Strava\ActivityAnalysis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Import des courses lues dans Google Health (séances de la montre arrivées par Health Connect, Samsung Health par exemple) :
 * résumé de la séance, tours au km s'ils existent, courbe de fréquence cardiaque, puis le même traitement que les autres
 * sources (séance prévue validée, analyse, records, débrief si la course est récente).
 */
class GoogleHealthImporter
{
    /** Pages de séances lues au plus (25 séances par page, imposé par Google). */
    private const MAX_EXERCISE_PAGES = 120;

    /** Pages de mesures cardiaques lues au plus par course (10 000 mesures par page). */
    private const MAX_HEART_RATE_PAGES = 3;

    public function __construct(
        private GoogleHealthClient $client,
        private ActivityAnalysis $analysis,
        private ReferencePaces $referencePaces,
        private CoachConversation $coach,
        private RunningRecords $records,
    ) {}

    /**
     * Séances de course d'une période (jours inclus, à l'heure locale de départ).
     *
     * @return Collection<int, array<string, mixed>> points de données « exercise » de Google Health
     *
     * @throws GoogleHealthUnavailable
     */
    public function runsBetween(GoogleHealthAccount $account, Carbon $from, Carbon $to): Collection
    {
        $filter = 'exercise.interval.civil_start_time >= "'.$from->toDateString().'" AND exercise.interval.civil_start_time < "'.$to->copy()->addDay()->toDateString().'"';
        $runs = collect();
        $pageToken = null;

        for ($page = 0; $page < self::MAX_EXERCISE_PAGES; $page++) {
            $response = $this->client->get($account, '/users/me/dataTypes/exercise/dataPoints', array_filter([
                'pageSize' => 25, 'filter' => $filter, 'pageToken' => $pageToken,
            ]));
            $runs = $runs->concat(array_filter($response['dataPoints'] ?? [], fn (array $point) => self::isRun($point)));
            $pageToken = $response['nextPageToken'] ?? null;
            if (! $pageToken) {
                break;
            }
        }

        return $runs->values();
    }

    /**
     * Importe (ou met à jour) une course à partir de son point de données Google Health.
     *
     * @param  array<string, mixed>  $point  point de données « exercise »
     *
     * @throws GoogleHealthUnavailable limite de requêtes ou autorisation retirée
     */
    public function import(GoogleHealthAccount $account, array $point, bool $notify = true): ?RunningActivity
    {
        if (! self::isRun($point)) {
            return null;
        }

        $user = $account->user;
        $exercise = $point['exercise'];
        $metrics = $exercise['metricsSummary'] ?? [];
        $id = self::externalId($point);
        $startedAt = self::localTime($exercise['interval']['startTime'], $exercise['interval']['startUtcOffset'] ?? null);

        // Course reçue ou complétée par Dada Sync (lue directement dans Samsung Health, plus riche) : on n'y touche plus,
        // et on ne la duplique pas.
        $fromDevice = RunningActivity::ownedBy($user)->whereNotNull('device_synced_at')
            ->whereBetween('started_at', [$startedAt->copy()->subMinutes(2), $startedAt->copy()->addMinutes(2)])->first();
        if ($fromDevice) {
            return $fromDevice;
        }
        $endedAt = self::localTime($exercise['interval']['endTime'], $exercise['interval']['endUtcOffset'] ?? null);
        $movingSeconds = max(60, (int) round(self::seconds($exercise['activeDuration'] ?? null) ?? $startedAt->diffInSeconds($endedAt)));
        $streams = $this->heartRateStream($account, $exercise['interval']['startTime'], $exercise['interval']['endTime']);
        $existing = RunningActivity::ownedBy($user)->where('source', 'google_health')->where('external_id', $id)->first();

        return DB::transaction(function () use ($user, $point, $exercise, $metrics, $id, $startedAt, $endedAt, $movingSeconds, $streams, $existing, $notify) {
            [$session, $suggestedId] = SessionMatcher::forImport($user, $startedAt, $existing, $notify);

            $heartRates = array_filter($streams['heartrate'] ?? []);
            $steps = isset($metrics['steps']) ? (int) $metrics['steps'] : null;

            $activity = $existing ?? (new RunningActivity(['source' => 'google_health', 'external_id' => $id]))->user()->associate($user);
            $activity->fill([
                'session_id' => $session?->id,
                'suggested_session_id' => $suggestedId,
                'name' => mb_substr((string) ($exercise['displayName'] ?? ''), 0, 150) ?: null,
                'sport_type' => $exercise['exerciseType'] ?? null,
                'started_at' => $startedAt,
                'distance_m' => (int) round(($metrics['distanceMillimeters'] ?? 0) / 1000),
                'moving_seconds' => $movingSeconds,
                'elapsed_seconds' => (int) $startedAt->diffInSeconds($endedAt),
                'avg_hr' => isset($metrics['averageHeartRateBeatsPerMinute']) ? (int) $metrics['averageHeartRateBeatsPerMinute'] : null,
                'max_hr' => $heartRates ? (int) max($heartRates) : null,
                'avg_cadence' => $steps ? (int) round($steps / ($movingSeconds / 60)) : null,
                'elevation_gain_m' => isset($metrics['elevationGainMillimeters']) ? (int) round($metrics['elevationGainMillimeters'] / 1000) : null,
                'laps' => $this->laps($exercise['splits'] ?? []) ?: null,
                'streams' => $streams ?: null,
                'raw_summary' => $point,
                'notes' => $activity->notes ?? (filled($exercise['notes'] ?? null) ? mb_substr($exercise['notes'], 0, 2000) : null),
            ]);
            $activity->analysis = $this->analysis->analyzeActivity($activity, $session, $this->referencePaces->zonesFor($user));
            $activity->save();

            $session?->update(['status' => 'done']);
            RunningRecords::saveBestTimes($activity);

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

    /**
     * @param  array<string, mixed>  $point
     */
    public static function isRun(array $point): bool
    {
        return in_array($point['exercise']['exerciseType'] ?? null, config('coach.google_health.run_types'), true);
    }

    /**
     * Identifiant de la séance : dernier segment de son nom (« users/…/dataTypes/exercise/dataPoints/{id} »).
     *
     * @param  array<string, mixed>  $point
     */
    public static function externalId(array $point): string
    {
        return mb_substr((string) str((string) $point['name'])->afterLast('/'), 0, 100);
    }

    /**
     * Courbe de fréquence cardiaque pendant la course, au format des flux de l'analyse (time en secondes depuis le départ,
     * heartrate). Vide sans l'autorisation de lire la FC ou si Google n'en a pas.
     *
     * @return array{time?: list<int>, heartrate?: list<int>}
     *
     * @throws GoogleHealthUnavailable limite de requêtes ou autorisation retirée
     */
    private function heartRateStream(GoogleHealthAccount $account, string $startTime, string $endTime): array
    {
        if (! $account->canReadHeartRate()) {
            return [];
        }

        $start = Carbon::parse($startTime)->utc()->startOfSecond();
        $end = Carbon::parse($endTime)->utc();
        $filter = 'heart_rate.sample_time.physical_time >= "'.$start->format('Y-m-d\TH:i:s\Z').'" AND heart_rate.sample_time.physical_time < "'.$end->copy()->addSecond()->format('Y-m-d\TH:i:s\Z').'"';
        $samples = [];
        $pageToken = null;

        try {
            for ($page = 0; $page < self::MAX_HEART_RATE_PAGES; $page++) {
                $response = $this->client->get($account, '/users/me/dataTypes/heart-rate/dataPoints', array_filter([
                    'pageSize' => 10000, 'filter' => $filter, 'pageToken' => $pageToken,
                ]));
                foreach ($response['dataPoints'] ?? [] as $sample) {
                    $time = $sample['heartRate']['sampleTime']['physicalTime'] ?? null;
                    $bpm = (int) ($sample['heartRate']['beatsPerMinute'] ?? 0);
                    if ($time && $bpm > 0) {
                        $samples[(int) $start->diffInSeconds(Carbon::parse($time)->utc(), false)] = $bpm;
                    }
                }
                $pageToken = $response['nextPageToken'] ?? null;
                if (! $pageToken) {
                    break;
                }
            }
        } catch (GoogleHealthUnavailable $e) {
            if ($e->rateLimited || $e->reconnect) {
                throw $e;
            }
            // La FC est un complément : la course est importée sans sa courbe.
            Log::info('Google Health : fréquence cardiaque non lue', ['account' => $account->id, 'error' => $e->getMessage()]);

            return [];
        }

        ksort($samples);
        $samples = array_filter($samples, fn (int $bpm, int $second) => $second >= 0, ARRAY_FILTER_USE_BOTH);
        if (count($samples) < 2) {
            return [];
        }

        return ['time' => array_keys($samples), 'heartrate' => array_values($samples)];
    }

    /**
     * Tours (au km par défaut chez Google) au format de l'application.
     *
     * @param  list<array<string, mixed>>  $splits
     * @return list<array{distance_m: int, moving_s: int, avg_speed: float, avg_hr: ?int, max_hr: null}>
     */
    private function laps(array $splits): array
    {
        return collect($splits)
            ->map(function (array $split) {
                $meters = (int) round(($split['metricsSummary']['distanceMillimeters'] ?? 0) / 1000);
                $seconds = (int) round(self::seconds($split['activeDuration'] ?? null) ?? 0);

                return [
                    'distance_m' => $meters,
                    'moving_s' => $seconds,
                    'avg_speed' => $seconds > 0 ? round($meters / $seconds, 3) : 0.0,
                    'avg_hr' => isset($split['metricsSummary']['averageHeartRateBeatsPerMinute']) ? (int) $split['metricsSummary']['averageHeartRateBeatsPerMinute'] : null,
                    'max_hr' => null,
                ];
            })
            ->filter(fn (array $lap) => $lap['distance_m'] > 0 && $lap['moving_s'] > 0)
            ->values()
            ->all();
    }

    /** Heure locale de l'athlète : instant UTC décalé de son fuseau au moment de la séance (« 7200s »). */
    private static function localTime(string $utcTime, ?string $utcOffset): Carbon
    {
        $local = Carbon::parse($utcTime)->utc()->addSeconds((int) round(self::seconds($utcOffset) ?? 0));

        return Carbon::parse($local->format('Y-m-d H:i:s'));
    }

    /** Durée Google (« 1985.290s ») en secondes. */
    private static function seconds(?string $duration): ?float
    {
        return $duration !== null && preg_match('/^-?\d+(\.\d+)?s$/', $duration) ? (float) rtrim($duration, 's') : null;
    }
}
