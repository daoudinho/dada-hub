<?php

namespace App\Modules\Coach\Services;

use App\Models\User;
use App\Modules\Coach\Models\DailyWellness;
use App\Modules\Coach\Models\Feedback;
use App\Modules\Coach\Models\Gear;
use App\Modules\Coach\Models\Injury;
use App\Modules\Coach\Models\RunningActivity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Garde-fous et chiffres calculés (et non laissés à l'IA) : niveau de départ, charge, répartition 80/20, douleurs, fatigue.
 *
 * Charge d'une course = minutes × effort ressenti (méthode RPE de Foster ; effort 5 par défaut).
 * Ratio aigu/chronique = 7 derniers jours / moyenne hebdomadaire des 28 derniers jours, en charge et en kilomètres.
 */
class TrainingLoad
{
    /** Ratio à surveiller. */
    public const RATIO_WATCH = 1.3;

    /** Ratio au-delà duquel le risque de blessure augmente nettement. */
    public const RATIO_ALERT = 1.5;

    /** Part maximale du temps de course en intensité (cible 80/20). */
    public const HARD_SHARE_ALERT = 0.25;

    /** Douleur à partir de laquelle on alerte (sur 10). */
    public const PAIN_ALERT = 4;

    /** Fatigue (sur 5) qui, répétée, doit alléger la suite. */
    public const FATIGUE_ALERT = 4;

    /** Hausse de la FC de repos (bpm) au-dessus de la référence qui signale fatigue ou maladie. */
    public const RESTING_HR_ALERT = 5;

    /** Body Battery maximal de la journée en dessous duquel la récupération est insuffisante. */
    public const BODY_BATTERY_ALERT = 40;

    /** Nuit courte (secondes). */
    public const SHORT_SLEEP_SECONDS = 6 * 3600;

    /** Types de séance comptés comme faciles. */
    private const EASY_TYPES = ['easy', 'long', 'recovery'];

    /** @var array<int, array<string, mixed>> bilans déjà calculés (la fiche du coach en demande plusieurs) */
    private array $summaries = [];

    public function __construct(private ReferencePaces $referencePaces) {}

    /**
     * Kilomètres par semaine (lundi), de la plus ancienne à la semaine en cours.
     *
     * @return Collection<string, float> clé : date du lundi (Y-m-d)
     */
    public function weeklyDistance(User $user, int $weeks): Collection
    {
        return $this->weeks($user, $weeks)->map(fn (array $week) => $week['km']);
    }

    /**
     * Niveau de départ et charge, pour la fiche du coach.
     *
     * @return array{weeks: Collection<string, array{km: float, hours: float, runs: int}>, active_weeks: int, streak: int, longest: ?RunningActivity, load_ratio: ?float, volume_ratio: ?float, hard_share: ?float}
     */
    public function summary(User $user): array
    {
        return $this->summaries[$user->id] ??= $this->computeSummary($user);
    }

    /**
     * @return array<string, mixed>
     */
    private function computeSummary(User $user): array
    {
        $weeks = $this->weeks($user, 12);
        $recent = RunningActivity::ownedBy($user)->summary()->with('session')->where('started_at', '>=', today()->subDays(28))->get();

        $streak = 0;
        foreach ($weeks->reverse()->values() as $index => $week) {
            if ($week['runs'] > 0) {
                $streak++;
            } elseif ($index > 0) { // la semaine en cours peut être encore vide
                break;
            }
        }

        return [
            'weeks' => $weeks,
            'active_weeks' => $weeks->filter(fn (array $week) => $week['runs'] > 0)->count(),
            'streak' => $streak,
            'longest' => RunningActivity::ownedBy($user)->summary()->where('started_at', '>=', today()->subWeeks(12))->orderByDesc('distance_m')->first(),
            'load_ratio' => $this->ratio($recent, fn (RunningActivity $activity) => $activity->moving_seconds / 60 * ($activity->rpe ?: 5)),
            'volume_ratio' => $this->ratio($recent, fn (RunningActivity $activity) => $activity->distance_m),
            'hard_share' => $this->hardShare($user, $recent),
        ];
    }

    /**
     * Alertes à transmettre au coach, phrases prêtes à lire.
     *
     * @return list<string>
     */
    public function signals(User $user): array
    {
        $signals = [];
        $summary = $this->summary($user);

        $ratios = sprintf('charge %s, volume %s', $this->formatRatio($summary['load_ratio']), $this->formatRatio($summary['volume_ratio']));
        $highest = max($summary['load_ratio'] ?? 0, $summary['volume_ratio'] ?? 0);
        if ($highest > self::RATIO_ALERT) {
            $signals[] = sprintf('Ratio de charge aigu/chronique au-dessus de %.1f (%s) : risque de blessure nettement accru, alléger les prochains jours.', self::RATIO_ALERT, $ratios);
        } elseif ($highest > self::RATIO_WATCH) {
            $signals[] = sprintf('Ratio de charge aigu/chronique au-dessus de %.1f (%s) : vigilance, ne pas augmenter davantage cette semaine.', self::RATIO_WATCH, $ratios);
        }

        if ($summary['hard_share'] !== null && $summary['hard_share'] > self::HARD_SHARE_ALERT) {
            $signals[] = sprintf('Intensité : %d %% du temps de course en séances dures sur 28 jours (cible ~20 %%) : remettre de l\'endurance fondamentale.', round($summary['hard_share'] * 100));
        }

        Injury::ownedBy($user)->where('current', true)->get()->each(function (Injury $injury) use (&$signals) {
            $signals[] = 'Blessure ou gêne en cours : '.$injury->label().'. Adapter les séances, pas d\'intensité qui la sollicite.';
        });

        $feedbacks = Feedback::ownedBy($user)->where('created_at', '>=', today()->subDays(14))->latest()->get();
        $feedbacks->filter(fn (Feedback $feedback) => $feedback->maxPain() >= self::PAIN_ALERT)
            ->each(function (Feedback $feedback) use (&$signals) {
                $pains = collect($feedback->pains)->filter(fn (array $pain) => ($pain['intensity'] ?? 0) >= self::PAIN_ALERT)
                    ->map(fn (array $pain) => ($pain['zone'] ?? '?').' '.($pain['intensity'] ?? '?').'/10')->implode(', ');
                $signals[] = 'Douleur signalée le '.$feedback->created_at->format('d/m').' ('.$pains.') : pas d\'intensité tant qu\'elle persiste ; conseiller un avis médical si elle dure ou augmente.';
            });

        Gear::ownedBy($user)->where('retired', false)->get()
            ->filter(fn (Gear $gear) => $gear->isWorn() && ($gear->primary || RunningActivity::ownedBy($user)->where('gear_id', $gear->strava_id)->where('started_at', '>=', today()->subDays(28))->exists()))
            ->each(function (Gear $gear) use (&$signals) {
                $signals[] = 'Chaussures « '.$gear->name.' » à '.$gear->km().' km : amorti probablement usé, suggérer de les remplacer (en alternance progressive).';
            });

        array_push($signals, ...$this->recoverySignals($user));

        $tired = $feedbacks->filter(fn (Feedback $feedback) => $feedback->created_at->isAfter(now()->subDays(7)) && $feedback->fatigue >= self::FATIGUE_ALERT);
        if ($tired->count() >= 2) {
            $signals[] = 'Arrivé fatigué aux séances (forme 4 ou 5 sur 5) '.$tired->count().' fois cette semaine : fatigue qui s\'accumule, alléger ou ajouter du repos.';
        }

        return $signals;
    }

    /**
     * Ratio 7 jours / moyenne hebdomadaire sur 28 jours, null avec moins de 3 semaines d'historique.
     *
     * @param  Collection<int, RunningActivity>  $activities  courses des 28 derniers jours
     * @param  callable(RunningActivity): float  $measure
     */
    public function ratio(Collection $activities, callable $measure): ?float
    {
        $oldest = $activities->min('started_at');
        if (! $oldest || Carbon::parse($oldest)->isAfter(today()->subDays(21))) {
            return null;
        }

        $acute = $activities->filter(fn (RunningActivity $activity) => $activity->started_at->isAfter(now()->subDays(7)))->sum($measure);
        $chronic = $activities->sum($measure) / 4;

        return $chronic > 0 ? round($acute / $chronic, 2) : null;
    }

    /**
     * Part du temps de course en intensité sur 28 jours, null avec moins de 4 courses classées.
     *
     * Avec les données cardiaques de Strava : le temps passé au-dessus de la zone 2. Sinon, la course entière
     * est dure ou facile selon le type de sa séance, à défaut l'effort ressenti (6 et plus), à défaut la FC moyenne.
     *
     * @param  Collection<int, RunningActivity>  $activities
     */
    private function hardShare(User $user, Collection $activities): ?float
    {
        $easyCeiling = $this->referencePaces->zonesFor($user)['Z2 endurance']['to'] ?? null;

        $classified = $activities->map(function (RunningActivity $activity) use ($easyCeiling) {
            $zones = $activity->analysis['zone_seconds'] ?? null;
            if ($zones && array_sum($zones) > 0) {
                $values = array_values($zones); // Z1 … Z5

                return ['minutes' => array_sum($values) / 60, 'hard_minutes' => array_sum(array_slice($values, 2)) / 60];
            }

            $hard = match (true) {
                $activity->session !== null => ! in_array($activity->session->type, self::EASY_TYPES, true),
                $activity->rpe !== null => $activity->rpe >= 6,
                $easyCeiling && $activity->avg_hr => $activity->avg_hr > $easyCeiling,
                default => null,
            };

            return $hard === null ? null : ['minutes' => $activity->moving_seconds / 60, 'hard_minutes' => $hard ? $activity->moving_seconds / 60 : 0];
        })->filter();

        if ($classified->count() < 4) {
            return null;
        }

        return round($classified->sum('hard_minutes') / max(1, $classified->sum('minutes')), 2);
    }

    /**
     * @return Collection<string, array{km: float, hours: float, runs: int}>
     */
    private function weeks(User $user, int $count): Collection
    {
        $start = today()->startOfWeek()->subWeeks($count - 1);
        $activities = RunningActivity::ownedBy($user)->where('started_at', '>=', $start)->get(['started_at', 'distance_m', 'moving_seconds']);

        return collect(range(0, $count - 1))->mapWithKeys(function (int $offset) use ($start, $activities) {
            $monday = $start->copy()->addWeeks($offset);
            $runs = $activities->filter(fn (RunningActivity $activity) => $activity->started_at->between($monday, $monday->copy()->endOfWeek()));

            return [$monday->toDateString() => [
                'km' => round($runs->sum('distance_m') / 1000, 1),
                'hours' => round($runs->sum('moving_seconds') / 3600, 1),
                'runs' => $runs->count(),
            ]];
        });
    }

    /**
     * Alertes de récupération d'après la montre (3 derniers jours) : FC de repos au-dessus de la référence,
     * Body Battery qui ne remonte plus, HRV basse, nuits courtes.
     *
     * @return list<string>
     */
    public function recoverySignals(User $user): array
    {
        $days = DailyWellness::ownedBy($user)->where('day', '>=', today()->subDays(37))->get();
        $recent = $days->filter(fn (DailyWellness $day) => $day->day->isAfter(today()->subDays(3)));
        $baseline = $days->filter(fn (DailyWellness $day) => $day->day->lte(today()->subDays(7)))->whereNotNull('resting_hr');
        $signals = [];

        $recentHr = $recent->whereNotNull('resting_hr');
        if ($recentHr->count() >= 2 && $baseline->count() >= 10 && $recentHr->avg('resting_hr') >= $baseline->avg('resting_hr') + self::RESTING_HR_ALERT) {
            $signals[] = sprintf('FC de repos à %d en moyenne ces derniers jours, contre %d d\'habitude : fatigue, début de maladie ou surcharge probable, alléger.',
                round($recentHr->avg('resting_hr')), round($baseline->avg('resting_hr')));
        }

        if ($recent->filter(fn (DailyWellness $day) => $day->body_battery_max !== null && $day->body_battery_max < self::BODY_BATTERY_ALERT)->count() >= 2) {
            $signals[] = 'Body Battery qui ne remonte pas au-dessus de '.self::BODY_BATTERY_ALERT.' depuis plusieurs jours : récupération insuffisante.';
        }

        $lastHrv = $days->whereNotNull('hrv_status')->sortBy('day')->last();
        if ($lastHrv && in_array($lastHrv->hrv_status, ['LOW', 'UNBALANCED', 'POOR'], true) && $lastHrv->day->isAfter(today()->subDays(3))) {
            $signals[] = 'Variabilité cardiaque nocturne « '.$lastHrv->hrv_status.' » le '.$lastHrv->day->format('d/m').' : organisme sous tension, privilégier le facile.';
        }

        if ($recent->filter(fn (DailyWellness $day) => $day->sleep_seconds && $day->sleep_seconds < self::SHORT_SLEEP_SECONDS)->count() >= 2) {
            $signals[] = 'Nuits de moins de 6 h à répétition : pas d\'intensité tant que le sommeil ne revient pas.';
        }

        return $signals;
    }

    public function formatRatio(?float $ratio): string
    {
        return $ratio === null ? 'non calculable' : number_format($ratio, 2, ',', '');
    }
}
