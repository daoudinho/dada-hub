<?php

namespace App\Modules\Coach\Models;

use App\Modules\Coach\Database\Factories\GoalFactory;
use App\Modules\Coach\Services\Duration;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Objectif de l'athlète (« Semi-marathon de Paris en 1h45 »), fixé avec le coach.
 */
class Goal extends Model
{
    use BelongsToUser;

    /** @use HasFactory<GoalFactory> */
    use HasFactory;

    protected $table = 'coach_goals';

    public const PRIORITIES = ['main' => 'Course principale', 'intermediate' => 'Course intermédiaire'];

    protected $fillable = ['sport', 'title', 'event_name', 'event_date', 'distance_km', 'target_seconds', 'finish_only', 'priority', 'notes', 'status'];

    protected $casts = [
        'event_date' => 'date',
        'distance_km' => 'float',
        'target_seconds' => 'integer',
        'finish_only' => 'boolean',
    ];

    public function isMain(): bool
    {
        return $this->priority === 'main';
    }

    /** « 1h50:00 », « finir » ou null. */
    public function targetLabel(): ?string
    {
        return $this->finish_only ? 'finir' : $this->targetTime();
    }

    /** Jours restants avant la course, null sans date ou si elle est passée. */
    public function daysLeft(): ?int
    {
        if (! $this->event_date || $this->event_date->isBefore(today())) {
            return null;
        }

        return (int) today()->diffInDays($this->event_date);
    }

    /** Phrase du tableau de bord : « Plus que 42 jours avant le semi-marathon de Paris ». */
    public function countdown(): string
    {
        $days = $this->daysLeft();
        $event = $this->event_name ?: $this->title;

        return match (true) {
            $days === null => $this->title,
            $days === 0 => 'C\'est aujourd\'hui : '.$event.' !',
            $days === 1 => 'Plus qu\'un jour avant '.$event,
            default => 'Plus que '.$days.' jours avant '.$event,
        };
    }

    public function targetTime(): ?string
    {
        return $this->target_seconds ? Duration::format($this->target_seconds) : null;
    }

    protected static function newFactory(): GoalFactory
    {
        return GoalFactory::new();
    }
}
