<?php

namespace App\Modules\Coach\Http\Controllers\Running;

use App\Http\Controllers\Controller;
use App\Modules\Coach\Models\RunningSession;
use App\Modules\Coach\Services\CoachConversation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Annulation d'une séance prévue : le coach demandera pourquoi pour adapter la suite.
 */
class SessionController extends Controller
{
    public function cancel(Request $request, RunningSession $session, CoachConversation $coach): RedirectResponse
    {
        Gate::authorize('update', $session);
        abort_unless($session->isPlanned(), 422, 'Cette séance n\'est plus prévue.');

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        DB::transaction(function () use ($request, $session, $coach, $data) {
            $session->update(['status' => 'cancelled', 'cancel_reason' => $data['reason'] ?? null]);
            $coach->start($request->user(), 'cancellation', 'Séance annulée : '.$session->title, $session);
        });

        return back()->with('status', 'Séance annulée. Ton coach voudra en parler pour adapter la suite.');
    }
}
