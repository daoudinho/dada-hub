<?php

namespace App\Modules\Coach\Services\Strava;

use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Models\RunningSession;
use App\Modules\Coach\Services\Duration;
use App\Modules\Coach\Services\RunningRecords;

/**
 * Analyse d'une course à partir des données Strava (flux seconde par seconde et tours) :
 * temps par zone cardiaque, dérive cardiaque, allure en endurance, écart à la cible, régularité des fractions.
 */
class ActivityAnalysis
{
    /** Durée minimale (secondes) pour que la dérive cardiaque ait un sens. */
    private const DRIFT_MIN_SECONDS = 1800;

    /** Dérive au-delà de laquelle l'endurance « décroche » (en %). */
    public const DRIFT_ALERT = 5.0;

    /** Tolérance autour de l'allure cible (secondes par km). */
    private const PACE_TOLERANCE = 5;

    /** Contraste minimal entre allure rapide et lente pour repérer des fractions : séance programmée, course libre. */
    private const REPS_CONTRAST_SESSION = 1.2;

    private const REPS_CONTRAST_FREE = 1.4;

    /** Durée minimale d'une fraction (secondes). */
    private const REP_MIN_SECONDS = 40;

    /** Longueur des tronçons pour la pente (mètres) : assez longs pour lisser le bruit de l'altitude. */
    private const SEGMENT_METERS = 100;

    /** Pente (fraction) sous laquelle un tronçon est considéré comme plat. */
    private const FLAT_GRADE = 0.02;

    /** Écart minimal (s/km) entre allure réelle et allure corrigée pour la mentionner. */
    private const GRADE_ADJUSTED_MIN_GAP = 5;

    /** Kilomètre « coup de mou » : 8 % plus lent que la médiane, à effort égal. */
    private const WEAK_KM_RATIO = 1.08;

    /** Écart (s/km) sous lequel les deux moitiés sont jugées régulières. */
    public const SPLIT_STEADY = 4;

    /** Analyse d'une course enregistrée (courbes et tours déjà sur la course), null si rien à en dire. */
    public function analyzeActivity(RunningActivity $activity, ?RunningSession $session, array $zones): ?array
    {
        return $this->analyze($activity->streams ?? [], $activity->laps ?? [], $activity->distance_m, $activity->moving_seconds, $session, $zones) ?: null;
    }

    /**
     * @param  array<string, list<float|int|bool>>  $streams  flux par type (time, heartrate, velocity_smooth, moving…)
     * @param  list<array<string, mixed>>  $laps  tours compactés (distance_m, moving_s, avg_speed, avg_hr)
     * @param  array<string, array{from: int, to: int}>  $zones  zones cardiaques de l'athlète
     * @return array<string, mixed>
     */
    public function analyze(array $streams, array $laps, int $distanceM, int $movingSeconds, ?RunningSession $session, array $zones): array
    {
        $points = $this->points($streams);
        $analysis = [];

        if ($zones && $points && isset($points[0]['hr'])) {
            $analysis['zone_seconds'] = $this->zoneSeconds($points, $zones);
            if ($pace = $this->paceInZone($points, $zones['Z2 endurance'] ?? null)) {
                $analysis['z2_pace'] = $pace;
            }
        }

        if (($drift = $this->drift($points)) !== null) {
            $analysis['drift_percent'] = $drift;
        }

        if ($topSpeed = RunningRecords::topSpeed($streams)) {
            $analysis['top_speed'] = $topSpeed;
        }

        // Fractions : repérées dans le flux de vitesse quand il existe (les tours Garmin sont des tours au km),
        // sinon dans les tours de la montre (Strava).
        if (count(array_filter($points, fn (array $point) => isset($point['speed']))) >= 300) {
            $reps = $this->repsFromStreams($points, $session);
            $paces = array_column($reps, 'pace');
        } else {
            $reps = [];
            $paces = array_map(fn (array $lap) => 1000 / $lap['avg_speed'], $this->workLaps($laps));
        }
        if (count($paces) >= 3) {
            $mean = array_sum($paces) / count($paces);
            $analysis['intervals'] = [
                'count' => count($paces),
                'avg_pace' => (int) round($mean),
                'variation_percent' => round(sqrt(array_sum(array_map(fn (float|int $pace) => ($pace - $mean) ** 2, $paces)) / count($paces)) / $mean * 100, 1),
                'first_pace' => (int) round($paces[0]),
                'last_pace' => (int) round(end($paces)),
            ] + ($reps ? ['reps' => $reps] : []);
        }

        if ($session && ($session->pace_from || $session->pace_to)) {
            $intervalTarget = isset($analysis['intervals']) && in_array($session->type, ['intervals', 'tempo'], true);
            $actual = $intervalTarget
                ? $analysis['intervals']['avg_pace']
                : ($distanceM > 0 ? (int) round($movingSeconds / ($distanceM / 1000)) : null);
            if ($actual) {
                $fast = min(array_filter([$session->pace_from, $session->pace_to]));
                $slow = max(array_filter([$session->pace_from, $session->pace_to]));
                $analysis['target'] = ['actual_pace' => $actual, 'gap' => $this->paceGap($actual, $fast, $slow)];

                if ($intervalTarget && isset($analysis['intervals']['reps'])) {
                    foreach ($analysis['intervals']['reps'] as $i => $rep) {
                        $analysis['intervals']['reps'][$i]['gap'] = $this->paceGap($rep['pace'], $fast, $slow);
                    }
                    $gaps = array_column($analysis['intervals']['reps'], 'gap');
                    $analysis['intervals']['in_target'] = count(array_filter($gaps, fn (int $gap) => $gap === 0));
                    $analysis['intervals']['too_fast'] = count(array_filter($gaps, fn (int $gap) => $gap < 0));
                    $analysis['intervals']['too_slow'] = count(array_filter($gaps, fn (int $gap) => $gap > 0));
                }
            }
        }

        $segments = $this->segments($streams);
        if ($segments) {
            $meters = array_sum(array_column($segments, 'meters'));
            $pace = $meters > 0 ? (int) round(array_sum(array_column($segments, 'seconds')) / $meters * 1000) : null;
            $gradeAdjusted = $this->gradeAdjustedPace($segments);
            if ($pace && $gradeAdjusted && abs($gradeAdjusted - $pace) >= self::GRADE_ADJUSTED_MIN_GAP) {
                $analysis['grade_adjusted'] = ['pace' => $pace, 'gap_pace' => $gradeAdjusted, 'climb' => self::elevation($segments, 1), 'descent' => self::elevation($segments, -1)];
            }

            if (! isset($analysis['intervals'])) {
                if ($split = $this->split($segments)) {
                    $analysis['split'] = $split;
                }
                if ($weak = $this->weakKilometre($this->kilometres($segments))) {
                    $analysis['weak_km'] = $weak;
                }
            }
        }

        return $analysis;
    }

