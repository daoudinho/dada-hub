<?php

namespace App\Modules\Coach\Services;

use App\Models\User;
use App\Modules\Coach\Models\Exercise;
use App\Modules\Coach\Models\Workout;
use App\Modules\Coach\Models\WorkoutSet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Enregistre une séance de fitness, saisie dans le formulaire ou racontée au coach :
 * chaque ligne (« 3 séries de 10 à 100 kg ») devient autant de séries, et le poids total soulevé est calculé.
 */
class WorkoutRecorder
{
    /**
     * Règles de validation d'une séance (formulaire et outil du coach).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'performed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'duration_minutes' => ['nullable', 'integer', 'between:5,300'],
            'rpe' => ['nullable', 'integer', 'between:1,10'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'entries' => ['required', 'array', 'min:1', 'max:60'],
            'entries.*.exercise_id' => ['required', 'integer'],
            'entries.*.sets' => ['nullable', 'integer', 'between:1,20'],
            'entries.*.reps' => ['nullable', 'integer', 'between:1,500'],
            'entries.*.load_kg' => ['nullable', 'numeric', 'between:0,1000'],
            'entries.*.duration_seconds' => ['nullable', 'integer', 'between:1,3600'],
        ];
    }

    /**
     * @param  array{performed_on: string, duration_minutes?: ?int, rpe?: ?int, notes?: ?string, entries: list<array{exercise_id: int, sets?: ?int, reps?: ?int, load_kg?: float|string|null, duration_seconds?: ?int}>}  $data  validées par rules()
     *
     * @throws ValidationException exercice inconnu, ou série sans ce que l'exercice mesure
     */
    public function record(User $user, array $data, string $source): Workout
    {
        $exercises = Exercise::ownedBy($user)->whereIn('id', collect($data['entries'])->pluck('exercise_id'))->get()->keyBy('id');

        foreach ($data['entries'] as $index => $entry) {
            $exercise = $exercises->get((int) $entry['exercise_id']);
            if (! $exercise) {
                throw ValidationException::withMessages(["entries.$index.exercise_id" => 'Exercice introuvable (#'.$entry['exercise_id'].') : choisis-le dans ton catalogue.']);
            }
            if ($exercise->measure === 'duration' && empty($entry['duration_seconds'])) {
                throw ValidationException::withMessages(["entries.$index.duration_seconds" => $exercise->name.' : indique la durée de chaque série.']);
            }
            if ($exercise->measure !== 'duration' && empty($entry['reps'])) {
                throw ValidationException::withMessages(["entries.$index.reps" => $exercise->name.' : indique le nombre de répétitions.']);
            }
        }

        return DB::transaction(function () use ($user, $data, $source, $exercises) {
            $workout = new Workout([
                'performed_on' => $data['performed_on'],
                'duration_minutes' => $data['duration_minutes'] ?? null,
                'rpe' => $data['rpe'] ?? null,
                'notes' => $data['notes'] ?? null,
                'source' => $source,
            ]);
            $workout->user()->associate($user)->save();

            $position = 0;
            foreach ($data['entries'] as $entry) {
                $exercise = $exercises->get((int) $entry['exercise_id']);
                for ($i = 0; $i < (int) ($entry['sets'] ?? 1); $i++) {
                    $set = new WorkoutSet([
                        'exercise_id' => $exercise->id,
                        'position' => ++$position,
                        'reps' => $exercise->measure === 'duration' ? null : $entry['reps'],
                        'load_kg' => $exercise->measure === 'reps' || ! isset($entry['load_kg']) || $entry['load_kg'] === '' ? null : (float) $entry['load_kg'],
                        'duration_seconds' => $exercise->measure === 'duration' ? $entry['duration_seconds'] : null,
                    ]);
                    $set->setRelation('exercise', $exercise);
                    $workout->sets()->save($set);
                }
            }

            $workout->load('sets.exercise');
            $workout->update(['total_load_kg' => (int) round($workout->sets->sum(fn (WorkoutSet $set) => $set->liftedKg()))]);

            return $workout;
        });
    }
}
