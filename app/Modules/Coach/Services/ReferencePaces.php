<?php

namespace App\Modules\Coach\Services;

use App\Models\User;
use App\Modules\Coach\Models\BestEffort;
use App\Modules\Coach\Models\PastRace;
use App\Modules\Coach\Models\Profile;
use App\Modules\Coach\Models\RunningActivity;

/**
 * Repères calculés (et non devinés par l'IA) : niveau VDOT, allures d'entraînement, temps prévisibles, zones cardiaques.
 *
 * VDOT de Jack Daniels, à partir de la meilleure performance récente (course passée, course ou test du programme).
 * Formules valables pour un effort maximal de 3 min 30 à 4 h.
 */
class ReferencePaces
{
    /**
     * Allures d'entraînement : fraction de VO2max visée [rapide, lente] (repères de Daniels).
     *
     * @var array<string, array{0: float, 1: float, 2: string}>
     */
    public const PACES = [
        'easy' => [0.67, 0.59, 'Endurance fondamentale'],
        'marathon' => [0.82, 0.78, 'Allure marathon'],
        'threshold' => [0.88, 0.86, 'Seuil'],
        'interval' => [1.00, 0.95, 'Fractionné (VMA)'],
        'repetition' => [1.10, 1.05, 'Répétitions courtes'],
    ];

    /** @var array<string, float> distances prévues (mètres) */
    public const PREDICTIONS = ['5 km' => 5000, '10 km' => 10000, 'Semi-marathon' => 21097.5, 'Marathon' => 42195];

    /** Zones cardiaques en % de la FC de réserve (ou de la FC max sans FC de repos). */
    public const ZONES = ['Z1 récupération' => [0.50, 0.60], 'Z2 endurance' => [0.60, 0.70], 'Z3 tempo' => [0.70, 0.80], 'Z4 seuil' => [0.80, 0.90], 'Z5 VMA' => [0.90, 1.00]];

    /** VDOT d'une performance, null hors du domaine de validité. */
    public static function vdot(float $meters, int $seconds): ?float
    {
        $minutes = $seconds / 60;
        if ($meters <= 0 || $minutes < 3.5 || $minutes > 240) {
            return null;
        }

        $velocity = $meters / $minutes;
        $vo2 = -4.60 + 0.182258 * $velocity + 0.000104 * $velocity ** 2;
        $fraction = 0.8 + 0.1894393 * exp(-0.012778 * $minutes) + 0.2989558 * exp(-0.1932605 * $minutes);

        return round($vo2 / $fraction, 1);
    }

    /** Vitesse (m/min) correspondant à une consommation d'oxygène donnée. */
    public static function velocity(float $vo2): float
    {
        return (-0.182258 + sqrt(0.182258 ** 2 + 4 * 0.000104 * ($vo2 + 4.60))) / (2 * 0.000104);
    }

    /** Allure (secondes par km) à une fraction du VDOT. */
    public static function pace(float $vdot, float $fraction): int
    {
        return (int) round(60000 / self::velocity($vdot * $fraction));
    }

    /** Temps prévisible (secondes) sur une distance pour un VDOT donné. */
    public static function predict(float $vdot, float $meters): int
    {
        [$low, $high] = [60, 6 * 3600];
        for ($i = 0; $i < 60; $i++) {
            $mid = ($low + $high) / 2;
            $value = self::vdot($meters, (int) round($mid)) ?? ($mid < 210 ? INF : 0);
            $value > $vdot ? $low = $mid : $high = $mid;
        }

        return (int) round(($low + $high) / 2);
    }

