<?php

namespace App\Modules\Coach\Console;

use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Services\ReferencePaces;
use App\Modules\Coach\Services\Strava\ActivityAnalysis;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('coach:reanalyze')]
#[Description('Recalcule l\'analyse des courses déjà importées (fractions, moitiés, coup de mou, dénivelé), à partir de leurs courbes')]
class ReanalyzeActivitiesCommand extends Command
{
    public function handle(ActivityAnalysis $analysis, ReferencePaces $referencePaces): int
    {
        $zones = [];
        $count = 0;

        RunningActivity::whereNotNull('streams')->with(['session', 'user'])->lazyById(50)->each(function (RunningActivity $activity) use ($analysis, $referencePaces, &$zones, &$count) {
            $zones[$activity->user_id] ??= $referencePaces->zonesFor($activity->user);
            $activity->forceFill(['analysis' => $analysis->analyzeActivity($activity, $activity->session, $zones[$activity->user_id])])->save();
            $count++;
        });

        $this->info($count.' course(s) réanalysée(s).');

        return self::SUCCESS;
    }
}
