<?php

namespace App\Modules\Coach\Models;

use App\Modules\Coach\Database\Factories\ExerciseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Exercice du catalogue de l'athlète (« Presse à cuisses », « Clamshell »), sur une machine ou au poids du corps.
 */
class Exercise extends Model
{
    use BelongsToUser;

    /** @use HasFactory<ExerciseFactory> */
    use HasFactory;

    /** Ce qu'on note à chaque série. */
    public const MEASURES = ['load_reps' => 'Charge × répétitions', 'reps' => 'Répétitions', 'duration' => 'Durée'];

    /** Groupes musculaires, dans l'ordre d'affichage. */
    public const MUSCLES = [
        'chest' => 'Pectoraux',
        'back' => 'Dos',
        'shoulders' => 'Épaules',
        'biceps' => 'Biceps',
        'triceps' => 'Triceps',
        'core' => 'Abdos / gainage',
        'glutes' => 'Fessiers',
        'glute_med' => 'Moyen fessier',
        'quads' => 'Quadriceps',
        'hamstrings' => 'Ischios',
        'adductors' => 'Adducteurs',
        'calves' => 'Mollets',
    ];

    protected $table = 'coach_exercises';

    protected $fillable = ['equipment_id', 'name', 'measure', 'muscles', 'secondary_muscles', 'unilateral', 'notes'];

    protected $casts = [
        'muscles' => 'array',
        'secondary_muscles' => 'array',
        'unilateral' => 'boolean',
    ];

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /** @return HasMany<WorkoutSet, $this> */
    public function sets(): HasMany
    {
        return $this->hasMany(WorkoutSet::class);
    }

    /** « Quadriceps, Fessiers » */
    public function musclesLabel(): string
    {
        return self::muscleLabels($this->muscles ?? [])->implode(', ');
    }

    /**
     * Libellés de groupes musculaires, dans l'ordre de MUSCLES.
     *
     * @param  iterable<string>  $keys
     * @return Collection<string, string>
     */
    public static function muscleLabels(iterable $keys): Collection
    {
        return collect(self::MUSCLES)->only(collect($keys)->all());
    }

    protected static function newFactory(): ExerciseFactory
    {
        return ExerciseFactory::new();
    }
}
