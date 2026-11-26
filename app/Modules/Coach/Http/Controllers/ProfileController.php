<?php

namespace App\Modules\Coach\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coach\Models\CycleEntry;
use App\Modules\Coach\Models\GarminAccount;
use App\Modules\Coach\Models\Gear;
use App\Modules\Coach\Models\Goal;
use App\Modules\Coach\Models\GoogleHealthAccount;
use App\Modules\Coach\Models\Injury;
use App\Modules\Coach\Models\PastRace;
use App\Modules\Coach\Models\Profile;
use App\Modules\Coach\Models\StravaAccount;
use App\Modules\Coach\Models\Unavailability;
use App\Modules\Coach\Services\CycleTracker;
use App\Modules\Coach\Services\ReferencePaces;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Profil de l'athlète : profil physique, historique, blessures, contraintes de vie, objectifs, cycle, repères calculés.
 */
class ProfileController extends Controller
{
    public function edit(Request $request, ReferencePaces $referencePaces, CycleTracker $cycleTracker): View
    {
        $user = $request->user();
        $profile = Profile::ownedBy($user)->first() ?? new Profile;

        return view('coach::profile', [
            'profile' => $profile,
            'goals' => Goal::ownedBy($user)->where('status', 'active')->orderByRaw("case when priority = 'main' then 0 else 1 end")->orderBy('event_date')->get(),
            'pastRaces' => PastRace::ownedBy($user)->orderByDesc('raced_on')->get(),
            'injuries' => Injury::ownedBy($user)->orderByDesc('current')->latest()->get(),
            'unavailabilities' => Unavailability::ownedBy($user)->where('ends_on', '>=', today())->orderBy('starts_on')->get(),
            'references' => $referencePaces->forUser($user),
            'cycle' => $cycleTracker->status($user),
            'garmin' => GarminAccount::ownedBy($user)->first(),
            'strava' => StravaAccount::ownedBy($user)->first(),
            'googleHealth' => GoogleHealthAccount::ownedBy($user)->first(),
            'gear' => Gear::ownedBy($user)->where('retired', false)->orderByDesc('primary')->get(),
            'cycleEntries' => $profile->tracksCycle() ? CycleEntry::ownedBy($user)->orderByDesc('started_on')->limit(12)->get() : collect(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sex' => ['nullable', Rule::in(array_keys(Profile::SEXES))],
            'birth_date' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'height_cm' => ['nullable', 'integer', 'between:80,250'],
            'resting_hr' => ['nullable', 'integer', 'between:25,120'],
            'max_hr' => ['nullable', 'integer', 'between:100,240'],
            'threshold_hr' => ['nullable', 'integer', 'between:80,230'],
            'running_since' => ['nullable', 'integer', 'between:1950,'.today()->year],
            'other_activities' => ['nullable', 'string', 'max:1000'],
            'long_run_day' => ['nullable', Rule::in(array_keys(Profile::DAYS))],
            'availability' => ['nullable', 'array'],
            'availability.*.available' => ['nullable', 'boolean'],
            'availability.*.max_minutes' => ['nullable', 'integer', 'between:10,600'],
            'availability.*.slot' => ['nullable', Rule::in(array_keys(Profile::SLOTS))],
            'availability_notes' => ['nullable', 'string', 'max:1500'],
            'cycle_tracking' => ['nullable', 'boolean'],
            'hormonal_contraception' => ['nullable', 'boolean'],
        ]);

        $availability = collect(Profile::DAYS)->map(fn (string $label, string $day) => [
            'available' => (bool) ($data['availability'][$day]['available'] ?? false),
            'max_minutes' => $data['availability'][$day]['max_minutes'] ?? null,
            'slot' => $data['availability'][$day]['slot'] ?? null,
        ]);
        $isFemale = ($data['sex'] ?? null) === 'female';

        $profile = Profile::ownedBy($request->user())->first() ?? (new Profile)->user()->associate($request->user());
        $profile->fill([
            ...collect($data)->only(['sex', 'birth_date', 'height_cm', 'resting_hr', 'max_hr', 'threshold_hr', 'running_since', 'other_activities', 'long_run_day', 'availability_notes'])->all(),
            // Aucune case cochée : disponibilités non renseignées (le coach les demandera).
            'availability' => $availability->contains('available', true) ? $availability->all() : null,
            // Le suivi du cycle ne concerne que les femmes.
            'cycle_tracking' => $isFemale && $request->boolean('cycle_tracking'),
            'hormonal_contraception' => $isFemale && $request->boolean('hormonal_contraception'),
        ])->save();

        return to_route('coach.profile.edit')->with('status', 'Profil enregistré.');
    }
}
