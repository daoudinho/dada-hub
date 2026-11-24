<?php

namespace App\Modules\Coach\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Paire de chaussures déclarée sur Strava, avec son kilométrage.
 */
class Gear extends Model
{
    use BelongsToUser;

    protected $table = 'coach_gear';

    protected $fillable = ['strava_id', 'name', 'distance_m', 'primary', 'retired'];

    protected $casts = [
        'distance_m' => 'integer',
        'primary' => 'boolean',
        'retired' => 'boolean',
    ];

    public function km(): int
    {
        return (int) round($this->distance_m / 1000);
    }

    /** Au-delà, l'amorti baisse : à signaler au coach. */
    public function isWorn(): bool
    {
        return $this->km() >= (int) config('coach.strava.shoe_alert_km');
    }
}
