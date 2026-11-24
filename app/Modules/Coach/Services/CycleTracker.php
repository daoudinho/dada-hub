<?php

namespace App\Modules\Coach\Services;

use App\Models\User;
use App\Modules\Coach\Models\CycleEntry;
use App\Modules\Coach\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * Suivi du cycle menstruel (si l'athlète l'a activé) : jour du cycle, phase estimée, durée moyenne.
 *
 * Estimation simple à partir des débuts de règles notés : l'ovulation est placée 14 jours avant la fin du cycle.
 * Sous contraception hormonale, seules les règles sont repérées (les phases ne s'appliquent pas).
 */
class CycleTracker
{
    public const DEFAULT_LENGTH = 28;

    /**
     * État du cycle aujourd'hui, ou à une date passée (jour d'une course).
     *
     * @return array{day: int, phase: string, started_on: Carbon, average_length: int, next_expected: Carbon, late: bool, hormonal: bool}|null
     */
    public function status(User $user, ?Carbon $on = null): ?array
    {
        $on = ($on ?? today())->copy()->startOfDay();
        $profile = Profile::ownedBy($user)->first();
        if (! $profile?->tracksCycle()) {
            return null;
        }

        $starts = CycleEntry::ownedBy($user)->where('started_on', '<=', $on)->orderByDesc('started_on')->limit(7)->pluck('started_on');
        if ($starts->isEmpty()) {
            return null;
        }

        $length = $this->averageLength($starts->all());
        $day = (int) $starts->first()->diffInDays($on) + 1;

        return [
            'day' => $day,
            'phase' => $this->phase($day, $length, $profile->hormonal_contraception),
            'started_on' => $starts->first()->copy(),
            'average_length' => $length,
            'next_expected' => $starts->first()->copy()->addDays($length),
            'late' => $day > $length + 7,
            'hormonal' => $profile->hormonal_contraception,
        ];
    }

    /**
     * Cycles qui recouvrent une période, projetés à partir du dernier début de règles et de la durée moyenne :
     * le cycle en cours (noté par l'athlète) puis les suivants (prévus). Un cycle prévu dont le début est déjà passé
     * sans être noté (retard, oubli de saisie) est ignoré. Avec une contraception hormonale, seules les règles sont prévues.
     *
     * @return list<array{start: Carbon, expected: bool, phases: array<string, array{0: Carbon, 1: Carbon}>}>
     */
    public function forecast(User $user, Carbon $from, Carbon $to): array
    {
        $status = $this->status($user);
        if (! $status) {
            return [];
        }

        $length = $status['average_length'];
        $start = $status['started_on'];
        $cycles = [];
        for ($i = 0; $i < 30 && $start->lte($to); $i++, $start = $start->copy()->addDays($length)) {
            $expected = $i > 0;
            if (($expected && ! $start->isAfter(today())) || $start->copy()->addDays($length - 1)->lt($from)) {
                continue;
            }
            $cycles[] = ['start' => $start->copy(), 'expected' => $expected, 'phases' => $this->phases($start, $length, $status['hormonal'])];
        }

        return $cycles;
    }

    /**
     * Dates de chaque phase d'un cycle (mêmes bornes que phase()).
     *
     * @return array<string, array{0: Carbon, 1: Carbon}>
     */
    private function phases(Carbon $start, int $length, bool $hormonal): array
    {
        $day = fn (int $number) => $start->copy()->addDays($number - 1);
        $phases = ['règles' => [$day(1), $day(5)]];
        if ($hormonal) {
            return $phases;
        }

        $ovulation = max(10, $length - 14);

        return $phases + [
            'phase folliculaire' => [$day(6), $day($ovulation - 2)],
            'période d\'ovulation' => [$day($ovulation - 1), $day($ovulation + 1)],
            'phase lutéale' => [$day($ovulation + 2), $day($length)],
        ];
    }

    /**
     * Durée moyenne des derniers cycles complets plausibles (20 à 45 jours), 28 jours par défaut.
     *
     * @param  list<Carbon>  $starts  du plus récent au plus ancien
     */
    public function averageLength(array $starts): int
    {
        $lengths = [];
        for ($i = 0; $i < count($starts) - 1; $i++) {
            $length = (int) $starts[$i + 1]->diffInDays($starts[$i]);
            if ($length >= 20 && $length <= 45) {
                $lengths[] = $length;
            }
        }

        return $lengths ? (int) round(array_sum($lengths) / count($lengths)) : self::DEFAULT_LENGTH;
    }

    public function phase(int $day, int $length, bool $hormonal = false): string
    {
        if ($day <= 5) {
            return 'règles';
        }
        if ($hormonal) {
            return 'hors règles';
        }
        if ($day > $length + 7) {
            return 'retard ou cycle irrégulier';
        }

        $ovulation = max(10, $length - 14);

        return match (true) {
            $day < $ovulation - 1 => 'phase folliculaire',
            $day <= $ovulation + 1 => 'période d\'ovulation',
            default => 'phase lutéale',
        };
    }
}