    /** Écart à une fourchette d'allure (s/km) : négatif plus rapide, positif plus lent, 0 dans la cible (avec tolérance). */
    private function paceGap(int $actual, int $fast, int $slow): int
    {
        return match (true) {
            $actual < $fast - self::PACE_TOLERANCE => $actual - $fast,
            $actual > $slow + self::PACE_TOLERANCE => $actual - $slow,
            default => 0,
        };
    }

    /**
     * Portions rapides (fractions) repérées dans le flux de vitesse lissé : seulement pour une séance de fractionné, de seuil ou de test,
     * ou une course libre aux variations d'allure marquées (pas pour un footing vallonné).
     *
     * @param  list<array{dt: int, hr?: float, speed?: float}>  $points
     * @return list<array{seconds: int, distance_m: int, pace: int, avg_hr: ?int}>
     */
    private function repsFromStreams(array $points, ?RunningSession $session): array
    {
        if ($session && ! in_array($session->type, ['intervals', 'tempo', 'test'], true)) {
            return [];
        }
        $points = array_values(array_filter($points, fn (array $point) => isset($point['speed'])));
        $count = count($points);

        // Vitesse moyenne sur ± 7 secondes autour de chaque point.
        $elapsed = [];
        $total = 0;
        foreach ($points as $point) {
            $elapsed[] = $total += $point['dt'];
        }
        $smoothed = [];
        $from = $to = 0;
        $sum = $sumTime = 0.0;
        for ($i = 0; $i < $count; $i++) {
            while ($to < $count && $elapsed[$to] <= $elapsed[$i] + 7) {
                $sum += $points[$to]['speed'] * $points[$to]['dt'];
                $sumTime += $points[$to]['dt'];
                $to++;
            }
            while ($elapsed[$from] < $elapsed[$i] - 7) {
                $sum -= $points[$from]['speed'] * $points[$from]['dt'];
                $sumTime -= $points[$from]['dt'];
                $from++;
            }
            $smoothed[] = $sumTime > 0 ? $sum / $sumTime : 0.0;
        }

        $sorted = $smoothed;
        sort($sorted);
        $slow = $sorted[(int) floor($count * 0.2)];
        $fast = $sorted[(int) floor($count * 0.9)];
        if ($slow <= 0 || $fast / $slow < ($session ? self::REPS_CONTRAST_SESSION : self::REPS_CONTRAST_FREE)) {
            return [];
        }
        $threshold = ($slow + $fast) / 2;

        // Blocs rapides, en fusionnant les creux de moins de 10 secondes.
        // Un creux n'est rattaché au bloc que si l'effort reprend ensuite.
        $blocks = [];
        $current = $pending = [];
        foreach ($points as $i => $point) {
            if ($smoothed[$i] >= $threshold) {
                array_push($current, ...$pending, ...[$point]);
                $pending = [];
            } elseif ($current) {
                $pending[] = $point;
                if (array_sum(array_column($pending, 'dt')) >= 10) {
                    $blocks[] = $current;
                    $current = $pending = [];
                }
            }
        }
        if ($current) {
            $blocks[] = $current;
        }

        $reps = [];
        foreach ($blocks as $blockPoints) {
            $withHr = array_filter($blockPoints, fn (array $point) => isset($point['hr']));
            $block = [
                'seconds' => array_sum(array_column($blockPoints, 'dt')),
                'meters' => array_sum(array_map(fn (array $point) => $point['speed'] * $point['dt'], $blockPoints)),
                'hr' => array_sum(array_map(fn (array $point) => $point['hr'] * $point['dt'], $withHr)),
                'hr_time' => array_sum(array_column($withHr, 'dt')),
            ];
            if ($block['seconds'] >= self::REP_MIN_SECONDS && $block['meters'] >= 100) {
                $reps[] = [
                    'seconds' => $block['seconds'],
                    'distance_m' => (int) round($block['meters']),
                    'pace' => (int) round($block['seconds'] / $block['meters'] * 1000),
                    'avg_hr' => $block['hr_time'] > 0 ? (int) round($block['hr'] / $block['hr_time']) : null,
                ];
            }
        }

        return count($reps) >= 3 ? $reps : [];
    }

