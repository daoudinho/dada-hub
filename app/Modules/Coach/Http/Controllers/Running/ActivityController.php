<?php

namespace App\Modules\Coach\Http\Controllers\Running;

use App\Http\Controllers\Controller;
use App\Modules\Coach\Http\Requests\ActivityRequest;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Models\RunningSession;
use App\Modules\Coach\Services\CoachConversation;
use App\Modules\Coach\Services\QuickFeedback;
use App\Modules\Coach\Services\RunningRecords;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Saisie d'une course faite (en attendant l'import Strava) avec son ressenti rapide : valide la séance prévue et ouvre un débrief.
 */
class ActivityController extends Controller
{
    public function create(Request $request): View
    {
        $session = $request->integer('seance')
            ? RunningSession::ownedBy($request->user())->where('status', 'planned')->find($request->integer('seance'))
            : null;

        return view('coach::running.activity-form', [
            'session' => $session,
            'plannedSessions' => RunningSession::ownedBy($request->user())->where('status', 'planned')
                ->whereBetween('scheduled_on', [today()->subDays(7), today()->addDay()])->orderBy('scheduled_on')->get(),
        ]);
    }

    public function store(ActivityRequest $request, CoachConversation $coach, QuickFeedback $quickFeedback, RunningRecords $records): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $session = isset($data['session_id'])
            ? RunningSession::ownedBy($user)->where('status', 'planned')->findOrFail($data['session_id'])
            : null;

        $activity = DB::transaction(function () use ($user, $data, $session, $coach, $quickFeedback, $records) {
            $activity = new RunningActivity([
                'session_id' => $session?->id,
                'source' => 'manual',
                'name' => $session?->title,
                'started_at' => $data['started_at'],
                'distance_m' => (int) round($data['distance_km'] * 1000),
                'moving_seconds' => $data['moving_seconds'],
                'avg_hr' => $data['avg_hr'] ?? null,
                'max_hr' => $data['max_hr'] ?? null,
                'elevation_gain_m' => $data['elevation_gain_m'] ?? null,
                'rpe' => $data['rpe'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $activity->user()->associate($user)->save();
            $session?->update(['status' => 'done']);
            $quickFeedback->record($activity, $data);
            $records->markNewRecords($activity);

            $coach->startDebrief($user, $activity);

            return $activity;
        });

        return to_route('coach.index')->with('status', 'Course enregistrée ('.number_format($activity->distanceKm(), 1, ',', '').' km). Ton coach t\'attend pour le débrief.');
    }
}
