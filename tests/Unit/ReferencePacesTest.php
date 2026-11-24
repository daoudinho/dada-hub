<?php

namespace Tests\Unit;

use App\Modules\Coach\Services\ReferencePaces;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class ReferencePacesTest extends TestCase
{
    /** Valeurs des tables de Jack Daniels (VDOT 50 : 5 km en 19:57, 10 km en 41:21, semi en 1:31:35). */
    public function test_vdot_and_predictions_match_daniels_tables(): void
    {
        $vdot = ReferencePaces::vdot(5000, 1200);

        $this->assertSame(49.8, $vdot);
        $this->assertEqualsWithDelta(2481, ReferencePaces::predict(50, 10000), 15);
        $this->assertEqualsWithDelta(5495, ReferencePaces::predict(50, 21097.5), 30);
    }

    public function test_threshold_pace_matches_daniels_tables(): void
    {
        // VDOT 50 : seuil vers 4:15 /km.
        $this->assertEqualsWithDelta(255, ReferencePaces::pace(50, 0.88), 5);
    }

    #[TestWith([1000, 150])] // moins de 3 min 30 : formule hors domaine
    #[TestWith([60000, 15000])] // plus de 4 h
    public function test_vdot_is_not_computed_outside_its_valid_range(int $meters, int $seconds): void
    {
        $this->assertNull(ReferencePaces::vdot($meters, $seconds));
    }
}