    /**
     * Tronçons de 100 m : temps en mouvement, distance et dénivelé (à partir des flux distance et altitude).
     *
     * @param  array<string, list<float|int|bool|null>>  $streams
     * @return list<array{from: float, meters: float, seconds: int, rise: float}>
     */
    private function segments(array $streams): array
    {
        $time = $streams['time'] ?? [];
        $distance = $streams['distance'] ?? [];
        $altitude = $streams['altitude'] ?? [];
        if (count($time) < 2 || count($distance) !== count($time)) {
            return [];
        }

        $segments = [];
        $current = ['from' => (float) ($distance[0] ?? 0), 'alt' => $altitude[0] ?? null, 'seconds' => 0];
        for ($i = 1; $i < count($time); $i++) {
            if ($distance[$i] === null) {
                continue;
            }
            $dt = (int) $time[$i] - (int) $time[$i - 1];
            if ($dt > 0 && $dt <= RunningRecords::PAUSE_SECONDS && ($streams['moving'][$i] ?? true)) {
                $current['seconds'] += $dt;
            }
            $meters = (float) $distance[$i] - $current['from'];
            if ($meters >= self::SEGMENT_METERS) {
                $alt = $altitude[$i] ?? null;
                if ($current['seconds'] > 0) {
                    $segments[] = [
                        'from' => $current['from'],
                        'meters' => $meters,
                        'seconds' => $current['seconds'],
                        'rise' => $alt !== null && $current['alt'] !== null ? (float) $alt - (float) $current['alt'] : 0.0,
                    ];
                }
                $current = ['from' => (float) $distance[$i], 'alt' => $alt, 'seconds' => 0];
            }
        }

        return $segments;
    }

    /**
     * Coût énergétique de la course selon la pente (Minetti, 2002), en J/kg/m ; 3,6 sur le plat.
     */
    private static function cost(float $grade): float
    {
        // Sous 2 %, on considère le terrain plat : la dérive de l'altimètre au fil de la course et le bruit GPS
        // ressembleraient sinon à une longue pente douce.
        if (abs($grade) < self::FLAT_GRADE) {
            return 3.6;
        }
        $g = max(-0.25, min(0.25, $grade));

        return 155.4 * $g ** 5 - 30.4 * $g ** 4 - 43.3 * $g ** 3 + 46.3 * $g ** 2 + 19.5 * $g + 3.6;
    }

    /**
     * Allure corrigée du dénivelé (s/km) : l'allure équivalente sur le plat pour le même effort.
     *
     * @param  list<array{from: float, meters: float, seconds: int, rise: float}>  $segments
     */
    private function gradeAdjustedPace(array $segments): ?int
    {
        $flatMeters = array_sum(array_map(fn (array $segment) => $segment['meters'] * self::cost($segment['rise'] / $segment['meters']) / self::cost(0), $segments));
        $seconds = array_sum(array_column($segments, 'seconds'));

        return $flatMeters > 0 ? (int) round($seconds / $flatMeters * 1000) : null;
    }

