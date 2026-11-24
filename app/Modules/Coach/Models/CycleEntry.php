<?php

namespace App\Modules\Coach\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Début de règles noté par l'athlète (suivi du cycle menstruel, activé dans son profil, visible d'elle seule).
 */
class CycleEntry extends Model
{
    use BelongsToUser;

    protected $table = 'coach_cycle_entries';

    protected $fillable = ['started_on'];

    protected $casts = [
        'started_on' => 'date',
    ];
}
