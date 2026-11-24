<?php

namespace App\Modules\Coach\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coach\Models\Goal;
use App\Modules\Coach\Services\Duration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Objectifs saisis par l'athlète : une course principale (une seule active) et des courses intermédiaires.
 */
class GoalController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->merge(['distance_km' => str_replace(',', '.', (string) $request->input('distance_km'))]);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'event_name' => ['nullable', 'string', 'max:150'],
            'event_date' => ['nullable', 'date', 'after_or_equal:today'],
            'distance_km' => ['nullable', 'numeric', 'between:0.4,300'],
            'target_time' => ['nullable', 'string', 'max:12'],
            'finish_only' => ['nullable', 'boolean'],
            'priority' => ['required', Rule::in(array_keys(Goal::PRIORITIES))],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $finishOnly = $request->boolean('finish_only');
        $seconds = $finishOnly ? null : Duration::parse($data['target_time'] ?? null);
        if (! $finishOnly && filled($data['target_time'] ?? null) && ! $seconds) {
            throw ValidationException::withMessages(['target_time' => 'Temps illisible : écris par exemple « 1:45:00 ».']);
        }

        if ($data['priority'] === 'main') {
            Goal::ownedBy($request->user())->where('status', 'active')->where('priority', 'main')->update(['status' => 'abandoned']);
        }

        (new Goal([
            'sport' => 'running',
            'title' => $data['title'],
            'event_name' => $data['event_name'] ?? null,
            'event_date' => $data['event_date'] ?? null,
            'distance_km' => $data['distance_km'] ?? null,
            'target_seconds' => $seconds,
            'finish_only' => $finishOnly,
            'priority' => $data['priority'],
            'notes' => $data['notes'] ?? null,
            'status' => 'active',
        ]))->user()->associate($request->user())->save();

        return back()->with('status', 'Objectif enregistré.');
    }

    /** Objectif atteint ou abandonné : il quitte le tableau de bord. */
    public function update(Request $request, Goal $goal): RedirectResponse
    {
        Gate::authorize('update', $goal);
        $data = $request->validate(['status' => ['required', Rule::in(['achieved', 'abandoned'])]]);

        $goal->update($data);

        return back()->with('status', $data['status'] === 'achieved' ? 'Bravo ! Objectif marqué comme atteint.' : 'Objectif retiré.');
    }
}
