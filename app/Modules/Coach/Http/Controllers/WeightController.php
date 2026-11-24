<?php

namespace App\Modules\Coach\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coach\Models\WeightEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Pesées : une par jour (une nouvelle saisie le même jour remplace la précédente).
 */
class WeightController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->merge(['weight_kg' => str_replace(',', '.', (string) $request->input('weight_kg'))]);
        $data = $request->validate([
            'weight_kg' => ['required', 'numeric', 'between:20,350'],
            'measured_on' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $date = $data['measured_on'] ?? today()->toDateString();
        $entry = WeightEntry::ownedBy($request->user())->whereDate('measured_on', $date)->first()
            ?? (new WeightEntry(['measured_on' => $date]))->user()->associate($request->user());
        $entry->fill(['weight_kg' => $data['weight_kg']])->save();

        return back()->with('status', 'Poids enregistré.');
    }

    public function destroy(WeightEntry $weight): RedirectResponse
    {
        Gate::authorize('delete', $weight);

        $weight->delete();

        return back()->with('status', 'Pesée supprimée.');
    }
}
