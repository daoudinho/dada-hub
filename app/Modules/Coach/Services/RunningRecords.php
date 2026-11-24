<?php

namespace App\Modules\Coach\Services;

use App\Models\User;
use App\Modules\Coach\Models\BestEffort;
use App\Modules\Coach\Models\RunningActivity;
use Illuminate\Support\Carbon;

/**
 * Records de course (« exploits ») : meilleurs temps sur une distance et vitesse de pointe, calculés à partir
 * des courbes seconde par seconde, quelle que soit la source ; plus longue course.
 */
class RunningRecords
{
    /** Distances suivies (nom → mètres). Les noms reprennent ceux de Strava pour rester compatibles. */
    public const DISTANCES = ['1K' => 1000, '5K' => 5000, '10K' => 10000, 'Half-Marathon' => 21097.5, 'Marathon' => 42195];

    /** Libellés des exploits, dans l'ordre d'affichage. */
    public const LABELS = [
        'longest' => 'Plus longue course',
        'best_week' => 'Semaine la plus prolifique',
        '1K' => 'Meilleur kilomètre',
        '5K' => 'Meilleur 5 kilomètres',
        '10K' => 'Meilleur 10 kilomètres',
        'Half-Marathon' => 'Meilleur semi-marathon',
        'Marathon' => 'Meilleur marathon',
        'top_speed' => 'Vitesse de pointe',
    ];

    /** Durée minimale (secondes) sur laquelle la vitesse de pointe est mesurée, pour écarter les pics GPS. */
    private const TOP_SPEED_WINDOW = 10;

    /** Vitesse (m/s) au-delà de laquelle un déplacement entre deux mesures est un saut GPS (~30 km/h). */
    private const MAX_PLAUSIBLE_SPEED = 8.3;

    /** Écart entre deux mesures (secondes) au-delà duquel la montre était en pause (records, analyse et tours). */
    public const PAUSE_SECONDS = 30;

    /**
     * Meilleur temps (secondes) sur chaque distance couverte par la course : le segment le plus rapide.
     *
     * @param  array<string, list<float|int|bool|null>>  $streams  flux time et distance
     * @return array<string, int>
     */
    public static function bestTimes(array $streams): array
    {
        [$time, $distance] = self::series($streams);
        if (! $time) {
            return [];
        }

        $best = [];
        $count = count($time);
        foreach (self::DISTANCES as $name => $meters) {
            if (end($distance) - $distance[0] < $meters) {
                continue;
            }
            $start = 0;
            $fastest = null;
            for ($end = 1; $end < $count; $end++) {
                // Le début avance tant que le segment reste au moins aussi long que la distance visée.
                while ($start + 1 < $end && $meters <= $distance[$end] - $distance[$start + 1]) {
                    $start++;
                }
                if ($meters <= $distance[$end] - $distance[$start]) {
                    $fastest = min($fastest ?? PHP_INT_MAX, $time[$end] - $time[$start]);
                }
            }
            if ($fastest) {
                $best[$name] = (int) $fastest;
            }
        }

        return $best;
    }

    /**
     * Vitesse de pointe (m/s) : la meilleure vitesse moyenne sur au moins 10 secondes.
     *
     * @param  array<string, list<float|int|bool|null>>  $streams
     */
    public static function topSpeed(array $streams): ?float
    {
        [$time, $distance] = self::series($streams);
        $start = 0;
        $top = null;
        for ($end = 1; $end < count($time); $end++) {
            while ($start + 1 < $end && $time[$end] - $time[$start + 1] >= self::TOP_SPEED_WINDOW) {
                $start++;
            }
            $seconds = $time[$end] - $time[$start];
            if ($seconds >= self::TOP_SPEED_WINDOW && $seconds <= self::TOP_SPEED_WINDOW * 3) {
                $top = max($top ?? 0, ($distance[$end] - $distance[$start]) / $seconds);
            }
        }

        return $top ? round($top, 2) : null;
    }

    /** Remplace les meilleurs temps calculés d'une course (ceux fournis par Strava sont gardés tels quels). */
    public static function saveBestTimes(RunningActivity $activity): void
    {
        $times = self::bestTimes($activity->streams ?? []);
        if (! $times) {
            return;
        }

        BestEffort::where('activity_id', $activity->id)->whereIn('name', array_keys(self::DISTANCES))->delete();
        foreach ($times as $name => $seconds) {
            (new BestEffort([
                'activity_id' => $activity->id,
                'name' => $name,
                'distance_m' => (int) round(self::DISTANCES[$name]),
                'elapsed_seconds' => $seconds,
                'achieved_on' => $activity->started_at->toDateString(),
            ]))->user()->associate($activity->user_id)->save();
        }
    }

    /**
     * Exploits existants de l'athlète, dans l'ordre d'affichage (un exploit absent n'apparaît pas).
     *
     * @return list<array{key: string, label: string, value: string, detail: string, activity: ?RunningActivity, week: ?array{start: Carbon, end: Carbon, km: float, sessions: int}}>
     */
    public function exploits(User $user): array
    {
        return collect($this->holders($user))
            ->map(fn (array $record, string $key) => ['key' => $key, 'label' => self::LABELS[$key], 'value' => $record['value'], 'detail' => $record['detail'],
                'activity' => $record['activity'], 'week' => $record['week'] ?? null])
            ->values()
            ->all();
    }

