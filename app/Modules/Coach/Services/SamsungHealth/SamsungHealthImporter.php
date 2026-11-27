<?php

namespace App\Modules\Coach\Services\SamsungHealth;

use App\Models\User;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Services\CoachConversation;
use App\Modules\Coach\Services\ReferencePaces;
use App\Modules\Coach\Services\RouteStreams;
use App\Modules\Coach\Services\RunningRecords;
use App\Modules\Coach\Services\SessionMatcher;
use App\Modules\Coach\Services\Strava\ActivityAnalysis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Courses envoyées par l'app Dada Sync, lues dans Samsung Health sur le téléphone : résumé, tracé GPS et journal
 * (fréquence cardiaque, vitesse, cadence), transformés en courbes comme pour les autres sources.
 *
 * Une course déjà présente (importée par Google Health par exemple, même départ à 2 minutes près) est complétée
 * plutôt que dupliquée : ressenti, débrief et bilan restent ; chaque mesure absente est ajoutée, une courbe plus riche
 * remplace l'ancienne.
 *
 * Format d'une course : {id, type, title?, start, end (ISO 8601 avec décalage), duration_s?, distance_m?, mean_hr?, max_hr?,
 * mean_cadence?, elevation_gain_m?, route?: [[secondes depuis le départ, latitude, longitude, altitude?]…],
 * samples?: [[secondes depuis le départ, fc?, vitesse m/s?, cadence?]…]}
 */
class SamsungHealthImporter
{
    public const SOURCE = 'samsung_health';

    /** Types de séance Samsung Health importés comme courses. */
    public const RUN_TYPES = ['RUNNING', 'TRACK_RUNNING', 'TREADMILL'];

    /** Écart de départ (secondes) en dessous duquel deux courses sont la même. */
    private const SAME_RUN_SECONDS = 120;

    /** Écart médian maximal entre deux mesures (secondes) pour calculer la distance à partir de la vitesse. */
    private const MAX_SPEED_SAMPLING_SECONDS = 10;

    /** Écart maximal (secondes) entre un point du tracé et la mesure du journal qu'on lui associe. */
    private const SAMPLE_MATCH_SECONDS = 30;

    public function __construct(
        private ActivityAnalysis $analysis,
        private ReferencePaces $referencePaces,
        private CoachConversation $coach,
        private RunningRecords $records,
    ) {}

    /**
     * Importe une course (créée, complétée ou mise à jour).
     *
     * @param  array<string, mixed>  $run  course validée par l'API
     * @return array{activity: RunningActivity, status: 'created'|'merged'|'updated'}
     */
    public function import(User $user, array $run, bool $notify = true): array
    {
        $startedAt = self::localTime($run['start']);
        $endedAt = self::localTime($run['end']);
        $streams = $this->streams($run['route'] ?? [], $run['samples'] ?? []);
        $measures = $this->measures($run, $streams, $startedAt, $endedAt);

        $own = RunningActivity::ownedBy($user)->where('source', self::SOURCE)->where('external_id', (string) $run['id'])->first();
        $other = $own ? null : RunningActivity::ownedBy($user)
            ->whereBetween('started_at', [$startedAt->copy()->subSeconds(self::SAME_RUN_SECONDS), $startedAt->copy()->addSeconds(self::SAME_RUN_SECONDS)])
            ->get()
            ->sortBy(fn (RunningActivity $activity) => abs($activity->started_at->diffInSeconds($startedAt, false)))
            ->first();

        return DB::transaction(function () use ($user, $run, $startedAt, $measures, $own, $other, $notify) {
            if ($other) {
                $this->complete($other, $measures);
                $status = 'merged';
                $activity = $other;
                $session = $activity->session;
            } else {
                [$session, $suggestedId] = SessionMatcher::forImport($user, $startedAt, $own, $notify);
                $activity = $own ?? (new RunningActivity(['source' => self::SOURCE, 'external_id' => mb_substr((string) $run['id'], 0, 100)]))->user()->associate($user);
                $activity->fill($measures + [
                    'session_id' => $session?->id,
                    'suggested_session_id' => $suggestedId,
                    'name' => filled($run['title'] ?? null) ? mb_substr($run['title'], 0, 150) : null,
                ]);
                $status = $own ? 'updated' : 'created';
            }

            $activity->device_synced_at = now();
            $activity->analysis = $this->analysis->analyzeActivity($activity, $session, $this->referencePaces->zonesFor($user));
            $activity->save();

            $session?->update(['status' => 'done']);
            RunningRecords::saveBestTimes($activity);

            if ($status === 'created' && $notify) {
                $this->records->markNewRecords($activity);
                if ($startedAt->isAfter(now()->subHours((int) config('coach.strava.debrief_within_hours')))) {
                    $this->coach->startDebrief($user, $activity);
                }
            }

            return ['activity' => $activity, 'status' => $status];
        });
    }

