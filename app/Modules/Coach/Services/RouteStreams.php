<?php

namespace App\Modules\Coach\Services;

/**
 * Calculs communs aux courbes d'une course, quelle que soit la source (Garmin, Samsung Health) :
 * tours de 1 km et tracé GPS simplifié pour la carte.
 */
final class RouteStreams
{
    /** Points gardés au plus dans le tracé affiché. */
    public const POLYLINE_POINTS = 600;

    /**
     * Tours de 1 km calculés à partir des courbes (dernier tour partiel gardé s'il fait au moins 100 m).
     *
     * @param  array<string, list<float|int|bool|null>>  $streams
     * @return list<array{distance_m: int, moving_s: int, avg_speed: float, avg_hr: ?int, max_hr: ?int}>
     */
    public static function kilometreLaps(array $streams): array
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
                $laps[] = self::closeLap($lap, $distance[$i] - $lap['start']);
                $lap = ['start' => (float) $distance[$i], 'moving' => 0, 'hr' => 0.0, 'hr_time' => 0, 'max_hr' => null];
            }
        }
        $rest = (float) end($distance) - $lap['start'];
        if ($rest >= 100 && $lap['moving'] > 0) {
            $laps[] = self::closeLap($lap, $rest);
        }

        return $laps;
    }

    /**
     * Tracé GPS simplifié (au plus POLYLINE_POINTS points), à partir des courbes latitude et longitude.
     *
     * @param  array<string, list<float|int|bool|null>>  $streams
     * @return list<array{0: float, 1: float}>
     */
    public static function polyline(array $streams): array
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
     * @param  array{start: float, moving: int, hr: float, hr_time: int, max_hr: ?int}  $lap
     * @return array{distance_m: int, moving_s: int, avg_speed: float, avg_hr: ?int, max_hr: ?int}
     */
    private static function closeLap(array $lap, float $meters): array
    {
        return [
            'distance_m' => (int) round($meters),
            'moving_s' => $lap['moving'],
            'avg_speed' => $lap['moving'] > 0 ? round($meters / $lap['moving'], 3) : 0.0,
            'avg_hr' => $lap['hr_time'] > 0 ? (int) round($lap['hr'] / $lap['hr_time']) : null,
            'max_hr' => $lap['max_hr'],
        ];
    }
}
