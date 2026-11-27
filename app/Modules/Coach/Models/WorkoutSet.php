<?php

namespace App\Modules\Coach\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Série d'une séance : répétitions et charge, ou durée (gainage).
 */
class WorkoutSet extends Model
{
    public $timestamps = false;

    protected $table = 'coach_workout_sets';

    protected $fillable = ['exercise_id', 'position', 'reps', 'load_kg', 'duration_seconds'];

    protected $casts = [
        'position' => 'integer',
        'reps' => 'integer',
        'load_kg' => 'float',
        'duration_seconds' => 'integer',
    ];

    /** @return BelongsTo<Workout, $this> */
    public function workout(): BelongsTo
    {
        return $this->belongsTo(Workout::class);
    }

    /** @return BelongsTo<Exercise, $this> */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /**
     * Poids soulevé : répétitions × charge ajoutée (le poids du corps ne compte pas), les deux côtés pour un exercice unilatéral.
     */
    public function liftedKg(): float
    {
        if (! $this->reps || ! $this->load_kg) {
            return 0;
        }

        return $this->reps * $this->load_kg * ($this->exercise?->unilateral ? 2 : 1);
    }

    /** « 10 × 100 kg », « 15 », « 45 s » */
    public function label(): string
    {
        if ($this->duration_seconds) {
            return $this->duration_seconds.' s'.($this->load_kg ? ' à '.self::kg($this->load_kg) : '');
        }

        return ($this->reps ?? 0).($this->load_kg ? ' × '.self::kg($this->load_kg) : '');
    }

    /**
     * Séries d'un même exercice en abrégé : « 3×10 à 100 kg », ou « 10 × 100 kg, 8 × 105 kg » si elles diffèrent.
     *
     * @param  Collection<int, WorkoutSet>  $sets
     */
    public static function describe(Collection $sets): string
    {
        $first = $sets->first();
        $identical = $sets->every(fn (WorkoutSet $set) => [$set->reps, $set->load_kg, $set->duration_seconds] === [$first->reps, $first->load_kg, $first->duration_seconds]);

        if (! $identical) {
            return $sets->map(fn (WorkoutSet $set) => $set->label())->implode(', ');
        }

        $unit = $first->duration_seconds ? $first->duration_seconds.' s' : (string) ($first->reps ?? 0);

        return $sets->count().'×'.$unit.($first->load_kg ? ' à '.self::kg($first->load_kg) : '');
    }

    /** « 102,5 kg » */
    public static function kg(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', ' '), '0'), ',').' kg';
    }
}
