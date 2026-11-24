<?php

namespace App\Modules\Coach\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Récupération d'une journée, d'après la montre : FC de repos, Body Battery, stress, sommeil, HRV.
 */
class DailyWellness extends Model
{
    use BelongsToUser;

    protected $table = 'coach_daily_wellness';

    protected $fillable = ['day', 'resting_hr', 'body_battery_min', 'body_battery_max', 'body_battery_charged', 'body_battery_drained',
        'stress_avg', 'sleep_seconds', 'deep_sleep_seconds', 'sleep_score', 'hrv_last_night', 'hrv_weekly_avg', 'hrv_status'];

    protected $casts = [
        'day' => 'date',
        'resting_hr' => 'integer',
        'body_battery_min' => 'integer',
        'body_battery_max' => 'integer',
        'body_battery_charged' => 'integer',
        'body_battery_drained' => 'integer',
        'stress_avg' => 'integer',
        'sleep_seconds' => 'integer',
        'deep_sleep_seconds' => 'integer',
        'sleep_score' => 'integer',
        'hrv_last_night' => 'integer',
        'hrv_weekly_avg' => 'integer',
    ];

    /**
     * Nuits mesurées par la montre, par jour de réveil (clé Y-m-d) : Garmin date la nuit du jour où elle se termine,
     * donc la nuit qui précède une course porte la date de la course.
     *
     * @param  iterable<\DateTimeInterface>  $days
     * @return Collection<string, self>
     */
    public static function measuredNights(User $user, iterable $days): Collection
    {
        $dates = collect($days)->map(fn (\DateTimeInterface $day) => $day->format('Y-m-d'))->unique()->values();

        if ($dates->isEmpty()) {
            return collect();
        }

        return self::ownedBy($user)->whereNotNull('sleep_seconds')
            ->whereBetween('day', [$dates->min(), $dates->max().' 23:59:59'])->get()
            ->toBase() // only() d'une collection Eloquent filtre par identifiant, pas par clé
            ->keyBy(fn (self $wellness) => $wellness->day->toDateString())
            ->only($dates->all());
    }

    /** « 7h12 » */
    public function sleepLabel(): ?string
    {
        return $this->sleep_seconds ? intdiv($this->sleep_seconds, 3600).'h'.str_pad((string) intdiv($this->sleep_seconds % 3600, 60), 2, '0', STR_PAD_LEFT) : null;
    }
}
