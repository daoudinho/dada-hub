<?php

namespace App\Modules\Coach\Models;

use App\Modules\Coach\Database\Factories\WorkoutFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Séance de fitness faite, avec ses séries. Le poids total soulevé est enregistré à la saisie (WorkoutRecorder).
 */
class Workout extends Model
{
    use BelongsToUser;

    /** @use HasFactory<WorkoutFactory> */
    use HasFactory;

    protected $table = 'coach_workouts';

    protected $fillable = ['performed_on', 'duration_minutes', 'rpe', 'notes', 'source', 'total_load_kg'];

    protected $casts = [
        'performed_on' => 'date',
        'duration_minutes' => 'integer',
        'rpe' => 'integer',
        'total_load_kg' => 'integer',
    ];

    /** @return HasMany<WorkoutSet, $this> */
    public function sets(): HasMany
    {
        return $this->hasMany(WorkoutSet::class)->orderBy('position');
    }

    /**
     * Groupes musculaires principaux travaillés (clé => libellé), dans l'ordre de Exercise::MUSCLES. Séries et exercices chargés.
     *
     * @return Collection<string, string>
     */
    public function muscles(): Collection
    {
        return Exercise::muscleLabels($this->sets->flatMap(fn (WorkoutSet $set) => $set->exercise->muscles ?? [])->unique());
    }

    /**
     * Séries regroupées par exercice, dans l'ordre de la séance. Séries et exercices chargés.
     *
     * @return Collection<int, array{exercise: Exercise, sets: Collection<int, WorkoutSet>, load_kg: int}>
     */
    public function blocks(): Collection
    {
        $blocks = collect();
        foreach ($this->sets as $set) {
            $last = $blocks->last();
            if ($last && $last['exercise']->is($set->exercise)) {
                $last['sets']->push($set);
                $blocks->put($blocks->keys()->last(), $last);
            } else {
                $blocks->push(['exercise' => $set->exercise, 'sets' => collect([$set])]);
            }
        }

        return $blocks->map(fn (array $block) => $block + ['load_kg' => (int) round($block['sets']->sum(fn (WorkoutSet $set) => $set->liftedKg()))]);
    }

    /** « 6 840 kg » */
    public function totalLoadLabel(): string
    {
        return number_format($this->total_load_kg, 0, ',', ' ').' kg';
    }

    /** Résumé pour le coach : « 27/11 · 52 min · effort 7/10 · 6 840 kg : Presse à cuisses 3×10 à 100 kg ; Clamshell 2×15 ». Séries et exercices chargés. */
    public function summary(): string
    {
        $head = collect([
            $this->performed_on->format('d/m/Y'),
            $this->duration_minutes ? $this->duration_minutes.' min' : null,
            $this->rpe ? 'effort '.$this->rpe.'/10' : null,
            $this->totalLoadLabel(),
        ])->filter()->implode(' · ');

        return $head.' : '.$this->blocks()->map(fn (array $block) => $block['exercise']->name.' '.WorkoutSet::describe($block['sets']))->implode(' ; ')
            .($this->notes ? ' — note : « '.$this->notes.' »' : '');
    }

    protected static function newFactory(): WorkoutFactory
    {
        return WorkoutFactory::new();
    }
}
