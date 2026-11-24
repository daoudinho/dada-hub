<?php

namespace App\Modules\Coach\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coach\Models\Proposal;
use App\Modules\Coach\Services\PlanApplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Réponse de l'athlète à une proposition du coach (programme ou ajustement).
 */
class ProposalController extends Controller
{
    public function accept(Proposal $proposal, PlanApplier $applier): RedirectResponse
    {
        Gate::authorize('update', $proposal);
        abort_unless($proposal->isPending(), 422, 'Cette proposition a déjà reçu une réponse.');

        $applier->accept($proposal);

        return to_route('coach.chat.show', $proposal->conversation_id)
            ->with('status', $proposal->kind === 'plan' ? 'Programme ajouté au calendrier.' : 'Calendrier ajusté.');
    }

    public function refuse(Proposal $proposal): RedirectResponse
    {
        Gate::authorize('update', $proposal);
        abort_unless($proposal->isPending(), 422, 'Cette proposition a déjà reçu une réponse.');

        $proposal->update(['status' => 'refused']);

        return to_route('coach.chat.show', $proposal->conversation_id)
            ->with('status', 'Proposition refusée. Dis à ton coach ce qui ne te convient pas.');
    }
}