    /**
     * Meilleure performance récente : courses passées (24 mois), courses et tests du programme,
     * meilleurs temps Strava sur 5 km et plus (12 mois).
     *
     * @return array{vdot: float, source: string}|null
     */
    public function best(User $user): ?array
    {
        $candidates = PastRace::ownedBy($user)->where('raced_on', '>=', today()->subMonths(24))->get()
            ->map(fn (PastRace $race) => ['vdot' => self::vdot($race->distance_km * 1000, $race->time_seconds), 'source' => 'course passée : '.$race->label()]);

        $tests = RunningActivity::ownedBy($user)
            ->where('started_at', '>=', today()->subMonths(12))
            ->whereHas('session', fn ($query) => $query->whereIn('type', ['test', 'race']))
            ->with('session')
            ->get()
            ->map(fn (RunningActivity $activity) => ['vdot' => self::vdot($activity->distance_m, $activity->moving_seconds),
                'source' => $activity->session->typeLabel().' du '.$activity->started_at->format('d/m/Y').' : '.$activity->metrics()]);

        $efforts = BestEffort::ownedBy($user)->whereIn('name', BestEffort::RELEVANT)->where('achieved_on', '>=', today()->subMonths(12))->get()
            ->map(fn (BestEffort $effort) => ['vdot' => self::vdot($effort->distance_m, $effort->elapsed_seconds), 'source' => 'meilleure performance : '.$effort->label()]);

        return $candidates->concat($tests)->concat($efforts)->filter(fn (array $candidate) => $candidate['vdot'] !== null)->sortByDesc('vdot')->first();
    }

    /**
     * Tous les repères de l'athlète.
     *
     * @return array{vdot: ?float, source: ?string, paces: array<string, array{label: string, from: int, to: int}>, predictions: array<string, int>, zones: array<string, array{from: int, to: int}>, zones_basis: ?string}
     */
    public function forUser(User $user): array
    {
        $best = $this->best($user);
        $profile = Profile::ownedBy($user)->first();

        $paces = $predictions = [];
        if ($best) {
            foreach (self::PACES as $key => [$fast, $slow, $label]) {
                $paces[$key] = ['label' => $label, 'from' => self::pace($best['vdot'], $fast), 'to' => self::pace($best['vdot'], $slow)];
            }
            foreach (self::PREDICTIONS as $label => $meters) {
                $predictions[$label] = self::predict($best['vdot'], $meters);
            }
        }

        [$zones, $basis] = $this->zones($profile);

        return ['vdot' => $best['vdot'] ?? null, 'source' => $best['source'] ?? null, 'paces' => $paces, 'predictions' => $predictions, 'zones' => $zones, 'zones_basis' => $basis];
    }

    /**
     * Zones cardiaques seules (sans recalculer le VDOT).
     *
     * @return array<string, array{from: int, to: int}>
     */
    public function zonesFor(User $user): array
    {
        return $this->zones(Profile::ownedBy($user)->first())[0];
    }

    /**
     * @return array{0: array<string, array{from: int, to: int}>, 1: ?string}
     */
    private function zones(?Profile $profile): array
    {
        if (! $profile?->max_hr) {
            return [[], null];
        }

        // Zones de la montre (Garmin) : planchers Z1 à Z5, la zone 5 allant jusqu'à la FC max.
        $floors = $profile->hr_zone_floors;
        if (is_array($floors) && count($floors) === 5) {
            $labels = array_keys(self::ZONES);
            $zones = [];
            foreach ($labels as $i => $label) {
                $zones[$label] = ['from' => (int) $floors[$i], 'to' => (int) ($i < 4 ? $floors[$i + 1] - 1 : $profile->max_hr)];
            }

            return [$zones, 'zones de la montre Garmin'.($profile->threshold_hr ? ', seuil '.$profile->threshold_hr : '')];
        }

        $rest = $profile->resting_hr ?: 0;
        $reserve = $profile->max_hr - $rest;
        $zones = collect(self::ZONES)->map(fn (array $range) => [
            'from' => (int) round($rest + $range[0] * $reserve),
            'to' => (int) round($rest + $range[1] * $reserve),
        ])->all();

        return [$zones, $rest ? 'FC de réserve (Karvonen)' : '% de la FC max (FC de repos inconnue)'];
    }
}