    /**
     * Tronçons regroupés par kilomètre.
     *
     * @param  list<array{from: float, meters: float, seconds: int, rise: float}>  $segments
     * @return array<int, array{meters: float, seconds: int, pace: int, gap_pace: int, climb: int}>
     */
    private function kilometres(array $segments): array
    {
        $groups = [];
        foreach ($segments as $segment) {
            $groups[(int) floor($segment['from'] / 1000)][] = $segment;
        }

        return array_map(function (array $group) {
            $meters = array_sum(array_column($group, 'meters'));
            $seconds = array_sum(array_column($group, 'seconds'));

            return [
                'meters' => $meters,
                'seconds' => $seconds,
                'pace' => (int) round($seconds / $meters * 1000),
                'gap_pace' => $this->gradeAdjustedPace($group) ?? (int) round($seconds / $meters * 1000),
                'climb' => self::elevation($group, 1),
            ];
        }, $groups);
    }

    /**
     * Allure de la 1re et de la 2e moitié (en distance), corrigée du dénivelé. Au moins 3 km.
     *
     * @param  list<array{from: float, meters: float, seconds: int, rise: float}>  $segments
     * @return array{first_pace: int, second_pace: int}|null
     */
    private function split(array $segments): ?array
    {
        $total = array_sum(array_column($segments, 'meters'));
        if ($total < 3000) {
            return null;
        }

        $halves = [[], []];
        $covered = 0.0;
        foreach ($segments as $segment) {
            $halves[$covered + $segment['meters'] / 2 <= $total / 2 ? 0 : 1][] = $segment;
            $covered += $segment['meters'];
        }
        if (! $halves[0] || ! $halves[1]) {
            return null;
        }

        return ['first_pace' => $this->gradeAdjustedPace($halves[0]), 'second_pace' => $this->gradeAdjustedPace($halves[1])];
    }

    /**
     * Kilomètre nettement plus lent que les autres à effort égal (allure corrigée du dénivelé), hors 1er km (échauffement)
     * et dernier km incomplet. Au moins 4 km comparables.
     *
     * @param  array<int, array{meters: float, seconds: int, pace: int, gap_pace: int, climb: int}>  $kilometres
     * @return array{km: int, pace: int, usual_pace: int, gap_pace: int, usual_gap_pace: int, climb: int}|null
     */
    private function weakKilometre(array $kilometres): ?array
    {
        $full = array_filter($kilometres, fn (array $km, int $index) => $index > 0 && $km['meters'] >= 950, ARRAY_FILTER_USE_BOTH);
        if (count($full) < 4) {
            return null;
        }

        $median = self::median(array_column($full, 'gap_pace'));
        $realMedian = self::median(array_column($full, 'pace'));
        $index = collect($full)->sortByDesc('gap_pace')->keys()->first();
        $worst = $full[$index];
        // Une descente prise en roue libre n'est pas un coup de mou : le km doit aussi être plus lent en allure réelle.
        if ($worst['gap_pace'] < $median * self::WEAK_KM_RATIO || $worst['pace'] <= $realMedian) {
            return null;
        }

        return ['km' => $index + 1, 'pace' => $worst['pace'], 'usual_pace' => $realMedian, 'gap_pace' => $worst['gap_pace'], 'usual_gap_pace' => $median, 'climb' => $worst['climb']];
    }

