<?php

namespace App\Modules\Coach\Services;

/**
 * Tracé GPS en miniature : chemin SVG d'un parcours, ajusté dans un cadre en gardant ses proportions.
 */
final class RouteSvg
{
    /**
     * Attribut « d » du chemin, ou null sans tracé.
     *
     * @param  list<array{0: float, 1: float}>|null  $polyline  [[lat, lng], …]
     */
    public static function path(?array $polyline, int $width, int $height, int $padding = 10): ?string
    {
        if (! $polyline || count($polyline) < 2) {
            return null;
        }

        // Projection plate : la longitude est resserrée selon la latitude moyenne.
        $scaleX = cos(deg2rad(array_sum(array_column($polyline, 0)) / count($polyline)));
        $points = array_map(fn (array $point) => [$point[1] * $scaleX, -$point[0]], $polyline);

        $xs = array_column($points, 0);
        $ys = array_column($points, 1);
        $spanX = max(max($xs) - min($xs), 1e-9);
        $spanY = max(max($ys) - min($ys), 1e-9);
        $scale = min(($width - 2 * $padding) / $spanX, ($height - 2 * $padding) / $spanY);
        $offsetX = ($width - $spanX * $scale) / 2;
        $offsetY = ($height - $spanY * $scale) / 2;

        return collect($points)->map(fn (array $point, int $index) => ($index === 0 ? 'M' : 'L')
            .round(($point[0] - min($xs)) * $scale + $offsetX, 1).' '.round(($point[1] - min($ys)) * $scale + $offsetY, 1))->implode(' ');
    }
}
