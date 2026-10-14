<?php

namespace App\Modules\Agenda\Services;

use App\Modules\Agenda\Models\Event;
use App\Modules\Agenda\Models\Source;
use RuntimeException;

/**
 * Valide les événements renvoyés par l'IA et les enregistre sans doublons.
 */
class EventImporter
{
    /**
     * Extrait la liste d'événements d'une réponse texte : {"events": [...]} ou un tableau JSON brut.
     *
     * @return array<int, mixed>
     */
    public function parse(string $text): array
    {
        $decoded = json_decode(trim($text), true);
        if (is_array($decoded) && is_array($decoded['events'] ?? null)) {
            return $decoded['events'];
        }
        if (is_array($decoded) && array_is_list($decoded)) {
            return $decoded;
        }

        $start = strpos($text, '[');
        $end = strrpos($text, ']');
        if ($start === false || $end === false) {
            throw new RuntimeException('Réponse sans tableau JSON.');
        }
        $items = json_decode(substr($text, $start, $end - $start + 1), true);
        if (! is_array($items)) {
            throw new RuntimeException('JSON illisible.');
        }

        return $items;
    }

    /**
     * @param  array<int, mixed>  $items
     * @param  array<int, string>  $categories  catégories acceptées
     * @return int nombre d'événements ajoutés ou mis à jour
     */
    public function import(array $items, array $categories, ?Source $source = null): int
    {
        $today = now('Europe/Madrid')->toDateString();
        $count = 0;

        foreach ($items as $it) {
            if (! is_array($it)) {
                continue;
            }
            $title = trim((string) ($it['title'] ?? ''));
            $date = (string) ($it['start'] ?? '');
            $cat = (string) ($it['category'] ?? '');
            if ($title === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $today
                || ! in_array($cat, $categories, true)) {
                continue;
            }
            $endDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($it['end'] ?? '')) && $it['end'] > $date ? $it['end'] : null;
            $time = preg_match('/^\d{2}:\d{2}$/', (string) ($it['time'] ?? '')) ? $it['time'] : null;
            $url = $this->absoluteUrl((string) ($it['url'] ?? ''), $source?->url);
            $place = trim((string) ($it['place'] ?? '')) ?: $source?->name;

            Event::updateOrCreate(
                ['dedupe_key' => sha1(mb_strtolower($title).'|'.$date)],
                [
                    'title' => mb_substr($title, 0, 150),
                    'starts_on' => $date,
                    'ends_on' => $endDate,
                    'time' => $time,
                    'place' => $place !== null ? mb_substr($place, 0, 120) : null,
                    'url' => $url !== null ? mb_substr($url, 0, 500) : null,
                    'category' => $cat,
                    'source' => 'scan',
                    'source_id' => $source?->id,
                ]
            );
            $count++;
        }

        return $count;
    }

    /**
     * Garde les URL http(s) ; complète les liens relatifs avec le domaine de la source.
     */
    private function absoluteUrl(string $url, ?string $base): ?string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if ($base !== null && str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            $parts = parse_url($base);

            return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').$url;
        }

        return $base;
    }
}