    /**
     * Phrases pour le coach.
     *
     * @param  array<string, mixed>  $analysis
     * @return list<string>
     */
    public static function describe(array $analysis): array
    {
        $lines = [];

        if (isset($analysis['target'])) {
            $gap = $analysis['target']['gap'];
            $lines[] = 'Allure réelle '.Duration::format($analysis['target']['actual_pace']).' /km : '
                .($gap === 0 ? 'dans la cible' : ($gap < 0 ? abs($gap).' s/km plus rapide que la cible' : $gap.' s/km plus lente que la cible')).'.';
        }
        if (isset($analysis['intervals'])) {
            $intervals = $analysis['intervals'];
            $lines[] = $intervals['count'].' fractions repérées, moyenne '.Duration::format($intervals['avg_pace']).' /km, variation '.$intervals['variation_percent'].' %'
                .' (première '.Duration::format($intervals['first_pace']).', dernière '.Duration::format($intervals['last_pace']).').';
            if (isset($intervals['in_target'])) {
                $misses = collect([
                    $intervals['too_fast'] ? $intervals['too_fast'].' trop rapide'.($intervals['too_fast'] > 1 ? 's' : '') : null,
                    $intervals['too_slow'] ? $intervals['too_slow'].' trop lente'.($intervals['too_slow'] > 1 ? 's' : '') : null,
                ])->filter();
                $lines[] = 'Fractions dans la cible : '.$intervals['in_target'].' sur '.$intervals['count']
                    .($misses->isNotEmpty() ? ' ('.$misses->implode(', ').')' : '').'.';
            }
        }
        if (isset($analysis['grade_adjusted'])) {
            $graded = $analysis['grade_adjusted'];
            $effect = $graded['pace'] - $graded['gap_pace'];
            $lines[] = 'Dénivelé +'.$graded['climb'].' m / −'.($graded['descent'] ?? 0).' m : tu as couru à '.Duration::format($graded['pace']).' /km, '
                .'ce qui équivaut à '.Duration::format($graded['gap_pace']).' /km sur du plat pour le même effort (« équivalent plat ») : '
                .($effect > 0 ? 'les montées t\'ont ralenti d\'environ '.$effect.' s/km.' : 'les descentes t\'ont fait gagner environ '.abs($effect).' s/km.');
        }
        if (isset($analysis['split'])) {
            $difference = $analysis['split']['second_pace'] - $analysis['split']['first_pace'];
            $lines[] = 'Moitiés de course'.(isset($analysis['grade_adjusted']) ? ' (en équivalent plat)' : '').' : '
                .Duration::format($analysis['split']['first_pace']).' puis '.Duration::format($analysis['split']['second_pace']).' /km'
                .match (true) {
                    abs($difference) < self::SPLIT_STEADY => ' : allure régulière, bonne gestion.',
                    $difference < 0 => ' : 2e moitié plus rapide de '.abs($difference).' s/km (split négatif), course bien gérée.',
                    default => ' : 2e moitié plus lente de '.$difference.' s/km, départ un peu rapide ou fatigue en fin de course.',
                };
        }
        if (isset($analysis['weak_km'])) {
            $weak = $analysis['weak_km'];
            $graded = $weak['gap_pace'] !== $weak['pace'] || $weak['usual_gap_pace'] !== $weak['usual_pace'];
            $lines[] = 'Coup de mou au km '.$weak['km'].' : '.Duration::format($weak['pace']).' /km contre '.Duration::format($weak['usual_pace']).' sur tes autres kilomètres.'
                .($graded ? ($weak['climb'] >= 5 ? ' La montée de ce km (D+ '.$weak['climb'].' m) n\'explique pas tout' : ' Ce n\'est pas dû à la pente')
                    .' : en équivalent plat, '.Duration::format($weak['gap_pace']).' contre '.Duration::format($weak['usual_gap_pace']).'.' : '');
        }
        if (isset($analysis['drift_percent'])) {
            $drift = $analysis['drift_percent'];
            $lines[] = 'Dérive cardiaque '.number_format($drift, 1, ',', '').' % entre la 1re et la 2e moitié'
                .($drift > self::DRIFT_ALERT ? ' : au-delà de 5 %, signe de fatigue, de chaleur ou d\'une endurance encore fragile à cette allure.' : ' : endurance solide à cette allure.');
        }
        if (isset($analysis['zone_seconds'])) {
            $total = max(1, array_sum($analysis['zone_seconds']));
            $lines[] = 'Temps par zone : '.collect($analysis['zone_seconds'])
                ->map(fn (int $seconds, string $zone) => strtok($zone, ' ').' '.round($seconds / $total * 100).' %')->implode(', ').'.';
        }
        if (isset($analysis['z2_pace'])) {
            $lines[] = 'Allure moyenne en zone 2 : '.Duration::format($analysis['z2_pace']).' /km.';
        }

        return $lines;
    }

    /**
     * Records battus par la course (notés à l'import).
     *
     * @param  list<array{key: string, label: string, value: string, previous: string}>|null  $records
     * @return list<string>
     */
    public static function describeRecords(?array $records): array
    {
        return array_map(fn (array $record) => '🏆 Nouveau record — '.$record['label'].' : '.$record['value'].' (ancien record '.$record['previous'].').', $records ?? []);
    }

    /**
     * Tours, pour le coach.
     *
     * @param  list<array<string, mixed>>|null  $laps
     */
    public static function describeLaps(?array $laps): ?string
    {
        if (! $laps) {
            return null;
        }

        return 'Tours (distance · temps · allure · FC moy.) : '.collect($laps)->map(fn (array $lap, int $index) => '#'.($index + 1).' '.$lap['distance_m'].' m · '
            .Duration::format($lap['moving_s']).' · '.(Duration::fromSpeed($lap['avg_speed']) ?? '?').($lap['avg_hr'] ? ' · '.$lap['avg_hr'] : ''))->implode(' | ');
    }

    /**
     * Détail fraction par fraction, pour le coach.
     *
     * @param  array<string, mixed>  $analysis
     */
    public static function describeReps(array $analysis): ?string
    {
        $reps = $analysis['intervals']['reps'] ?? [];
        if (! $reps) {
            return null;
        }

        return 'Fractions (durée · distance · allure · FC moy.) : '.collect($reps)->map(fn (array $rep, int $index) => '#'.($index + 1).' '
            .Duration::format($rep['seconds']).' · '.$rep['distance_m'].' m · '.Duration::format($rep['pace']).' /km'
            .($rep['avg_hr'] ? ' · '.$rep['avg_hr'] : '')
            .(isset($rep['gap']) ? ($rep['gap'] === 0 ? ' (cible)' : ' ('.($rep['gap'] > 0 ? '+' : '').$rep['gap'].' s/km)') : ''))->implode(' | ');
    }

