<?php

namespace App\Modules\Coach\Console;

use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Services\RunningRecords;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('coach:recompute-records')]
#[Description('Recalcule les exploits (meilleurs temps, vitesse de pointe) des courses déjà importées, à partir de leurs courbes')]
class RecomputeRecordsCommand extends Command
{
    public function handle(): int
    {
        $count = 0;

        RunningActivity::whereNotNull('streams')->lazyById(50)->each(function (RunningActivity $activity) use (&$count) {
            $analysis = $activity->analysis ?? [];
            if ($topSpeed = RunningRecords::topSpeed($activity->streams)) {
                $analysis['top_speed'] = $topSpeed;
                $activity->forceFill(['analysis' => $analysis])->save();
            }
            RunningRecords::saveBestTimes($activity);
            $count++;
        });

        $this->info($count.' course(s) recalculée(s).');

        return self::SUCCESS;
    }
}
