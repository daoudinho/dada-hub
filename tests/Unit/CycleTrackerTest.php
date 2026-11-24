<?php

namespace Tests\Unit;

use App\Modules\Coach\Services\CycleTracker;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class CycleTrackerTest extends TestCase
{
    public function test_average_length_ignores_implausible_cycles(): void
    {
        $starts = array_map(fn (string $date) => Carbon::parse($date), ['2026-11-08', '2026-10-21', '2026-09-22', '2026-08-26']);

        // 18 jours (écarté), 29 et 27 jours.
        $this->assertSame(28, (new CycleTracker)->averageLength($starts));
    }

    public function test_average_length_defaults_to_28_days_with_a_single_date(): void
    {
        $this->assertSame(28, (new CycleTracker)->averageLength([Carbon::parse('2026-11-08')]));
    }

    #[TestWith([3, 28, false, 'règles'])]
    #[TestWith([8, 28, false, 'phase folliculaire'])]
    #[TestWith([14, 28, false, 'période d\'ovulation'])]
    #[TestWith([20, 28, false, 'phase lutéale'])]
    #[TestWith([16, 32, false, 'phase folliculaire'])] // cycle long : ovulation plus tardive
    #[TestWith([40, 28, false, 'retard ou cycle irrégulier'])]
    #[TestWith([20, 28, true, 'hors règles'])] // contraception hormonale : pas de phases
    public function test_phase_depends_on_the_day_and_the_cycle_length(int $day, int $length, bool $hormonal, string $phase): void
    {
        $this->assertSame($phase, (new CycleTracker)->phase($day, $length, $hormonal));
    }
}
