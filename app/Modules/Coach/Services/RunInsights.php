<?php

namespace App\Modules\Coach\Services;

use App\Modules\Coach\Models\DailyWellness;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Services\Strava\ActivityAnalysis;

/**
 * Lecture d'une course dans son contexte : comparaison avec les courses passées au même cardio, et état du jour
 * (récupération mesurée par la montre, cycle menstruel). Calculé à l'affichage, car cela dépend des autres données de l'athlète.
 */
class RunInsights
{
    /** Écart de FC moyenne (bpm) pour qu'une course passée soit comparable. */
    private const HR_TOLERANCE = 4;

    /** Fenêtre des courses de comparaison, en semaines avant la course. */
    private const COMPARISON_FROM_WEEKS = 10;

    private const COMPARISON_TO_WEEKS = 3;

    private const COMPARISON_MIN_RUNS = 2;

    /** Écart (s/km) sous lequel l'allure est jugée stable. */
    private const STABLE_PACE = 4;

    /** Hausse de la FC de repos (bpm) qui signale une fatigue possible. */
    private const RESTING_HR_ALERT = 5;

    private const HRV_STATUSES = ['BALANCED' => 'équilibrée', 'UNBALANCED' => 'déséquilibrée', 'LOW' => 'basse', 'POOR' => 'faible'];

    public function __construct(private CycleTracker $cycle) {}

    /**
     * @return array{comparison?: array{avg_hr: int, pace: int, previous_pace: int, count: int}, day?: array<string, mixed>}
     */
    public function forActivity(RunningActivity $run): array
    {
        return array_filter(['comparison' => $this->comparison($run), 'day' => $this->day($run)]);
    }

    /**
     * Lecture complète d'une course pour le coach : records, analyse, fractions, contexte, tours.
     *
     * @return list<string>
     */
    public function coachReading(RunningActivity $run, bool $withSleep = true): array
    {
        $findings = [...ActivityAnalysis::describeRecords($run->new_records), ...ActivityAnalysis::describe($run->analysis ?? []), ...ActivityAnalysis::describeDynamics($run->dynamics)];

        return array_values(array_filter([
            ...array_map(fn (string $finding) => 'Analyse : '.$finding, $findings),
            ActivityAnalysis::describeReps($run->analysis ?? []),
            ...array_map(fn (string $line) => 'Contexte : '.$line, self::describe($this->forActivity($run), $withSleep)),
            ActivityAnalysis::describeLaps($run->laps),
        ]));
    }

    /**
     * Allure (corrigée du dénivelé) comparée à celle des courses d'il y a 3 à 10 semaines au même cardio moyen.
     * Pas pour le fractionné, dont le cardio moyen ne reflète pas l'effort.
     *
     * @return array{avg_hr: int, pace: int, previous_pace: int, count: int}|null
     */
    public function comparison(RunningActivity $run): ?array
    {
        if (! $run->avg_hr || $run->distance_m < 3000 || isset($run->analysis['intervals'])) {
            return null;
        }

        $similar = RunningActivity::where('user_id', $run->user_id)->whereKeyNot($run->id)
            ->whereBetween('started_at', [$run->started_at->copy()->subWeeks(self::COMPARISON_FROM_WEEKS), $run->started_at->copy()->subWeeks(self::COMPARISON_TO_WEEKS)])
            ->whereBetween('avg_hr', [$run->avg_hr - self::HR_TOLERANCE, $run->avg_hr + self::HR_TOLERANCE])
            ->where('distance_m', '>=', 3000)
            ->get(['id', 'distance_m', 'moving_seconds', 'analysis'])
            ->reject(fn (RunningActivity $other) => isset($other->analysis['intervals']));

        if ($similar->count() < self::COMPARISON_MIN_RUNS) {
            return null;
        }

        return [
            'avg_hr' => $run->avg_hr,
            'pace' => self::flatPace($run),
            'previous_pace' => (int) round($similar->avg(fn (RunningActivity $other) => self::flatPace($other))),
            'count' => $similar->count(),
        ];
    }