    /**
     * Explication des métriques présentes dans l'analyse d'une course (bloc « i » de la fiche).
     *
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>|null  $dynamics
     * @param  array<string, array{from: int, to: int}>  $zones  zones cardiaques de l'athlète (ReferencePaces)
     * @param  array<string, mixed>  $insights  comparaison et contexte du jour (RunInsights)
     * @return array<string, string> terme => explication
     */
    public static function glossary(array $analysis, ?array $dynamics, array $zones = [], array $insights = []): array
    {
        $day = $insights['day'] ?? [];
        $zoneTexts = [
            'Z1 récupération' => 'Très facile, on peut tenir une conversation sans effort. Sert à récupérer et à s\'échauffer.',
            'Z2 endurance' => 'Endurance fondamentale, le cœur de l\'entraînement (environ 80 % du volume). Développe le moteur aérobie sans fatiguer.',
            'Z3 tempo' => 'Allure soutenue mais contrôlée, proche de l\'allure semi-marathon. Phrases courtes seulement.',
            'Z4 seuil' => 'Autour du seuil : effort dur, tenable 20 à 60 minutes. Repousse le moment où l\'acide lactique s\'accumule.',
            'Z5 VMA' => 'Effort maximal ou presque, tenable quelques minutes. Développe la VO2max, en fractionné court.',
        ];
        $dynamics ??= [];

        return array_filter([
            'Allure réelle' => isset($analysis['target']) ? 'Allure moyenne de la course comparée à la fourchette prévue par la séance du programme.' : null,
            'Fractions' => isset($analysis['intervals']) ? 'Portions rapides repérées automatiquement. La variation mesure la régularité entre elles : sous 3 %, c\'est très régulier ; une dernière fraction bien plus lente signale un départ trop rapide ou une fatigue.' : null,
            'Dérive cardiaque' => isset($analysis['drift_percent']) ? 'Hausse de la fréquence cardiaque à allure égale entre la 1re et la 2e moitié de la course. Sous 5 %, l\'endurance est solide à cette allure ; au-delà, fatigue, chaleur, déshydratation ou allure encore trop exigeante.' : null,
            ...(isset($analysis['zone_seconds']) ? collect($zoneTexts)->mapWithKeys(fn (string $text, string $zone) => [
                $zone => $text.(isset($zones[$zone]) ? ' Pour toi : '.$zones[$zone]['from'].' à '.$zones[$zone]['to'].' bpm.' : ''),
            ])->all() : []),
            'Allure en zone 2' => isset($analysis['z2_pace']) ? 'Allure moyenne pendant le temps passé en zone 2. Si elle accélère au fil des semaines à cardio égal, l\'endurance progresse.' : null,
            'Fractions dans la cible' => isset($analysis['intervals']['in_target']) ? 'Chaque fraction comparée à la fourchette d\'allure de la séance (à 5 s/km près). Trop rapide n\'est pas un bon point : la séance vise un effort précis, et partir trop vite se paie sur les dernières.' : null,
            'Moitiés de course' => isset($analysis['split']) ? 'Allure de la 1re et de la 2e moitié de la distance. Un split négatif (2e moitié plus rapide) est le signe d\'une course bien gérée ; une 2e moitié nettement plus lente trahit un départ trop rapide ou un manque d\'endurance.' : null,
            'Coup de mou' => isset($analysis['weak_km']) ? 'Kilomètre plus lent que les autres, et qui le reste en équivalent plat (8 % ou plus) : ce n\'est pas la pente, c\'est l\'effort qui a baissé. Le 1er kilomètre, souvent un échauffement, n\'est pas compté.' : null,
            'Équivalent plat' => isset($analysis['grade_adjusted']) || isset($analysis['split']) || isset($analysis['weak_km']) ? 'L\'allure que tu aurais eue sur un terrain plat pour le même effort. En montée, on court plus lentement sans forcer moins ; en descente douce, plus vite sans forcer plus. L\'équivalent plat gomme la pente pour comparer tes kilomètres et tes courses entre eux. Plus rapide que ton allure réelle : les montées t\'ont ralenti. Plus lent : les descentes t\'ont aidé.' : null,
            'À FC comparable' => isset($insights['comparison']) ? 'Ta course comparée à tes courses d\'il y a 3 à 10 semaines faites au même cardio moyen (à 4 bpm près), toutes ramenées en équivalent plat pour ne pas pénaliser un parcours vallonné. Plus rapide au même cardio : l\'endurance progresse.' : null,
            'VFC' => isset($day['hrv']) ? 'Variabilité de la fréquence cardiaque pendant la nuit, en millisecondes. Plus elle est haute par rapport à ton habitude, mieux tu as récupéré ; une VFC basse signale fatigue, stress, alcool ou maladie.' : null,
            'Body Battery' => isset($day['body_battery']) ? 'Réserve d\'énergie estimée par la montre, de 0 à 100, à partir du sommeil, du stress et de l\'activité. Le maximum du jour correspond en général au réveil.' : null,
            'FC de repos' => isset($day['resting_hr']) ? 'Fréquence cardiaque la plus basse de la journée. 5 bpm ou plus au-dessus de ton habitude : fatigue accumulée, manque de sommeil ou début de maladie.' : null,
            'Foulée' => isset($dynamics['stride_length_cm']) ? 'Distance parcourue à chaque pas. Elle s\'allonge naturellement avec la vitesse ; la forcer n\'est pas conseillé.' : null,
            'Oscillation verticale' => isset($dynamics['vertical_oscillation_cm']) ? 'Rebond du buste à chaque pas. Moins on rebondit, moins on gaspille d\'énergie (souvent entre 6 et 10 cm).' : null,
            'Contact au sol' => isset($dynamics['ground_contact_ms']) ? 'Temps pendant lequel le pied touche le sol à chaque pas. Il raccourcit avec la vitesse ; autour de 250 ms ou moins, c\'est efficace.' : null,
            'Ratio vertical' => isset($dynamics['vertical_ratio']) ? 'Oscillation verticale divisée par la longueur de foulée : plus il est bas, plus la foulée est économique (sous 8 % c\'est bien).' : null,
            'Puissance' => isset($dynamics['power_avg']) ? 'Puissance de course estimée par la montre, en watts. Utile pour comparer l\'effort quand le terrain ou le vent faussent l\'allure.' : null,
            'Effet d\'entraînement' => isset($dynamics['aerobic_effect']) || isset($dynamics['anaerobic_effect']) ? 'Note de 0 à 5 calculée par la montre : impact de la séance sur l\'endurance (aérobie) et sur la capacité à faire des efforts courts et intenses (anaérobie). 2 à 3 entretient, 3 à 4 fait progresser, au-delà de 4 la séance a été très exigeante.' : null,
        ]);
    }

