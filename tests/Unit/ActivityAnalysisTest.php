<?php

namespace Tests\Unit;

use App\Modules\Coach\Models\RunningSession;
use App\Modules\Coach\Services\Strava\ActivityAnalysis;
use PHPUnit\Framework\TestCase;

class ActivityAnalysisTest extends TestCase
{
    private const ZONES = [
        'Z1 récupération' => ['from' => 120, 'to' => 134], 'Z2 endurance' => ['from' => 134, 'to' => 148],
        'Z3 tempo' => ['from' => 148, 'to' => 162], 'Z4 seuil' => ['from' => 162, 'to' => 176], 'Z5 VMA' => ['from' => 176, 'to' => 190],
    ];

    public function test_steady_heart_rate_at_steady_pace_shows_no_drift(): void
    {
        $analysis = (new ActivityAnalysis)->analyze($this->streams(3600, fn () => 140), [], 10000, 3600, null, self::ZONES);

        $this->assertSame(0.0, $analysis['drift_percent']);
        $this->assertSame(3600 - 1, $analysis['zone_seconds']['Z2 endurance']);
        $this->assertSame(360, $analysis['z2_pace']); // 2,78 m/s ≈ 6:00 /km
    }

    public function test_rising_heart_rate_at_steady_pace_is_reported_as_drift(): void
    {
        $analysis = (new ActivityAnalysis)->analyze($this->streams(3600, fn (int $t) => 140 + 20 * $t / 3600), [], 10000, 3600, null, self::ZONES);

        $this->assertGreaterThan(ActivityAnalysis::DRIFT_ALERT, $analysis['drift_percent']);
        $this->assertStringContainsString('au-delà de 5 %', implode(' ', ActivityAnalysis::describe($analysis)));
    }

    public function test_short_runs_get_no_drift(): void
    {
        $analysis = (new ActivityAnalysis)->analyze($this->streams(1200, fn () => 150), [], 4000, 1200, null, self::ZONES);

        $this->assertArrayNotHasKey('drift_percent', $analysis);
    }

    public function test_interval_laps_are_detected_and_their_regularity_measured(): void
    {
        $recovery = ['distance_m' => 200, 'moving_s' => 90, 'avg_speed' => 2.2, 'avg_hr' => 140];
        $laps = [
            ['distance_m' => 2000, 'moving_s' => 720, 'avg_speed' => 2.78, 'avg_hr' => 135],
            ['distance_m' => 400, 'moving_s' => 100, 'avg_speed' => 4.0, 'avg_hr' => 170], $recovery,
            ['distance_m' => 400, 'moving_s' => 100, 'avg_speed' => 4.0, 'avg_hr' => 172], $recovery,
            ['distance_m' => 400, 'moving_s' => 105, 'avg_speed' => 3.8, 'avg_hr' => 174], $recovery,
        ];

        $intervals = (new ActivityAnalysis)->analyze([], $laps, 4600, 1300, null, [])['intervals'];

        $this->assertSame([3, 250, 263], [$intervals['count'], $intervals['first_pace'], $intervals['last_pace']]);
        $this->assertEqualsWithDelta(2.4, $intervals['variation_percent'], 0.2);
    }

    public function test_reps_are_found_in_the_speed_stream_and_each_compared_to_the_target(): void
    {
        $speeds = array_fill(0, 600, 2.8);
        foreach ([4.1, 4.1, 4.1, 4.1, 4.1, 3.6] as $repSpeed) {
            $speeds = [...$speeds, ...array_fill(0, 90, $repSpeed), ...array_fill(0, 90, 2.2)];
        }
        $speeds = [...$speeds, ...array_fill(0, 300, 2.8)];
        $session = new RunningSession(['type' => 'intervals', 'pace_from' => 240, 'pace_to' => 250]);

        $intervals = (new ActivityAnalysis)->analyze($this->speedStreams($speeds), [], 6000, count($speeds), $session, [])['intervals'];

        $this->assertSame(6, $intervals['count']);
        $this->assertSame([5, 0, 1], [$intervals['in_target'], $intervals['too_fast'], $intervals['too_slow']]);
        $this->assertEqualsWithDelta(90, $intervals['reps'][0]['seconds'], 8);
        $this->assertStringContainsString('Fractions dans la cible : 5 sur 6 (1 trop lente)', implode(' ', ActivityAnalysis::describe(['intervals' => $intervals])));
    }

