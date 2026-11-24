<?php

namespace App\Modules\Coach\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Blessure passée ou fragilité récurrente (genou, tibia, tendon d'Achille…).
 */
class Injury extends Model
{
    use BelongsToUser;

    protected $table = 'coach_injuries';

    protected $fillable = ['zone', 'description', 'period', 'recurrent', 'current'];

    protected $casts = [
        'recurrent' => 'boolean',
        'current' => 'boolean',
    ];

    public function label(): string
    {
        return collect([
            $this->zone,
            $this->description,
            $this->period,
            $this->recurrent ? 'récurrente' : null,
            $this->current ? 'ENCORE GÊNANTE' : null,
        ])->filter()->implode(' · ');
    }
}
