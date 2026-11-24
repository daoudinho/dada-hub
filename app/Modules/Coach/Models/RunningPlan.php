<?php

namespace App\Modules\Coach\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Programme de course vers un objectif. Un seul programme actif par compte ; les ajustements
 * modifient ses séances à venir et sont tracés dans les propositions acceptées.
 */
class RunningPlan extends Model
{
    use BelongsToUser;

    public const FEASIBILITY = ['realistic' => 'Réaliste', 'ambitious' => 'Ambitieux', 'unrealistic' => 'À revoir'];

    protected $table = 'coach_running_plans';

    protected $fillable = ['goal_id', 'feasibility', 'summary', 'rationale', 'status'];

    /** @return BelongsTo<Goal, $this> */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    /** @return HasMany<RunningSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(RunningSession::class, 'plan_id');
    }
}
