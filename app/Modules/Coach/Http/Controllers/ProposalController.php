<?php

namespace App\Modules\Coach\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coach\Models\Proposal;
use App\Modules\Coach\Services\GymCatalog;
use App\Modules\Coach\Services\PlanApplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Réponse de l'athlète à une proposition du coach (programme ou ajustement).
 */
class ProposalController extends Controller
{
    public function accept(Proposal $proposal, PlanApplier $applier): RedirectResponse
    {
        Gate::authorize('update', $proposal);
        abort_unless($proposal->isPending(), 422, 'Cette proposition a déjà reçu une réponse.');
        abort_if($proposal->kind === 'exercises', 422, 'Les exercices proposés se valident un par un.');

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

    /**
     * Réponse à un exercice proposé : « J'ai » (machine et exercice ajoutés), « Pas dans ma salle » (machine absente) ou « Non merci ».
     */
    public function answerExercise(Request $request, Proposal $proposal, int $index, GymCatalog $catalog): RedirectResponse
    {
        Gate::authorize('update', $proposal);
        abort_unless($proposal->kind === 'exercises' && $proposal->isPending(), 422, 'Cette proposition a déjà reçu une réponse.');
        abort_unless(isset($proposal->payload['items'][$index]) && ! isset($proposal->payload['items'][$index]['answer']), 422, 'Cet exercice a déjà reçu une réponse.');
        $data = $request->validate(['answer' => ['required', Rule::in(GymCatalog::ANSWERS)]]);

        $catalog->answer($proposal, $index, $data['answer']);

        $name = $proposal->payload['items'][$index]['name'];

        return to_route('coach.chat.show', $proposal->conversation_id)->with('status', match ($data['answer']) {
            'have' => $name.' ajouté à ta salle.',
            'missing' => 'Noté : pas de '.mb_strtolower($proposal->payload['items'][$index]['equipment'] ?? $name).' dans ta salle.',
            default => $name.' écarté.',
        });
    }
}
