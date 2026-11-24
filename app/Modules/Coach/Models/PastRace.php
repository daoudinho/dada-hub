<?php

namespace App\Modules\Coach\Models;

use App\Modules\Coach\Services\Duration;
use Illuminate\Database\Eloquent\Model;

/**
 * Course déjà courue par l'athlète, avec son temps : sert à estimer son niveau (VDOT) et ses allures.
 */
class PastRace extends Model
{
    use BelongsToUser;

    protected $table = 'coach_past_races';

    protected $fillable = ['raced_on', 'name', 'distance_km', 'time_seconds'];

    protected $casts = [
        'raced_on' => 'date',
        'distance_km' => 'float',
        'time_seconds' => 'integer',
    ];

    public function time(): string
    {
        return Duration::format($this->time_seconds);
    }

    public function label(): string
    {
        return ($this->name ? $this->name.' · ' : '').rtrim(rtrim(number_format($this->distance_km, 2, ',', ''), '0'), ',').' km en '.$this->time().' ('.$this->raced_on->format('m/Y').')';
    }
}
