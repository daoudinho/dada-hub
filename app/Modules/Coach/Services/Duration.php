<?php

namespace App\Modules\Coach\Services;

/**
 * Formatage des durées et allures de course.
 */
final class Duration
{
    /** 6330 → « 1h45:30 », 1530 → « 25:30 ». */
    public static function format(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest = $seconds % 60;

        return $hours > 0
            ? sprintf('%dh%02d:%02d', $hours, $minutes, $rest)
            : sprintf('%d:%02d', $minutes, $rest);
    }

    /** Allure en min/km : 330 s/km → « 5:30 /km ». */
    public static function pace(int $seconds, int $meters): ?string
    {
        if ($meters <= 0) {
            return null;
        }

        $perKm = (int) round($seconds / ($meters / 1000));

        return sprintf('%d:%02d /km', intdiv($perKm, 60), $perKm % 60);
    }

    /** Allure d'une vitesse (m/s) : 3,03 → « 5:30 » ; null à l'arrêt. */
    public static function fromSpeed(float $metersPerSecond): ?string
    {
        return $metersPerSecond > 0 ? self::format((int) round(1000 / $metersPerSecond)) : null;
    }

    /** « 1:45:30 », « 1h45 », « 52:10 » ou « 52 » (minutes) → secondes ; null si illisible. */
    public static function parse(?string $text): ?int
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }

        if (preg_match('/^(\d+)\s*h\s*(\d{1,2})?(?::(\d{1,2}))?$/i', $text, $m)) {
            return (int) $m[1] * 3600 + (int) ($m[2] ?? 0) * 60 + (int) ($m[3] ?? 0);
        }

        $parts = explode(':', $text);
        if (! collect($parts)->every(fn (string $part) => ctype_digit($part)) || count($parts) > 3) {
            return null;
        }

        return match (count($parts)) {
            1 => (int) $parts[0] * 60,
            2 => (int) $parts[0] * 60 + (int) $parts[1],
            3 => (int) $parts[0] * 3600 + (int) $parts[1] * 60 + (int) $parts[2],
        };
    }
}