    /**
     * Dynamique de course et effet d'entraînement (données Garmin), en phrases.
     *
     * @param  array<string, mixed>|null  $dynamics
     * @return list<string>
     */
    public static function describeDynamics(?array $dynamics): array
    {
        if (! $dynamics) {
            return [];
        }

        $lines = [];
        $form = collect([
            isset($dynamics['stride_length_cm']) ? 'foulée '.number_format($dynamics['stride_length_cm'] / 100, 2, ',', '').' m' : null,
            isset($dynamics['vertical_oscillation_cm']) ? 'oscillation verticale '.number_format($dynamics['vertical_oscillation_cm'], 1, ',', '').' cm' : null,
            isset($dynamics['ground_contact_ms']) ? 'contact au sol '.$dynamics['ground_contact_ms'].' ms' : null,
            isset($dynamics['vertical_ratio']) ? 'ratio vertical '.number_format($dynamics['vertical_ratio'], 1, ',', '').' %' : null,
            isset($dynamics['power_avg']) ? 'puissance '.$dynamics['power_avg'].' W' : null,
        ])->filter();
        if ($form->isNotEmpty()) {
            $lines[] = 'Dynamique de course : '.$form->implode(', ').'.';
        }
        if (isset($dynamics['aerobic_effect']) || isset($dynamics['anaerobic_effect'])) {
            $lines[] = 'Effet d\'entraînement (montre) : aérobie '.number_format($dynamics['aerobic_effect'] ?? 0, 1, ',', '')
                .', anaérobie '.number_format($dynamics['anaerobic_effect'] ?? 0, 1, ',', '')
                .(isset($dynamics['effect_label']) ? ' — « '.$dynamics['effect_label'].' »' : '').'.';
        }

        return $lines;
    }

    /**
     * Points en mouvement : temps écoulé depuis le point précédent, FC, vitesse.
     *
     * @param  array<string, list<float|int|bool>>  $streams
     * @return list<array{dt: int, hr?: float, speed?: float}>
     */
    private function points(array $streams): array
    {
        $time = $streams['time'] ?? [];
        $points = [];

        foreach ($time as $i => $seconds) {
            if ($i === 0 || (isset($streams['moving'][$i]) && ! $streams['moving'][$i])) {
                continue;
            }
            $dt = (int) $seconds - (int) $time[$i - 1];
            if ($dt <= 0 || $dt > RunningRecords::PAUSE_SECONDS) { // pause ou trou d'enregistrement
                continue;
            }
            $point = ['dt' => $dt];
            if (isset($streams['heartrate'][$i]) && $streams['heartrate'][$i] > 0) {
                $point['hr'] = (float) $streams['heartrate'][$i];
            }
            if (isset($streams['velocity_smooth'][$i])) {
                $point['speed'] = (float) $streams['velocity_smooth'][$i];
            }
            $points[] = $point;
        }

        return $points;
    }

