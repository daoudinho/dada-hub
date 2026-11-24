<?php

namespace App\Modules\Coach\Models;

use App\Modules\Coach\Database\Factories\WeightEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Pesée : une par jour au plus.
 */
class WeightEntry extends Model
{
    use BelongsToUser;

    /** @use HasFactory<WeightEntryFactory> */
    use HasFactory;

    protected $table = 'coach_weight_entries';

    protected $fillable = ['measured_on', 'weight_kg'];

    protected $casts = [
        'measured_on' => 'date',
        'weight_kg' => 'float',
    ];

    protected static function newFactory(): WeightEntryFactory
    {
        return WeightEntryFactory::new();
    }
}