    /**
     * Course déjà présente : chaque mesure absente est ajoutée, une courbe, un tracé ou des tours plus riches remplacent les anciens.
     *
     * @param  array<string, mixed>  $measures
     */
    private function complete(RunningActivity $activity, array $measures): void
    {
        foreach (['distance_m', 'moving_seconds', 'elapsed_seconds', 'avg_hr', 'max_hr', 'avg_cadence', 'elevation_gain_m', 'sport_type'] as $field) {
            if (blank($activity->{$field}) && filled($measures[$field] ?? null)) {
                $activity->{$field} = $measures[$field];
            }
        }

        $points = fn (?array $streams) => count($streams['time'] ?? []);
        $hasHeartRate = fn (?array $streams) => array_filter($streams['heartrate'] ?? []) !== [];
        $newStreams = $measures['streams'];
        if ($newStreams && ($points($newStreams) > $points($activity->streams) || ($hasHeartRate($newStreams) && ! $hasHeartRate($activity->streams)))) {
            $activity->streams = $newStreams;
        }
        if ($measures['polyline'] && count($measures['polyline']) > count($activity->polyline ?? [])) {
            $activity->polyline = $measures['polyline'];
        }
        if ($measures['laps'] && count($measures['laps']) > count($activity->laps ?? [])) {
            $activity->laps = $measures['laps'];
        }
    }

    /**
     * Colonnes de la course tirées de l'envoi et des courbes.
     *
     * @param  array<string, mixed>  $run
     * @param  array<string, list<float|int|bool|null>>  $streams
     * @return array<string, mixed>
     */
    private function measures(array $run, array $streams, Carbon $startedAt, Carbon $endedAt): array
    {
        $heartRates = array_values(array_filter($streams['heartrate'] ?? []));
        $distances = $streams['distance'] ?? [];
        $distance = isset($run['distance_m']) ? (int) round($run['distance_m']) : (int) round((float) ($distances ? end($distances) : 0));
        $elapsed = (int) $startedAt->diffInSeconds($endedAt);

        return [
            'sport_type' => $run['type'],
            'started_at' => $startedAt,
            'distance_m' => $distance,
            'moving_seconds' => max(60, (int) round($run['duration_s'] ?? $elapsed)),
            'elapsed_seconds' => $elapsed,
            'avg_hr' => isset($run['mean_hr']) ? (int) round($run['mean_hr']) : ($heartRates ? (int) round(array_sum($heartRates) / count($heartRates)) : null),
            'max_hr' => isset($run['max_hr']) ? (int) round($run['max_hr']) : ($heartRates ? (int) max($heartRates) : null),
            'avg_cadence' => isset($run['mean_cadence']) ? (int) round($run['mean_cadence']) : null,
            'elevation_gain_m' => isset($run['elevation_gain_m']) ? (int) round($run['elevation_gain_m']) : null,
            'laps' => RouteStreams::kilometreLaps($streams) ?: null,
            'streams' => collect($streams)->except(['latitude', 'longitude'])->all() ?: null,
            'polyline' => RouteStreams::polyline($streams) ?: null,
        ];
    }

