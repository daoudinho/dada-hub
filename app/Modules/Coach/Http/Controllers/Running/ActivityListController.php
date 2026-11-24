<?php

namespace App\Modules\Coach\Http\Controllers\Running;

use App\Http\Controllers\Controller;
use App\Modules\Coach\Jobs\WriteAutoReviewJob;
use App\Modules\Coach\Models\BestEffort;
use App\Modules\Coach\Models\DailyWellness;
use App\Modules\Coach\Models\Feedback;
use App\Modules\Coach\Models\Gear;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Services\ReferencePaces;
use App\Modules\Coach\Services\RunInsights;
use App\Modules\Coach\Services\Strava\ActivityAnalysis;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Historique des courses (saisies ou importées de Strava) et fiche détaillée de chacune.
 */
class ActivityListController extends Controller
{
    public function index(Request $request): View
    {
        $running = $request->user()->hasModuleFeature('coach', 'running');

        return view('coach::running.activities', [
            'running' => $running,
            'activities' => RunningActivity::ownedBy($request->user())
                ->with('session')
                ->when(! $running, fn ($query) => $query->whereRaw('1 = 0'))
                ->latest('started_at')
                ->paginate(20),
        ]);
    }

    public function show(RunningActivity $activity, ReferencePaces $referencePaces, RunInsights $runInsights): View
    {
        Gate::authorize('view', $activity);
        $activity->load(['session', 'suggestedSession', 'debrief']);
        $insights = $runInsights->forActivity($activity);
        $debrief = $activity->debrief;
        $autoReviewAt = $debrief && ! $activity->review && $debrief->status !== 'closed' ? WriteAutoReviewJob::dueAt($debrief) : null;

        return view('coach::running.activity', [
            'activity' => $activity,
            'findings' => [...ActivityAnalysis::describe($activity->analysis ?? []), ...ActivityAnalysis::describeDynamics($activity->dynamics)],
            'context' => RunInsights::describe($insights),
            'glossary' => ActivityAnalysis::glossary($activity->analysis ?? [], $activity->dynamics, $referencePaces->zonesFor($activity->user), $insights),
            'feedbacks' => Feedback::where('activity_id', $activity->id)->oldest()->get(),
            'bestEfforts' => BestEffort::where('activity_id', $activity->id)->orderBy('distance_m')->get(),
            'gear' => $activity->gear_id ? Gear::where('user_id', $activity->user_id)->where('strava_id', $activity->gear_id)->first() : null,
            'debrief' => $debrief,
            'autoReviewAt' => $autoReviewAt?->isFuture() ? $autoReviewAt : null,
            'measuredSleep' => DailyWellness::measuredNights($activity->user, [$activity->started_at])->first(),
        ]);
    }
}
