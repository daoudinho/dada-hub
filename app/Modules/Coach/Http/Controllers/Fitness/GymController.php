<?php

namespace App\Modules\Coach\Http\Controllers\Fitness;

use App\Http\Controllers\Controller;
use App\Modules\Coach\Models\Equipment;
use App\Modules\Coach\Models\Exercise;
use App\Modules\Coach\Services\GymCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * « Ma salle » : machines (disponibles ou absentes) et exercices, ajoutés surtout au fil des propositions du coach,
 * et modifiables à la main.
 */
class GymController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('coach::fitness.gym', [
            'equipment' => Equipment::ownedBy($user)->withCount('exercises')->orderBy('name')->get(),
            'exercises' => Exercise::ownedBy($user)->with('equipment')->orderBy('name')->get(),
        ]);
    }

    public function storeEquipment(Request $request, GymCatalog $catalog): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'load_step_kg' => ['nullable', 'numeric', 'between:0.25,50'],
        ]);
        if ($catalog->findEquipment($request->user(), $data['name'])) {
            return back()->withErrors(['name' => 'Cette machine est déjà dans ta salle.'])->withInput();
        }

        (new Equipment($data + ['status' => 'available']))->user()->associate($request->user())->save();

        return to_route('coach.fitness.gym')->with('status', 'Machine ajoutée.');
    }

    public function updateEquipment(Request $request, Equipment $equipment): RedirectResponse
    {
        Gate::authorize('update', $equipment);
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Equipment::STATUSES))],
            'load_step_kg' => ['nullable', 'numeric', 'between:0.25,50'],
        ]);

        $equipment->update($data);

        return to_route('coach.fitness.gym')->with('status', $equipment->name.' : '.mb_strtolower(Equipment::STATUSES[$equipment->status]).'.');
    }

    public function destroyEquipment(Equipment $equipment): RedirectResponse
    {
        Gate::authorize('delete', $equipment);

        $equipment->delete();

        return to_route('coach.fitness.gym')->with('status', 'Machine retirée.');
    }

    public function storeExercise(Request $request, GymCatalog $catalog): RedirectResponse
    {
        $data = $this->validateExercise($request);
        if ($catalog->findExercise($request->user(), $data['name'])) {
            return back()->withErrors(['name' => 'Cet exercice est déjà dans ton catalogue.'])->withInput();
        }

        (new Exercise($data))->user()->associate($request->user())->save();

        return to_route('coach.fitness.gym')->with('status', 'Exercice ajouté.');
    }

    public function updateExercise(Request $request, Exercise $exercise, GymCatalog $catalog): RedirectResponse
    {
        Gate::authorize('update', $exercise);
        $data = $this->validateExercise($request);
        $existing = $catalog->findExercise($request->user(), $data['name']);
        if ($existing && ! $existing->is($exercise)) {
            return back()->withErrors(['name' => 'Un autre exercice porte déjà ce nom.']);
        }

        $exercise->update($data);

        return to_route('coach.fitness.gym')->with('status', 'Exercice modifié.');
    }

    public function destroyExercise(Exercise $exercise): RedirectResponse
    {
        Gate::authorize('delete', $exercise);
        if ($exercise->sets()->exists()) {
            return back()->withErrors(['exercise' => $exercise->name.' figure dans tes séances : il reste dans le catalogue pour garder ton historique.']);
        }

        $exercise->delete();

        return to_route('coach.fitness.gym')->with('status', 'Exercice retiré.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateExercise(Request $request): array
    {
        $data = $request->validate([
            ...GymCatalog::exerciseRules(),
            'equipment_id' => ['nullable', 'integer', Rule::exists('coach_equipment', 'id')->where('user_id', $request->user()->id)],
        ]);

        // Les muscles secondaires (donnés par le coach) ne figurent pas dans le formulaire : ils restent tels quels.
        return ['unilateral' => $request->boolean('unilateral')] + $data + ['equipment_id' => null, 'notes' => null];
    }
}
