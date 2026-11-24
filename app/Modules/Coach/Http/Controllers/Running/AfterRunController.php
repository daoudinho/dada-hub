<?php

namespace App\Modules\Coach\Http\Controllers\Running;

use App\Http\Controllers\Controller;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Services\CoachConversation;
use App\Modules\Coach\Services\SessionMatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Après une course : débrief avec le coach depuis sa fiche, et rattachement à une séance prévue un autre jour.
 */
class AfterRunController extends Controller
{
    /** Ouvre le débrief de la course (le reprend s'il existe déjà). */
    public function debrief(Request $request, RunningActivity $activity, CoachConversation $coach): RedirectResponse
    {
        Gate::authorize('update', $activity);

        $conversation = $activity->debrief ?? $coach->startDebrief($request->user(), $activity);

        return to_route('coach.chat.show', $conversation);
    }

    /** « C'était ma séance de mardi » : la course devient cette séance. */
    public function confirmSession(RunningActivity $activity, SessionMatcher $matcher): RedirectResponse
    {
        Gate::authorize('update', $activity);
        $session = $activity->suggestedSession;
        abort_unless($session && $session->user_id === $activity->user_id && $session->status === 'planned', 404);

        $matcher->link($activity, $session);

        return back()->with('status', 'Séance « '.$session->title.' » marquée comme faite.');
    }

    /** « Non, c'était une autre course » : la proposition disparaît. */
    public function dismissSession(RunningActivity $activity): RedirectResponse
    {
        Gate::authorize('update', $activity);
        $activity->update(['suggested_session_id' => null]);

        return back()->with('status', 'Noté : course hors programme.');
    }
}
