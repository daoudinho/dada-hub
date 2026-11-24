<?php

namespace App\Modules\Coach\Http\Controllers\Running;

use App\Http\Controllers\Controller;
use App\Modules\Coach\Models\Goal;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Models\RunningPlan;
use App\Modules\Coach\Models\RunningSession;
use App\Modules\Coach\Models\Unavailability;
use App\Modules\Coach\Services\CycleTracker;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Programme sous forme de calendrier : 5 semaines à partir de la semaine en cours (ou d'une autre semaine choisie),
 * avec les séances du programme (prévues, faites, annulées). Les courses hors programme sont dans l'historique.
 */
class CalendarController extends Controller
{
    /** Semaines affichées. */
    public const WEEKS = 5;

    /** Pas des flèches précédent / suivant (semaines). */
    public const STEP = 4;

    public function __invoke(Request $request, CycleTracker $cycleTracker): View
    {
        $user = $request->user();
        $start = $this->firstMonday((string) $request->query('semaine'));
        $end = $start->copy()->addWeeks(self::WEEKS)->subDay();

        $sessions = RunningSession::ownedBy($user)->with('activity')->whereBetween('scheduled_on', [$start, $end])->orderBy('scheduled_on')->get()
            ->groupBy(fn (RunningSession $session) => $session->scheduled_on->toDateString());

        // Jours de règles (en cours ou prévues), si l'athlète suit son cycle.
        $periodDays = [];
        foreach ($cycleTracker->forecast($user, $start, $end) as $cycle) {
            [$first, $last] = $cycle['phases']['règles'];
            foreach ($first->toPeriod($last) as $day) {
                $periodDays[$day->toDateString()] = $cycle['expected'] ? 'Règles prévues' : 'Règles';
            }
        }

        return view('coach::running.calendar', [
            'periodDays' => $periodDays,
            'start' => $start,
            'end' => $end,
            'isCurrent' => $start->equalTo(today()->startOfWeek()),
            'previous' => $start->copy()->subWeeks(self::STEP)->toDateString(),
            'next' => $start->copy()->addWeeks(self::STEP)->toDateString(),
            'days' => collect($start->toPeriod($end)),
            'sessions' => $sessions,
            'unavailabilities' => Unavailability::ownedBy($user)->where('ends_on', '>=', $start)->where('starts_on', '<=', $end)->get(),
            'autoSync' => RunningActivity::syncedFor($user),
            'plan' => RunningPlan::ownedBy($user)->where('status', 'active')->latest()->first(),
            'goal' => Goal::ownedBy($user)->where('status', 'active')->where('priority', 'main')->latest()->first(),
        ]);
    }

    /** Lundi de la semaine demandée (« 2026-11-18 » → lundi 16) ; semaine en cours si absent ou illisible. */
    private function firstMonday(string $value): Carbon
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? Carbon::createFromFormat('!Y-m-d', $value) : false;

        return ($date ?: today())->startOfWeek();
    }
}
