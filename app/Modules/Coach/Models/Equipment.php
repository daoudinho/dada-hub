<?php

namespace App\Modules\Coach\Models;

use App\Modules\Coach\Database\Factories\EquipmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Machine ou accessoire de la salle (presse, haltères, élastique), découvert au fil des propositions du coach :
 * disponible, ou absent (le coach ne le propose plus).
 */
class Equipment extends Model
{
    use BelongsToUser;

    /** @use HasFactory<EquipmentFactory> */
    use HasFactory;

    public const STATUSES = ['available' => 'Disponible', 'missing' => 'Pas dans ma salle'];

    protected $table = 'coach_equipment';

    protected $fillable = ['name', 'status', 'load_step_kg'];

    protected $casts = ['load_step_kg' => 'float'];

    /** @return HasMany<Exercise, $this> */
    public function exercises(): HasMany
    {
        return $this->hasMany(Exercise::class);
    }

    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }

    protected static function newFactory(): EquipmentFactory
    {
        return EquipmentFactory::new();
    }
}
