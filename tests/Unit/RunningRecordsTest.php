<?php

namespace Tests\Unit;

use App\Modules\Coach\Services\RouteSvg;
use App\Modules\Coach\Services\RunningRecords;
use PHPUnit\Framework\TestCase;

class RunningRecordsTest extends TestCase
{
    public function test_best_times_find_the_fastest_segment_of_each_covered_distance(): void
    {
        // 6 km : 3 km à 6:00 /km, puis 3 km à 4:00 /km (une mesure par seconde).
        $time = [];
        $distance = [];
        $meters = 0.0;
        for ($t = 0; $t <= 1080 + 720; $t++) {
            $time[] = $t;
            $distance[] = $meters;
            $meters += $t < 1080 ? 1000 / 360 : 1000 / 240;
        }

        $best = RunningRecords::bestTimes(['time' => $time, 'distance' => $distance]);

        $this->assertEqualsWithDelta(240, $best['1K'], 1);
        $this->assertEqualsWithDelta(3 * 240 + 2 * 360, $best['5K'], 2); // les 3 km rapides + 2 km avant
        $this->assertArrayNotHasKey('10K', $best);
    }

    public function test_best_times_leave_out_pauses_like_the_laps(): void
    {
        // 2 km à 5:00 /km, avec une pause automatique de 2 min et un arrêt de 30 s au feu (montre qui tourne, immobile) au milieu du 1er km.
        $time = $distance = $moving = [];
        $clock = 0;
        $meters = 0.0;
        for ($run = 0; $run <= 600; $run++) {
            if ($run === 150) {
                $clock += 120; // pause automatique : la montre n'enregistre rien
            }
            $stopped = $run > 300 && $run <= 330;
            $time[] = $clock++;
            $distance[] = $meters;
            $moving[] = ! $stopped;
            $meters += $stopped ? 0 : 1000 / 300;
        }

        $best = RunningRecords::bestTimes(['time' => $time, 'distance' => $distance, 'moving' => $moving]);

        $this->assertEqualsWithDelta(300, $best['1K'], 1);
    }

    public function test_top_speed_ignores_a_single_gps_spike(): void
    {
        $time = range(0, 120);
        $distance = array_map(fn (int $t) => $t * 3.0 + ($t >= 60 ? 40 : 0), $time); // saut GPS de 40 m en 1 s

        $top = RunningRecords::topSpeed(['time' => $time, 'distance' => $distance]);

        $this->assertSame(3.0, $top); // le saut de 40 m en 1 s est écarté
    }

    public function test_route_svg_fits_the_track_in_its_frame(): void
    {
        $path = RouteSvg::path([[48.85, 2.35], [48.86, 2.36]], 600, 200);

        $this->assertMatchesRegularExpression('/^M[\d.]+ [\d.]+ L[\d.]+ [\d.]+$/', $path);
        preg_match_all('/[\d.]+/', $path, $numbers);
        foreach ($numbers[0] as $index => $value) {
            $this->assertLessThanOrEqual($index % 2 === 0 ? 600 : 200, (float) $value);
        }
        $this->assertNull(RouteSvg::path([[48.85, 2.35]], 600, 200));
    }
}
