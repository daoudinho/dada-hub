<?php

namespace App\Modules\Coach\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Période où l'athlète ne pourra pas courir (vacances, déplacement) ou moins que d'habitude (semaine chargée).
 */
class Unavailability extends Model
{
    use BelongsToUser;

    public const KINDS = ['none' => 'Pas de course', 'reduced' => 'Volume réduit'];

    protected $table = 'coach_unavailabilities';

    protected $fillable = ['starts_on', 'ends_on', 'kind', 'reason'];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];

    public function covers(\DateTimeInterface $date): bool
    {
        $day = $date->format('Y-m-d');

        return $day >= $this->starts_on->toDateString() && $day <= $this->ends_on->toDateString();
    }

    public function label(): string
    {
        $dates = $this->starts_on->equalTo($this->ends_on)
            ? 'le '.$this->starts_on->format('d/m')
            : 'du '.$this->starts_on->format('d/m').' au '.$this->ends_on->format('d/m');

        return $dates.' · '.self::KINDS[$this->kind].($this->reason ? ' ('.$this->reason.')' : '');
    }
}
