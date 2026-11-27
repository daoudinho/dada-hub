<?php

namespace App\Modules\Coach\Http\Controllers\Fitness;

use App\Http\Controllers\Controller;
use App\Modules\Coach\Http\Requests\WorkoutRequest;
use App\Modules\Coach\Models\Exercise;
use App\Modules\Coach\Models\Workout;
use App\Modules\Coach\Services\WorkoutRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Séances de fitness : historique (poids total soulevé, muscles travaillés), saisie et détail.
 */
class WorkoutController extends Controller
{
    public function index(Request $request): View
    {
        return view('coach::fitness.index', [
            'workouts' => Workout::ownedBy($request->user())->with('sets.exercise')->latest('performed_on')->latest('id')->paginate(20),
            'hasExercises' => Exercise::ownedBy($request->user())->exists(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('coach::fitness.workout-form', [
            'exercises' => Exercise::ownedBy($request->user())->with('equipment')->orderBy('name')->get(),
        ]);
    }

    public function store(WorkoutRequest $request, WorkoutRecorder $recorder): RedirectResponse
    {
        $workout = $recorder->record($request->user(), $request->validated(), 'form');

        return to_route('coach.fitness.workouts.show', $workout)->with('status', 'Séance enregistrée : '.$workout->totalLoadLabel().' soulevés.');
    }

    public function show(Workout $workout): View
    {
        Gate::authorize('view', $workout);

        return view('coach::fitness.workout', ['workout' => $workout->load('sets.exercise.equipment')]);
    }

    public function destroy(Workout $workout): RedirectResponse
    {
        Gate::authorize('delete', $workout);

        $workout->delete();

        return to_route('coach.fitness.index')->with('status', 'Séance supprimée.');
    }
}
