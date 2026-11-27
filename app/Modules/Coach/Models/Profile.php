<?php

namespace App\Modules\Coach\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Données physiques de l'athlète, communes à tous les sous-modules (running, musculation…).
 */
class Profile extends Model
{
    use BelongsToUser;

    public const SEXES = ['female' => 'Femme', 'male' => 'Homme', 'other' => 'Autre'];

    public const DAYS = ['mon' => 'Lundi', 'tue' => 'Mardi', 'wed' => 'Mercredi', 'thu' => 'Jeudi', 'fri' => 'Vendredi', 'sat' => 'Samedi', 'sun' => 'Dimanche'];

    public const SLOTS = ['morning' => 'Matin', 'lunch' => 'Midi', 'evening' => 'Soir', 'any' => 'Variable'];

    public const FITNESS_GOALS = ['strength' => 'Force', 'muscle' => 'Prise de muscle', 'weight_loss' => 'Perte de poids', 'running' => 'Renfo pour la course', 'general' => 'Forme générale'];

    public const FITNESS_LEVELS = ['beginner' => 'Débutant', 'intermediate' => 'Intermédiaire', 'advanced' => 'Confirmé'];

    protected $table = 'coach_profiles';

    protected $fillable = ['sex', 'birth_date', 'height_cm', 'resting_hr', 'max_hr', 'running_since', 'other_activities',
        'availability', 'availability_notes', 'long_run_day', 'cycle_tracking', 'hormonal_contraception', 'threshold_hr', 'hr_zone_floors', 'vo2max', 'vo2max_on',
        'fitness_goal', 'fitness_level', 'fitness_sessions_per_week', 'fitness_session_minutes'];

    protected $casts = [
        'birth_date' => 'date',
        'height_cm' => 'integer',
        'resting_hr' => 'integer',
        'max_hr' => 'integer',
        'running_since' => 'integer',
        'availability' => 'array',
        'cycle_tracking' => 'boolean',
        'hormonal_contraception' => 'boolean',
        'threshold_hr' => 'integer',
        'hr_zone_floors' => 'array',
        'vo2max' => 'float',
        'vo2max_on' => 'date',
        'fitness_sessions_per_week' => 'integer',
        'fitness_session_minutes' => 'integer',
    ];

    public function age(): ?int
    {
        return $this->birth_date?->age;
    }

    /** Le suivi du cycle ne vaut que pour une athlète qui l'a activé. */
    public function tracksCycle(): bool
    {
        return $this->sex === 'female' && $this->cycle_tracking;
    }

    /**
     * Disponibilité d'un jour (clé mon … sun), null si les disponibilités ne sont pas renseignées.
     *
     * @return array{available: bool, max_minutes: ?int, slot: ?string}|null
     */
    public function dayAvailability(string $day): ?array
    {
        if (empty($this->availability)) {
            return null;
        }

        $value = $this->availability[$day] ?? [];

        return [
            'available' => (bool) ($value['available'] ?? false),
            'max_minutes' => isset($value['max_minutes']) ? (int) $value['max_minutes'] : null,
            'slot' => $value['slot'] ?? null,
        ];
    }

    /** Clé du jour (mon … sun) d'une date. */
    public static function dayKey(\DateTimeInterface $date): string
    {
        return array_keys(self::DAYS)[(int) $date->format('N') - 1];
    }
}
