<?php

namespace App\Modules\Coach\Services;

use App\Modules\Coach\Models\WeightEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Géométrie des graphiques SVG du tableau de bord (rendus côté serveur, sans bibliothèque).
 * Repère : viewBox 0 0 640 220, marges pour les axes.
 */
class Charts
{
    private const WIDTH = 640;

    private const HEIGHT = 220;

    private const LEFT = 44;

    private const RIGHT = 12;

    private const TOP = 12;

    private const BOTTOM = 28;

    /**
     * Courbe de poids.
     *
     * @param  Collection<int, WeightEntry>  $entries  triées par date
     * @return array{path: string, points: list<array{x: float, y: float, label: string, value: string}>, ticks: list<array{y: float, label: string}>, xLabels: list<array{x: float, label: string}>}|null
     */
    public function weight(Collection $entries): ?array
    {
        if ($entries->isEmpty()) {
            return null;
        }

        $min = floor($entries->min('weight_kg') - 1);
        $max = ceil($entries->max('weight_kg') + 1);
        $first = $entries->first()->measured_on;
        $span = max(1, $first->diffInDays($entries->last()->measured_on));

        $x = fn (Carbon $date) => $entries->count() === 1
            ? self::LEFT + $this->plotWidth() / 2
            : self::LEFT + $first->diffInDays($date) / $span * $this->plotWidth();
        $y = fn (float $value) => self::TOP + ($max - $value) / ($max - $min) * $this->plotHeight();

        $points = $entries->map(fn (WeightEntry $entry) => [
            'x' => round($x($entry->measured_on), 1),
            'y' => round($y($entry->weight_kg), 1),
            'label' => $entry->measured_on->format('d/m/Y'),
            'value' => number_format($entry->weight_kg, 1, ',', '').' kg',
        ])->values()->all();

        $step = max(1, (int) ceil(($max - $min) / 4));
        $ticks = collect(range((int) $min, (int) $max, $step))
            ->map(fn (int $value) => ['y' => round($y($value), 1), 'label' => (string) $value])->all();

        $xLabels = collect([$entries->first(), $entries->last()])->unique('id')
            ->map(fn (WeightEntry $entry) => ['x' => round($x($entry->measured_on), 1), 'label' => $entry->measured_on->format('d/m')])->values()->all();

        $path = collect($points)->map(fn (array $point, int $index) => ($index === 0 ? 'M' : 'L').$point['x'].' '.$point['y'])->implode(' ');

        return compact('path', 'points', 'ticks', 'xLabels');
    }

    /**
     * Barres des kilomètres par semaine.
     *
     * @param  Collection<string, float>  $weeks  clé : lundi (Y-m-d)
     * @return array{bars: list<array{x: float, y: float, width: float, height: float, label: string, value: string, current: bool}>, ticks: list<array{y: float, label: string}>, total: float}
     */
    public function distance(Collection $weeks): array
    {
        $max = max(10, (int) ceil($weeks->max() / 10) * 10);
        $slot = $this->plotWidth() / max(1, $weeks->count());
        $width = max(4, $slot - 8);
        $currentMonday = today()->startOfWeek()->toDateString();

        $bars = $weeks->map(function (float $km, string $monday) use ($max, $slot, $width, $currentMonday, $weeks) {
            $index = $weeks->keys()->search($monday);
            $height = $km / $max * $this->plotHeight();

            return [
                'x' => round(self::LEFT + $index * $slot + ($slot - $width) / 2, 1),
                'y' => round(self::TOP + $this->plotHeight() - $height, 1),
                'width' => round($width, 1),
                'height' => round($height, 1),
                'label' => Carbon::parse($monday)->format('d/m'),
                'value' => number_format($km, 1, ',', '').' km',
                'current' => $monday === $currentMonday,
            ];
        })->values()->all();

        $ticks = collect([0, $max / 2, $max])
            ->map(fn (float|int $value) => ['y' => round(self::TOP + $this->plotHeight() - $value / $max * $this->plotHeight(), 1), 'label' => (string) round($value)])->all();

        return ['bars' => $bars, 'ticks' => $ticks, 'total' => round($weeks->sum(), 1)];
    }

    private function plotWidth(): float
    {
        return self::WIDTH - self::LEFT - self::RIGHT;
    }

    private function plotHeight(): float
    {
        return self::HEIGHT - self::TOP - self::BOTTOM;
    }
}
