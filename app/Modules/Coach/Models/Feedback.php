<?php

namespace App\Modules\Coach\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ressenti après une course ou une séance annulée : effort, fatigue, moral, sommeil, douleurs.
 * Saisi en quelques secondes par l'athlète (source « form ») ou noté par le coach en débrief (« coach »).
 */
class Feedback extends Model
{
    use BelongsToUser;

    protected $table = 'coach_feedbacks';

    protected $fillable = ['conversation_id', 'session_id', 'activity_id', 'rpe', 'fatigue', 'mood', 'sleep', 'pains', 'summary', 'source'];

    protected $casts = [
        'rpe' => 'integer',
        'fatigue' => 'integer',
        'mood' => 'integer',
        'sleep' => 'integer',
        'pains' => 'array',
    ];

    /** @return BelongsTo<RunningActivity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(RunningActivity::class, 'activity_id');
    }

    /** « effort 7/10, forme avant la séance 4/5 (5 = épuisé), sommeil 2/5, moral 4/5 » (notes données seulement). */
    public function scoresLabel(): string
    {
        return collect([
            $this->rpe ? 'effort '.$this->rpe.'/10' : null,
            $this->fatigue ? 'forme avant la séance '.$this->fatigue.'/5 (5 = épuisé)' : null,
            $this->sleep ? 'sommeil '.$this->sleep.'/5' : null,
            $this->mood ? 'moral '.$this->mood.'/5' : null,
        ])->filter()->implode(', ');
    }

    /** « mollet gauche 5/10, genou 2/10 » ; vide sans douleur. */
    public function painsLabel(): string
    {
        return collect($this->pains ?? [])->map(fn (array $pain) => ($pain['zone'] ?? '?').' '.($pain['intensity'] ?? '?').'/10')->implode(', ');
    }

    /** Intensité de la douleur la plus forte signalée (0 à 10). */
    public function maxPain(): int
    {
        return (int) collect($this->pains ?? [])->max('intensity');
    }
}
