<?php

namespace App\Modules\Coach\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coach\Models\CycleEntry;
use App\Modules\Coach\Models\Injury;
use App\Modules\Coach\Models\PastRace;
use App\Modules\Coach\Models\Profile;
use App\Modules\Coach\Models\Unavailability;
use App\Modules\Coach\Services\Duration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Listes du profil : courses passées, blessures, indisponibilités, débuts de règles.
 */
class AthleteRecordController extends Controller
{
    public function storeRace(Request $request): RedirectResponse
    {
        $request->merge(['distance_km' => str_replace(',', '.', (string) $request->input('distance_km'))]);
        $data = $request->validate([
            'raced_on' => ['required', 'date', 'before_or_equal:today'],
            'name' => ['nullable', 'string', 'max:150'],
            'distance_km' => ['required', 'numeric', 'between:0.4,300'],
            'time' => ['required', 'string', 'max:12'],
        ]);

        $seconds = Duration::parse($data['time']);
        if (! $seconds) {
            throw ValidationException::withMessages(['time' => 'Temps illisible : écris par exemple « 52:10 » ou « 1:58:30 ».']);
        }

        (new PastRace(['raced_on' => $data['raced_on'], 'name' => $data['name'] ?? null, 'distance_km' => $data['distance_km'], 'time_seconds' => $seconds]))
            ->user()->associate($request->user())->save();

        return back()->with('status', 'Course ajoutée.');
    }

    public function destroyRace(PastRace $race): RedirectResponse
    {
        Gate::authorize('delete', $race);
        $race->delete();

        return back()->with('status', 'Course retirée.');
    }

    public function storeInjury(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'zone' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:1000'],
            'period' => ['nullable', 'string', 'max:40'],
            'recurrent' => ['nullable', 'boolean'],
            'current' => ['nullable', 'boolean'],
        ]);

        (new Injury([...$data, 'recurrent' => $request->boolean('recurrent'), 'current' => $request->boolean('current')]))
            ->user()->associate($request->user())->save();

        return back()->with('status', 'Blessure ajoutée.');
    }

    /** Bascule « encore gênante » / « guérie ». */
    public function toggleInjury(Injury $injury): RedirectResponse
    {
        Gate::authorize('update', $injury);
        $injury->update(['current' => ! $injury->current]);

        return back();
    }

    public function destroyInjury(Injury $injury): RedirectResponse
    {
        Gate::authorize('delete', $injury);
        $injury->delete();

        return back()->with('status', 'Blessure retirée.');
    }

    public function storeUnavailability(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'starts_on' => ['required', 'date', 'after_or_equal:today'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'kind' => ['required', Rule::in(array_keys(Unavailability::KINDS))],
            'reason' => ['nullable', 'string', 'max:150'],
        ]);

        (new Unavailability($data))->user()->associate($request->user())->save();

        return back()->with('status', 'Indisponibilité ajoutée : ton coach en tiendra compte.');
    }

    public function destroyUnavailability(Unavailability $unavailability): RedirectResponse
    {
        Gate::authorize('delete', $unavailability);
        $unavailability->delete();

        return back()->with('status', 'Indisponibilité retirée.');
    }

    /** Début de règles (suivi du cycle activé dans le profil). */
    public function storeCycle(Request $request): RedirectResponse
    {
        abort_unless(Profile::ownedBy($request->user())->first()?->tracksCycle(), 403, 'Suivi du cycle non activé.');
        $data = $request->validate(['started_on' => ['required', 'date', 'before_or_equal:today', 'after:'.today()->subYear()->toDateString()]]);

        CycleEntry::ownedBy($request->user())->whereDate('started_on', $data['started_on'])->exists()
            || (new CycleEntry($data))->user()->associate($request->user())->save();

        return back()->with('status', 'Début de cycle noté.');
    }

    public function destroyCycle(CycleEntry $entry): RedirectResponse
    {
        Gate::authorize('delete', $entry);
        $entry->delete();

        return back()->with('status', 'Date retirée.');
    }
}
