<?php

namespace App\Modules\Coach\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ce que le coach retient durablement de l'athlète (historique, blessures, contraintes, motivations),
 * tenu à jour par le coach lui-même : évite de renvoyer toutes les anciennes conversations.
 */
class Memory extends Model
{
    use BelongsToUser;

    protected $table = 'coach_memories';

    protected $fillable = ['content'];
}