    /**
     * Récupération du jour de la course (nuit précédente comprise) et phase du cycle si elle est suivie.
     *
     * @return array<string, mixed>|null
     */
    public function day(RunningActivity $run): ?array
    {
        $date = $run->started_at->copy()->startOfDay();
        $wellness = DailyWellness::where('user_id', $run->user_id)->whereDate('day', $date)->first();
        $usualRestingHr = $wellness?->resting_hr
            ? DailyWellness::where('user_id', $run->user_id)->where('day', '<', $date)->where('day', '>=', $date->copy()->subDays(30))->avg('resting_hr')
            : null;
        $cycle = $this->cycle->status($run->user, $date);

        return array_filter([
            'sleep' => $wellness?->sleepLabel(),
            'sleep_score' => $wellness?->sleep_score,
            'hrv' => $wellness?->hrv_last_night,
            'hrv_usual' => $wellness?->hrv_weekly_avg,
            'hrv_status' => $wellness?->hrv_status ? (self::HRV_STATUSES[$wellness->hrv_status] ?? mb_strtolower($wellness->hrv_status)) : null,
            'body_battery' => $wellness?->body_battery_max,
            'resting_hr' => $wellness?->resting_hr,
            'resting_usual' => $usualRestingHr ? (int) round($usualRestingHr) : null,
            'cycle' => $cycle ? ['day' => $cycle['day'], 'phase' => $cycle['phase']] : null,
        ], fn ($value) => $value !== null) ?: null;
    }

    /**
     * Phrases, pour la fiche et pour le coach.
     *
     * @param  array<string, mixed>  $insights
     * @return list<string>
     */
    public static function describe(array $insights, bool $withSleep = true): array
    {
        $lines = [];

        if ($comparison = $insights['comparison'] ?? null) {
            $difference = $comparison['pace'] - $comparison['previous_pace'];
            $lines[] = 'À FC comparable (~'.$comparison['avg_hr'].' bpm) : '.Duration::format($comparison['pace']).' /km contre '
                .Duration::format($comparison['previous_pace']).' /km sur '.$comparison['count'].' courses d\'il y a 3 à 10 semaines'
                .match (true) {
                    abs($difference) < self::STABLE_PACE => ' : forme stable.',
                    $difference < 0 => ' : '.abs($difference).' s/km plus rapide, l\'endurance progresse.',
                    default => ' : '.$difference.' s/km plus lent, fatigue du moment, chaleur ou baisse de forme (à recouper avec le ressenti).',
                };
        }

        $day = $insights['day'] ?? [];
        $hrvDetails = collect([isset($day['hrv_usual']) ? 'moyenne 7 j : '.$day['hrv_usual'] : null, $day['hrv_status'] ?? null])->filter();
        $watch = collect([
            $withSleep && isset($day['sleep']) ? 'nuit '.$day['sleep'].(isset($day['sleep_score']) ? ' (score '.$day['sleep_score'].')' : '') : null,
            isset($day['hrv']) ? 'VFC '.$day['hrv'].' ms'.($hrvDetails->isNotEmpty() ? ' ('.$hrvDetails->implode(', ').')' : '') : null,
            isset($day['body_battery']) ? 'Body Battery max '.$day['body_battery'] : null,
            isset($day['resting_hr']) ? 'FC de repos '.$day['resting_hr'].(isset($day['resting_usual']) ? ' (habituelle '.$day['resting_usual'].')' : '') : null,
        ])->filter();
        if ($watch->isNotEmpty()) {
            $high = isset($day['resting_hr'], $day['resting_usual']) && $day['resting_hr'] - $day['resting_usual'] >= self::RESTING_HR_ALERT;
            $lines[] = 'Contexte du jour (montre) : '.$watch->implode(', ').($high ? ' — FC de repos élevée, signe possible de fatigue.' : '.');
        }
        if (isset($day['cycle'])) {
            $lines[] = 'Cycle : jour '.$day['cycle']['day'].', '.$day['cycle']['phase'].'.';
        }

        return $lines;
    }

    /** Allure corrigée du dénivelé si elle est connue, sinon allure moyenne (s/km). */
    private static function flatPace(RunningActivity $run): int
    {
        return $run->analysis['grade_adjusted']['gap_pace'] ?? (int) round($run->moving_seconds / ($run->distance_m / 1000));
    }
}