    /**
     * @param  list<array{dt: int, hr?: float, speed?: float}>  $points
     * @param  array<string, array{from: int, to: int}>  $zones
     * @return array<string, int>
     */
    private function zoneSeconds(array $points, array $zones): array
    {
        $seconds = array_fill_keys(array_keys($zones), 0);
        $labels = array_keys($zones);

        foreach ($points as $point) {
            if (! isset($point['hr'])) {
                continue;
            }
            $zone = $labels[0];
            foreach ($zones as $label => $range) {
                if ($point['hr'] >= $range['from']) {
                    $zone = $label;
                }
            }
            $seconds[$zone] += $point['dt'];
        }

        return $seconds;
    }

    /**
     * Allure moyenne (s/km) pendant le temps passé dans une zone, si au moins 5 minutes.
     *
     * @param  list<array{dt: int, hr?: float, speed?: float}>  $points
     * @param  array{from: int, to: int}|null  $zone
     */
    private function paceInZone(array $points, ?array $zone): ?int
    {
        if (! $zone) {
            return null;
        }

        $inZone = array_filter($points, fn (array $point) => isset($point['hr'], $point['speed']) && $point['speed'] > 1
            && $point['hr'] >= $zone['from'] && $point['hr'] <= $zone['to']);
        $seconds = array_sum(array_column($inZone, 'dt'));
        if ($seconds < 300) {
            return null;
        }

        $meters = array_sum(array_map(fn (array $point) => $point['speed'] * $point['dt'], $inZone));

        return (int) round($seconds / ($meters / 1000));
    }

    /**
     * Dérive cardiaque (découplage allure/FC) : baisse du rapport vitesse/FC entre la 1re et la 2e moitié,
     * échauffement (10 premières minutes) exclu. Null si la course est trop courte ou sans FC.
     *
     * @param  list<array{dt: int, hr?: float, speed?: float}>  $points
     */
    private function drift(array $points): ?float
    {
        $points = array_values(array_filter($points, fn (array $point) => isset($point['hr'], $point['speed']) && $point['speed'] > 1));
        $total = array_sum(array_column($points, 'dt'));
        if ($total < self::DRIFT_MIN_SECONDS) {
            return null;
        }

        $elapsed = 0;
        $halves = [[0, 0, 0], [0, 0, 0]]; // [temps, vitesse × temps, FC × temps]
        $half = (600 + $total) / 2;
        foreach ($points as $point) {
            $elapsed += $point['dt'];
            if ($elapsed <= 600) {
                continue;
            }
            $index = $elapsed <= $half ? 0 : 1;
            $halves[$index][0] += $point['dt'];
            $halves[$index][1] += $point['speed'] * $point['dt'];
            $halves[$index][2] += $point['hr'] * $point['dt'];
        }

        if ($halves[0][2] <= 0 || $halves[1][2] <= 0) {
            return null;
        }

        $first = $halves[0][1] / $halves[0][2];
        $second = $halves[1][1] / $halves[1][2];

        return round(($first - $second) / $first * 100, 1);
    }

    /**
     * Tours « d'effort » d'une séance fractionnée : nettement plus rapides que la médiane des tours (au moins 3).
     *
     * @param  list<array<string, mixed>>  $laps
     * @return list<array<string, mixed>>
     */
    private function workLaps(array $laps): array
    {
        $laps = array_values(array_filter($laps, fn (array $lap) => ($lap['avg_speed'] ?? 0) > 0 && ($lap['distance_m'] ?? 0) >= 100));
        if (count($laps) < 4) {
            return [];
        }

        $median = self::median(array_column($laps, 'avg_speed'));
        $work = array_values(array_filter($laps, fn (array $lap) => $lap['avg_speed'] > $median * 1.1));

        return count($work) >= 3 ? $work : [];
    }

    /**
     * Médiane d'une liste de nombres (élément du milieu une fois triée).
     *
     * @param  list<int|float>  $values
     */
    private static function median(array $values): int|float
    {
        sort($values);

        return $values[intdiv(count($values), 2)];
    }

    /**
     * Dénivelé positif (sens 1) ou négatif (sens -1) cumulé de tronçons, en mètres.
     *
     * @param  list<array{rise: float}>  $segments
     */
    private static function elevation(array $segments, int $direction): int
    {
        return (int) round(array_sum(array_map(fn (array $segment) => max(0, $direction * $segment['rise']), $segments)));
    }
}