    public function test_a_steady_easy_run_has_no_reps(): void
    {
        $session = new RunningSession(['type' => 'easy']);

        $analysis = (new ActivityAnalysis)->analyze($this->speedStreams([...array_fill(0, 600, 2.2), ...array_fill(0, 600, 3.5)]), [], 3400, 1200, $session, []);

        $this->assertArrayNotHasKey('intervals', $analysis);
    }

    public function test_a_slow_flat_kilometre_is_a_weak_spot_and_slows_the_second_half(): void
    {
        $kilometres = array_fill(0, 10, [2.78, 0.0]);
        $kilometres[5] = [2.4, 0.0];

        $analysis = (new ActivityAnalysis)->analyze($this->trackStreams($kilometres), [], 10000, 3600, null, []);

        $this->assertSame(6, $analysis['weak_km']['km']);
        $this->assertEqualsWithDelta(417, $analysis['weak_km']['pace'], 3);
        $this->assertEqualsWithDelta(360, $analysis['weak_km']['usual_pace'], 2);
        $this->assertGreaterThan($analysis['split']['first_pace'], $analysis['split']['second_pace']);
        $this->assertArrayNotHasKey('grade_adjusted', $analysis);
    }

    public function test_a_climbing_kilometre_is_judged_on_its_grade_adjusted_pace(): void
    {
        $kilometres = array_fill(0, 10, [2.78, 0.0]);
        $kilometres[5] = [2.4, 50.0]; // 5 % de côte

        $analysis = (new ActivityAnalysis)->analyze($this->trackStreams($kilometres), [], 10000, 3600, null, []);

        $this->assertArrayNotHasKey('weak_km', $analysis);
        $this->assertSame(50, $analysis['grade_adjusted']['climb']);
        $this->assertLessThan($analysis['grade_adjusted']['pace'], $analysis['grade_adjusted']['gap_pace']);
    }

    /**
     * Flux seconde par seconde à partir d'une vitesse par seconde.
     *
     * @param  list<float>  $speeds
     * @return array<string, list<float|int|bool>>
     */
    private function speedStreams(array $speeds): array
    {
        return [
            'time' => range(0, count($speeds) - 1),
            'velocity_smooth' => $speeds,
            'heartrate' => array_fill(0, count($speeds), 150),
            'moving' => array_fill(0, count($speeds), true),
        ];
    }

    /**
     * Flux distance et altitude, kilomètre par kilomètre : [vitesse m/s, dénivelé positif du km].
     *
     * @param  list<array{0: float, 1: float}>  $kilometres
     * @return array<string, list<float|int|bool>>
     */
    private function trackStreams(array $kilometres): array
    {
        $streams = ['time' => [0], 'distance' => [0.0], 'altitude' => [0.0], 'velocity_smooth' => [0.0], 'moving' => [true]];
        $distance = $altitude = 0.0;
        foreach ($kilometres as $index => [$speed, $climb]) {
            while ($distance < ($index + 1) * 1000) {
                $distance = min(($index + 1) * 1000, $distance + $speed);
                $altitude += $climb * $speed / 1000;
                $streams['time'][] = count($streams['time']);
                $streams['distance'][] = $distance;
                $streams['altitude'][] = $altitude;
                $streams['velocity_smooth'][] = $speed;
                $streams['moving'][] = true;
            }
        }

        return $streams;
    }

    /**
     * @param  callable(int): float  $heartRate
     * @return array<string, list<float|int|bool>>
     */
    private function streams(int $seconds, callable $heartRate): array
    {
        $range = range(0, $seconds - 1);

        return [
            'time' => $range,
            'heartrate' => array_map($heartRate, $range),
            'velocity_smooth' => array_fill(0, $seconds, 2.78),
            'moving' => array_fill(0, $seconds, true),
        ];
    }

    public function test_glossary_explains_only_the_metrics_of_the_run_with_the_athletes_zones(): void
    {
        $glossary = ActivityAnalysis::glossary(['drift_percent' => 3.2, 'zone_seconds' => ['Z2 endurance' => 600]], ['ground_contact_ms' => 250], self::ZONES);

        $this->assertArrayHasKey('Dérive cardiaque', $glossary);
        $this->assertArrayHasKey('Contact au sol', $glossary);
        $this->assertStringContainsString('Pour toi : 134 à 148 bpm', $glossary['Z2 endurance']);
        $this->assertArrayNotHasKey('Fractions', $glossary);
        $this->assertArrayNotHasKey('Puissance', $glossary);
        $this->assertSame([], ActivityAnalysis::glossary([], null));
    }
}
