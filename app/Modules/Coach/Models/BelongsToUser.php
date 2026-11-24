<?php

namespace App\Modules\Coach\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Donnée personnelle du module Coach : elle appartient à un compte et n'est visible que de lui (OwnerPolicy).
 */
trait BelongsToUser
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->where($this->qualifyColumn('user_id'), $user->id);
    }
}