    /**
     * Courbes au format de l'analyse. Avec un tracé : un point par position GPS (distance cumulée), complété par la mesure
     * du journal la plus proche. Sans tracé : un point par mesure du journal, la distance venant de la vitesse si les mesures
     * sont assez rapprochées (une mesure par minute ne suffit pas à des records justes).
     *
     * @param  array<int, mixed>  $route
     * @param  array<int, mixed>  $samples
     * @return array<string, list<float|int|bool|null>>
     */
    private function streams(array $route, array $samples): array
    {
        $route = self::clean($route, fn (array $point) => count($point) >= 3 && is_numeric($point[1]) && is_numeric($point[2])
            && abs((float) $point[1]) <= 90 && abs((float) $point[2]) <= 180 && ((float) $point[1] != 0 || (float) $point[2] != 0));
        $samples = self::clean($samples, fn (array $point) => count($point) >= 2);

        if (count($route) >= 2) {
            $time = $distance = $latitude = $longitude = $altitude = [];
            $total = 0.0;
            foreach ($route as $i => $point) {
                if ($i > 0) {
                    $total += self::meters($route[$i - 1][1], $route[$i - 1][2], $point[1], $point[2]);
                }
                $time[] = (int) $point[0];
                $distance[] = round($total, 1);
                $latitude[] = (float) $point[1];
                $longitude[] = (float) $point[2];
                $altitude[] = isset($point[3]) && is_numeric($point[3]) ? round((float) $point[3], 1) : null;
            }
            $matched = $this->match($time, $samples);
            $streams = ['time' => $time, 'distance' => $distance, 'latitude' => $latitude, 'longitude' => $longitude,
                'altitude' => array_filter($altitude, fn ($value) => $value !== null) ? $altitude : null] + $matched;
        } elseif (count($samples) >= 2) {
            $time = array_map(fn (array $sample) => (int) $sample[0], $samples);
            $streams = $this->match($time, $samples);
            $streams['time'] = $time;
            $speed = $streams['velocity_smooth'] ?? null;
            if ($speed && self::medianGap($time) <= self::MAX_SPEED_SAMPLING_SECONDS) {
                $total = 0.0;
                $streams['distance'] = array_map(function (int $i) use ($time, $speed, &$total) {
                    if ($i > 0 && $speed[$i] !== null) {
                        $total += $speed[$i] * ($time[$i] - $time[$i - 1]);
                    }

                    return round($total, 1);
                }, array_keys($time));
            }
        } else {
            return [];
        }

        if (! empty($streams['velocity_smooth'])) {
            $streams['moving'] = array_map(fn ($value) => $value === null || $value > 0.5, $streams['velocity_smooth']);
        }

        return array_filter($streams, fn ($values) => $values !== null);
    }

    /**
     * Fréquence cardiaque, vitesse et cadence du journal associées à chaque instant (mesure la plus proche, 30 s d'écart au plus).
     *
     * @param  list<int>  $time
     * @param  list<array<int, mixed>>  $samples
     * @return array<string, ?list<float|int|null>>
     */
    private function match(array $time, array $samples): array
    {
        $columns = ['heartrate' => 1, 'velocity_smooth' => 2, 'cadence' => 3];
        $values = array_fill_keys(array_keys($columns), []);
        $j = 0;
        foreach ($time as $second) {
            while ($j + 1 < count($samples) && abs($samples[$j + 1][0] - $second) <= abs($samples[$j][0] - $second)) {
                $j++;
            }
            $sample = $samples !== [] && abs($samples[$j][0] - $second) <= self::SAMPLE_MATCH_SECONDS ? $samples[$j] : null;
            foreach ($columns as $name => $index) {
                $value = $sample[$index] ?? null;
                $values[$name][] = is_numeric($value) && $value > 0 ? ($name === 'velocity_smooth' ? round((float) $value, 3) : (int) round((float) $value)) : null;
            }
        }

        return array_map(fn (array $column) => array_filter($column, fn ($value) => $value !== null) ? $column : null, $values);
    }

    /**
     * Points valides, triés par temps, un seul par seconde.
     *
     * @param  array<int, mixed>  $points
     * @return list<array<int, mixed>>
     */
    private static function clean(array $points, callable $valid): array
    {
        $kept = [];
        foreach ($points as $point) {
            if (is_array($point) && isset($point[0]) && is_numeric($point[0]) && $point[0] >= 0 && $valid(array_values($point))) {
                $point = array_values($point);
                $point[0] = (int) round((float) $point[0]);
                $kept[$point[0]] ??= $point;
            }
        }
        ksort($kept);

        return array_values($kept);
    }

    /** Distance entre deux positions (mètres, formule de haversine). */
    private static function meters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $a = sin(($phi2 - $phi1) / 2) ** 2 + cos($phi1) * cos($phi2) * sin(deg2rad($lon2 - $lon1) / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * @param  list<int>  $time
     */
    private static function medianGap(array $time): int
    {
        $gaps = [];
        for ($i = 1; $i < count($time); $i++) {
            $gaps[] = $time[$i] - $time[$i - 1];
        }
        sort($gaps);

        return $gaps ? $gaps[intdiv(count($gaps), 2)] : PHP_INT_MAX;
    }

    /** Heure locale de l'athlète (« 2026-11-27T12:11:00+01:00 » → 12:11), comme pour les autres sources. */
    private static function localTime(string $time): Carbon
    {
        return Carbon::parse(Carbon::parse($time)->format('Y-m-d H:i:s'));
    }
}