    /**
     * Compare une nouvelle course aux records : ceux qu'elle bat sont notés sur la course (avec l'ancienne valeur).
     * Une première performance (sans record précédent) n'est pas un « nouveau record ».
     *
     * @return list<array{key: string, label: string, value: string, previous: string}>
     */
    public function markNewRecords(RunningActivity $activity): array
    {
        $user = $activity->user;
        $now = $this->holders($user);
        $before = $this->holders($user, exceptActivityId: $activity->id);

        $records = collect($now)
            ->filter(fn (array $record, string $key) => isset($before[$key]) && ($key === 'best_week'
                // La semaine de la course devient la plus prolifique, au-delà de l'ancien record.
                ? $activity->started_at->between($record['week']['start'], $record['week']['end']) && $record['week']['km'] > $before[$key]['week']['km']
                : $record['activity']?->id === $activity->id))
            ->map(fn (array $record, string $key) => ['key' => $key, 'label' => self::LABELS[$key], 'value' => $record['value'], 'previous' => $before[$key]['value']])
            ->values()
            ->all();

        $activity->forceFill(['new_records' => $records ?: null])->save();

        return $records;
    }

    /**
     * Détenteur de chaque record, dans l'ordre d'affichage (records absents omis), éventuellement sans une course.
     *
     * @return array<string, array{value: string, detail: string, activity: ?RunningActivity, week?: array{start: Carbon, end: Carbon, km: float, sessions: int}}>
     */
    private function holders(User $user, ?int $exceptActivityId = null): array
    {
        // Résumés seulement : les courbes de toutes les courses n'ont rien à faire ici.
        $runs = RunningActivity::ownedBy($user)->summary()->when($exceptActivityId, fn ($query) => $query->whereKeyNot($exceptActivityId));
        $records = [];

        $longest = (clone $runs)->orderByDesc('distance_m')->first();
        if ($longest && $longest->distance_m > 0) {
            $records['longest'] = ['value' => number_format($longest->distanceKm(), 2, ',', ' ').' km', 'detail' => 'en '.Duration::format($longest->moving_seconds), 'activity' => $longest];
        }

        // Semaine (lundi → dimanche) au plus grand kilométrage.
        $week = (clone $runs)->get(['started_at', 'distance_m'])
            ->groupBy(fn (RunningActivity $run) => $run->started_at->copy()->startOfWeek()->toDateString())
            ->map(fn ($group, string $monday) => ['start' => Carbon::parse($monday), 'end' => Carbon::parse($monday)->endOfWeek(),
                'km' => round($group->sum('distance_m') / 1000, 1), 'sessions' => $group->count()])
            ->sortByDesc('km')
            ->first();
        if ($week && $week['km'] > 0) {
            $records['best_week'] = ['value' => number_format($week['km'], 1, ',', ' ').' km', 'detail' => $week['sessions'].' séance'.($week['sessions'] > 1 ? 's' : ''),
                'activity' => null, 'week' => $week];
        }

        $efforts = BestEffort::ownedBy($user)->with(['activity' => fn ($query) => $query->summary()])->whereIn('name', array_keys(self::DISTANCES))
            ->when($exceptActivityId, fn ($query) => $query->where('activity_id', '!=', $exceptActivityId))
            ->get()
            ->filter(fn (BestEffort $effort) => $effort->activity !== null)
            ->groupBy('name')
            ->map(fn ($group) => $group->sortBy('elapsed_seconds')->first());
        foreach (array_keys(self::DISTANCES) as $name) {
            if ($effort = $efforts->get($name)) {
                $records[$name] = ['value' => Duration::format($effort->elapsed_seconds), 'detail' => Duration::pace($effort->elapsed_seconds, $effort->distance_m) ?? '', 'activity' => $effort->activity];
            }
        }

        $fastest = (clone $runs)->whereNotNull('analysis')->get()
            ->filter(fn (RunningActivity $run) => isset($run->analysis['top_speed']))
            ->sortByDesc(fn (RunningActivity $run) => $run->analysis['top_speed'])
            ->first();
        if ($fastest) {
            $speed = $fastest->analysis['top_speed'];
            $records['top_speed'] = ['value' => number_format($speed * 3.6, 1, ',', '').' km/h', 'detail' => 'soit '.Duration::format((int) round(1000 / $speed)).' /km', 'activity' => $fastest];
        }

        return collect(self::LABELS)->keys()->filter(fn (string $key) => isset($records[$key]))->mapWithKeys(fn (string $key) => [$key => $records[$key]])->all();
    }

    /**
     * Temps en mouvement et distances cumulés, débarrassés des points vides et des pauses (pause automatique de la montre,
     * arrêt à un feu) : comme pour les tours, le chrono ne tourne que quand on court.
     *
     * @param  array<string, list<float|int|bool|null>>  $streams
     * @return array{0: list<int>, 1: list<float>}
     */
    private static function series(array $streams): array
    {
        $time = [];
        $distance = [];
        $previous = $clock = null;
        foreach ($streams['time'] ?? [] as $i => $seconds) {
            $meters = $streams['distance'][$i] ?? null;
            if ($seconds === null || $meters === null) {
                continue;
            }
            if (! $time) {
                [$time[], $distance[], $previous, $clock] = [0, 0.0, (float) $meters, (int) $seconds];

                continue;
            }
            $dt = (int) $seconds - $clock;
            $step = (float) $meters - $previous;
            $previous = (float) $meters;
            if ($dt <= 0) {
                continue;
            }
            $clock = (int) $seconds;
            if ($dt > self::PAUSE_SECONDS || ! ($streams['moving'][$i] ?? true)) {
                continue;
            }
            // Saut GPS (accroche des satellites, perte de signal) : ce déplacement impossible ne compte pas.
            if ($step < 0 || $step / $dt > self::MAX_PLAUSIBLE_SPEED) {
                $step = 0.0;
            }
            $time[] = end($time) + $dt;
            $distance[] = end($distance) + $step;
        }

        return [$time, $distance];
    }
}
