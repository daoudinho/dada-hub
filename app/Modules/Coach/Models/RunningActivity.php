<?php

namespace App\Modules\Coach\Models;

use App\Models\User;
use App\Modules\Coach\Database\Factories\RunningActivityFactory;
use App\Modules\Coach\Services\Duration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Course réellement faite : saisie à la main, ou importée (Garmin, Strava, Google Health) avec ses tours et ses flux.
 */
class RunningActivity extends Model
{
    use BelongsToUser;

    /** @use HasFactory<RunningActivityFactory> */
    use HasFactory;

    protected $table = 'coach_running_activities';

    /** Verdicts du bilan de séance rédigé par le coach. */
    public const VERDICTS = ['reached' => 'Objectif atteint', 'partial' => 'Objectif en partie atteint', 'missed' => 'Objectif manqué', 'free' => 'Course libre'];

    /**
     * Colonnes d'un résumé de course : tout sauf les données volumineuses (courbes, tours, tracé, résumé brut, dynamique),
     * pour les listes et les calculs qui n'en ont pas besoin.
     */
    public const SUMMARY_COLUMNS = ['id', 'user_id', 'session_id', 'suggested_session_id', 'source', 'external_id', 'name', 'sport_type', 'started_at',
        'distance_m', 'moving_seconds', 'elapsed_seconds', 'avg_hr', 'max_hr', 'avg_cadence', 'elevation_gain_m', 'rpe', 'notes', 'gear_id',
        'analysis', 'new_records', 'review', 'created_at', 'updated_at'];

    protected $fillable = ['session_id', 'source', 'external_id', 'name', 'started_at', 'distance_m', 'moving_seconds', 'elapsed_seconds',
        'avg_hr', 'max_hr', 'avg_cadence', 'elevation_gain_m', 'laps', 'streams', 'rpe', 'notes', 'sport_type', 'gear_id', 'analysis',
        'raw_summary', 'dynamics', 'polyline', 'review', 'suggested_session_id', 'new_records'];

    protected $casts = [
        'started_at' => 'datetime',
        'distance_m' => 'integer',
        'moving_seconds' => 'integer',
        'elapsed_seconds' => 'integer',
        'avg_hr' => 'integer',
        'max_hr' => 'integer',
        'avg_cadence' => 'integer',
        'elevation_gain_m' => 'integer',
        'laps' => 'array',
        'streams' => 'array',
        'rpe' => 'integer',
        'analysis' => 'array',
        'raw_summary' => 'array',
        'dynamics' => 'array',
        'polyline' => 'array',
        'review' => 'array',
        'new_records' => 'array',
    ];

    /** @return BelongsTo<RunningSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(RunningSession::class, 'session_id');
    }

    /** Séance non faite des jours précédents, proposée à l'athlète (SessionMatcher). */
    public function suggestedSession(): BelongsTo
    {
        return $this->belongsTo(RunningSession::class, 'suggested_session_id');
    }

    /** Dernier débrief de la course avec le coach. */
    public function debrief(): HasOne
    {
        return $this->hasOne(Conversation::class, 'activity_id')->where('kind', 'debrief')->latestOfMany();
    }

    /** @return HasMany<Feedback, $this> */
    public function feedbacks(): HasMany
    {
        return $this->hasMany(Feedback::class, 'activity_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeSummary(Builder $query): void
    {
        $query->select(self::SUMMARY_COLUMNS);
    }

    /** Montre ou application reliée : les courses arrivent seules. */
    public static function syncedFor(User $user): bool
    {
        return GarminAccount::ownedBy($user)->exists() || StravaAccount::ownedBy($user)->exists() || GoogleHealthAccount::ownedBy($user)->exists();
    }

    public function debriefTitle(): string
    {
        return 'Débrief : '.($this->session?->title ?? $this->name ?? 'course du '.$this->started_at->format('d/m'));
    }

    public function verdictLabel(): ?string
    {
        return self::VERDICTS[$this->review['verdict'] ?? ''] ?? null;
    }

    /** Type d'activité affiché : « Course à pied », « Trail », « Tapis »… */
    public function typeLabel(): string
    {
        $type = strtolower((string) $this->sport_type);

        return match (true) {
            str_contains($type, 'trail') => 'Trail',
            str_contains($type, 'treadmill'), str_contains($type, 'virtual'), str_contains($type, 'indoor') => 'Course sur tapis',
            str_contains($type, 'track') => 'Course sur piste',
            default => 'Course à pied',
        };
    }

    public function sourceLabel(): string
    {
        return match ($this->source) {
            'garmin' => 'Garmin',
            'strava' => 'Strava',
            'google_health' => 'Google Health',
            default => 'Saisie',
        };
    }

    /** Lien vers la course sur le site d'origine. */
    public function externalUrl(): ?string
    {
        return match (true) {
            ! $this->external_id => null,
            $this->source === 'garmin' => 'https://connect.garmin.com/modern/activity/'.$this->external_id,
            $this->source === 'strava' => 'https://www.strava.com/activities/'.$this->external_id,
            default => null,
        };
    }

    public function distanceKm(): float
    {
        return round($this->distance_m / 1000, 2);
    }

    public function pace(): ?string
    {
        return Duration::pace($this->moving_seconds, $this->distance_m);
    }

    /** Résumé des métriques pour le coach et l'affichage. */
    public function metrics(): string
    {
        return collect([
            number_format($this->distanceKm(), 2, ',', '').' km',
            Duration::format($this->moving_seconds),
            $this->pace(),
            $this->avg_hr ? 'FC moy. '.$this->avg_hr : null,
            $this->max_hr ? 'FC max '.$this->max_hr : null,
            $this->avg_cadence ? 'cadence '.$this->avg_cadence.' ppm' : null,
            $this->elevation_gain_m ? 'D+ '.$this->elevation_gain_m.' m' : null,
            $this->rpe ? 'effort ressenti '.$this->rpe.'/10' : null,
        ])->filter()->implode(' · ');
    }

    protected static function newFactory(): RunningActivityFactory
    {
        return RunningActivityFactory::new();
    }
}
