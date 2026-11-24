<?php

namespace App\Modules\Coach\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coach\Models\Conversation;
use App\Modules\Coach\Models\DailyWellness;
use App\Modules\Coach\Models\Goal;
use App\Modules\Coach\Models\Profile;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Models\RunningSession;
use App\Modules\Coach\Models\WeightEntry;
use App\Modules\Coach\Services\Charts;
use App\Modules\Coach\Services\CycleTracker;
use App\Modules\Coach\Services\GettingStarted;
use App\Modules\Coach\Services\RunningRecords;
use App\Modules\Coach\Services\TrainingLoad;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Accueil : objectif, actions en attente, puis selon les sous-modules ouverts (Running : prochaines séances, historique,
 * exploits, kilomètres ; Fitness : poids), récupération et cycle.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, Charts $charts, TrainingLoad $trainingLoad, CycleTracker $cycleTracker, RunningRecords $records, GettingStarted $gettingStarted): View
    {
        $user = $request->user();
        $running = $user->hasModuleFeature('coach', 'running');

        $weights = WeightEntry::ownedBy($user)
            ->where('measured_on', '>=', today()->subDays((int) config('coach.weight_chart_days')))
            ->orderBy('measured_on')
            ->get();
        $goals = Goal::ownedBy($user)->where('status', 'active')->orderBy('event_date')->get();
        $hasOnboarding = Conversation::ownedBy($user)->where('kind', 'onboarding')->exists();
        // Dernière course de la semaine, tant que son parcours (ressenti → débrief → bilan) n'est pas fini, ou 2 jours après.
        $lastRun = $running ? RunningActivity::ownedBy($user)->summary()->with(['session', 'suggestedSession', 'debrief'])->where('started_at', '>=', today()->subDays(7))->latest('started_at')->first() : null;
        if ($lastRun?->review && $lastRun->started_at->lt(today()->subDays(2))) {
            $lastRun = null;
        }

        $withoutFeedback = RunningActivity::ownedBy($user)->summary()->where('started_at', '>=', today()->subDays(7))
            ->whereDoesntHave('feedbacks')->latest('started_at')->get();

        return view('coach::dashboard', [
            'running' => $running,
            'fitness' => $user->hasModuleFeature('coach', 'fitness'),
            'exploits' => $running ? $records->exploits($user) : [],
            'profile' => Profile::ownedBy($user)->first(),
            'goal' => $goals->firstWhere('priority', 'main'),
            'intermediateGoals' => $goals->where('priority', 'intermediate')->values(),
            'lastRun' => $lastRun,
            'lastRunHasFeedback' => $lastRun && ! $withoutFeedback->contains('id', $lastRun->id),
            // Le débrief de la dernière course est proposé dans sa carte.
            'awaiting' => Conversation::ownedBy($user)->whereIn('kind', ['debrief', 'cancellation'])->where('status', '!=', 'closed')
                ->when($lastRun, fn ($query) => $query->where(fn ($query) => $query->whereNull('activity_id')->orWhere('activity_id', '!=', $lastRun->id)))
                ->latest()->get(),
            'withoutFeedback' => $withoutFeedback->reject(fn (RunningActivity $run) => $run->id === $lastRun?->id)->values(),
            // Nuits déjà mesurées par la montre : la question du sommeil disparaît de ces ressentis.
            'measuredNights' => DailyWellness::measuredNights($user, $withoutFeedback->pluck('started_at')),
            'recentRuns' => RunningActivity::ownedBy($user)->summary()->with('session')->latest('started_at')->limit(5)->get(),
            'hasOnboarding' => $hasOnboarding,
            // Tant que l'athlète n'a pas fait connaissance avec son coach : parcours de démarrage.
            'start' => $hasOnboarding || $goals->isNotEmpty() ? null : $gettingStarted->status($user),
            'upcoming' => RunningSession::ownedBy($user)->where('status', 'planned')->whereBetween('scheduled_on', [today(), today()->addDays(7)])->orderBy('scheduled_on')->get(),
            'cycle' => $cycleTracker->status($user),
            'wellness' => DailyWellness::ownedBy($user)->where('day', '>=', today()->subDays(2))->orderByDesc('day')->first(),
            'recoverySignals' => $trainingLoad->recoverySignals($user),
            'autoSync' => RunningActivity::syncedFor($user),
            'weights' => $weights,
            'latestWeight' => WeightEntry::ownedBy($user)->orderByDesc('measured_on')->first(),
            'weightChart' => $charts->weight($weights),
            'distanceChart' => $charts->distance($trainingLoad->weeklyDistance($user, (int) config('coach.distance_chart_weeks'))),
        ]);
    }
}
