<?php

namespace App\Modules\Coach\Models;

use App\Modules\Coach\Services\Duration;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Meilleur temps sur une distance repéré par Strava dans une course (« 5K », « 10K », « Half-Marathon »…).
 */
class BestEffort extends Model
{
    use BelongsToUser;

    /** Distances retenues pour estimer le niveau (les plus courtes sont trop sensibles aux erreurs GPS). */
    public const RELEVANT = ['5K', '10K', '15K', '10 mile', '20K', 'Half-Marathon', 'Marathon'];

    protected $table = 'coach_best_efforts';

    protected $fillable = ['activity_id', 'name', 'distance_m', 'elapsed_seconds', 'achieved_on'];

    protected $casts = [
        'distance_m' => 'integer',
        'elapsed_seconds' => 'integer',
        'achieved_on' => 'date',
    ];

    /** @return BelongsTo<RunningActivity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(RunningActivity::class, 'activity_id');
    }

    public function label(): string
    {
        return $this->name.' en '.Duration::format($this->elapsed_seconds).' ('.$this->achieved_on->format('d/m/Y').', '.($this->activity?->sourceLabel() ?? 'montre').')';
    }
}
