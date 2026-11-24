<?php

namespace App\Modules\Coach\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Compte Strava relié par l'athlète (OAuth). Les jetons sont chiffrés en base et jamais affichés.
 */
class StravaAccount extends Model
{
    use BelongsToUser;

    protected $table = 'coach_strava_accounts';

    protected $fillable = ['athlete_id', 'athlete_name', 'access_token', 'refresh_token', 'expires_at', 'scopes', 'backfilled_at', 'last_synced_at'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = [
        'athlete_id' => 'integer',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
        'backfilled_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    /** Le jeton d'accès expire (6 h) : on le renouvelle une minute avant. */
    public function tokenExpired(): bool
    {
        return $this->expires_at->lte(now()->addMinute());
    }
}
