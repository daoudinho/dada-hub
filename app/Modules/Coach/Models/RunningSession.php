<?php

namespace App\Modules\Coach\Models;

use App\Modules\Coach\Database\Factories\RunningSessionFactory;
use App\Modules\Coach\Services\Duration;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Séance prévue au calendrier : faite (avec une course), annulée, ou encore à venir.
 */
class RunningSession extends Model
{
    use BelongsToUser;

    /** @use HasFactory<RunningSessionFactory> */
    use HasFactory;

    public const TYPES = [
        'easy' => 'Footing',
        'long' => 'Sortie longue',
        'intervals' => 'Fractionné',
        'tempo' => 'Seuil / tempo',
        'recovery' => 'Récupération',
        'test' => 'Test',
        'race' => 'Course',
    ];

    protected $table = 'coach_running_sessions';

    protected $fillable = ['plan_id', 'scheduled_on', 'type', 'title', 'description', 'duration_minutes', 'distance_km', 'target_pace', 'target_hr',
        'pace_from', 'pace_to', 'hr_from', 'hr_to', 'status', 'cancel_reason'];

    protected $casts = [
        'scheduled_on' => 'date',
        'duration_minutes' => 'integer',
        'distance_km' => 'float',
        'pace_from' => 'integer',
        'pace_to' => 'integer',
        'hr_from' => 'integer',
        'hr_to' => 'integer',
    ];

    /** @return BelongsTo<RunningPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(RunningPlan::class, 'plan_id');
    }

    /** @return HasOne<RunningActivity, $this> */
    public function activity(): HasOne
    {
        return $this->hasOne(RunningActivity::class, 'session_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function isPlanned(): bool
    {
        return $this->status === 'planned';
    }

    /** Ligne courte pour le coach et le calendrier : « 45 min · 8 km · 5:30 /km · Z2 ». */
    public function targets(): string
    {
        return collect([
            $this->duration_minutes ? $this->duration_minutes.' min' : null,
            $this->distance_km ? rtrim(rtrim(number_format($this->distance_km, 1, ',', ''), '0'), ',').' km' : null,
            $this->paceLabel() ?? $this->target_pace,
            $this->hrLabel() ?? $this->target_hr,
        ])->filter()->implode(' · ');
    }

    /** « 5:30-5:45 /km » à partir des allures chiffrées. */
    public function paceLabel(): ?string
    {
        $paces = collect([$this->pace_from, $this->pace_to])->filter()->unique()->map(fn (int $seconds) => Duration::format($seconds));

        return $paces->isEmpty() ? null : $paces->implode('-').' /km';
    }

    /** « FC 135-150 » à partir des fréquences chiffrées. */
    public function hrLabel(): ?string
    {
        $rates = collect([$this->hr_from, $this->hr_to])->filter()->unique();

        return $rates->isEmpty() ? null : 'FC '.$rates->implode('-');
    }

    protected static function newFactory(): RunningSessionFactory
    {
        return RunningSessionFactory::new();
    }
}
