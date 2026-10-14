<?php

namespace App\Modules\Agenda\Services;

use App\Modules\Agenda\Models\Event;
use App\Modules\Agenda\Models\Source;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Valide les événements renvoyés par l'IA et les enregistre sans doublons.
 */
class EventImporter
{
    /** Mots ignorés pour comparer deux titres (« The Strokes – Live » = « The Strokes »). */
    private const STOP_WORDS = [
        'the', 'le', 'la', 'les', 'l', 'el', 'los', 'las', 'lo', 'de', 'del', 'du', 'des', 'd', 'et', 'y', 'i', 'a', 'en', 'al',
        'in', 'at', 'au', 'aux', 'con', 'with', 'feat', 'ft', 'and',
        'concert', 'concerts', 'concierto', 'concerto', 'live', 'directo', 'tour', 'gira', 'world', 'show',
        'presents', 'presenta', 'presente', 'barcelona', 'barcelone', 'bcn',
    ];

    /** Similarité minimale (en %) pour considérer deux titres du même jour comme le même événement. */
    private const SIMILARITY = 85;

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
     * @return array<int, int> identifiants des événements ajoutés ou mis à jour
     */
    public function import(array $items, array $categories, ?Source $source = null): array
    {
        $today = now('Europe/Madrid')->toDateString();
        $ids = [];

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

            $event = $this->findExisting($title, $date) ?? new Event(['dedupe_key' => self::dedupeKey($title, $date)]);

            if ($event->exists && $event->source === 'manual') {
                $ids[] = $event->id; // saisi à la main : on ne l'écrase pas

                continue;
            }

            $event->fill([
                'title' => mb_substr($title, 0, 150),
                'starts_on' => $date,
                'ends_on' => $endDate,
                'time' => $time,
                'place' => $place !== null ? mb_substr($place, 0, 120) : null,
                'url' => $url !== null ? mb_substr($url, 0, 500) : null,
                'category' => $cat,
                'source' => 'scan',
                // La première source qui a trouvé l'événement en reste propriétaire (voir prune()).
                'source_id' => $event->source_id ?? $source?->id,
            ])->save();

            $ids[] = $event->id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Supprime les événements à venir d'une source qui ne figurent plus sur sa page
     * (annulés, retirés). Les événements saisis à la main et ceux du jour ne sont jamais touchés.
     *
     * @param  array<int, int>  $keptIds  événements trouvés lors de cette analyse
     * @return int nombre d'événements supprimés
     */
    public function prune(Source $source, array $keptIds, string $until): int
    {
        return Event::where('source_id', $source->id)
            ->where('source', 'scan')
            ->whereDate('starts_on', '>', now('Europe/Madrid')->toDateString())
            ->whereDate('starts_on', '<=', $until)
            ->whereNotIn('id', $keptIds)
            ->delete();
    }

    /**
     * Clé anti-doublon : titre normalisé + date.
     */
    public static function dedupeKey(string $title, string $date): string
    {
        return sha1(self::normalizeTitle($title).'|'.$date);
    }

    /**
     * « La Bohème (Puccini) – Live 2026 » → « boheme ».
     */
    public static function normalizeTitle(string $title): string
    {
        $text = Str::ascii(mb_strtolower($title));
        $text = preg_replace('/\([^)]*\)|\[[^\]]*\]|\b20\d{2}\b/', ' ', $text);
        $words = array_filter(
            preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY),
            fn (string $word) => ! in_array($word, self::STOP_WORDS, true),
        );

        return implode(' ', $words) ?: trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Même clé, ou même date avec un titre très proche (l'un contient l'autre, ou similarité ≥ 85 %).
     */
    private function findExisting(string $title, string $date): ?Event
    {
        $event = Event::where('dedupe_key', self::dedupeKey($title, $date))->first();
        if ($event !== null) {
            return $event;
        }

        $normalized = self::normalizeTitle($title);

        return Event::whereDate('starts_on', $date)->limit(100)->get()
            ->first(fn (Event $candidate) => self::sameTitle($normalized, self::normalizeTitle($candidate->title)));
    }

    private static function sameTitle(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        $shorter = mb_strlen($a) <= mb_strlen($b) ? $a : $b;
        $longer = $shorter === $a ? $b : $a;
        if (mb_strlen($shorter) >= 5 && str_contains($longer, $shorter)) {
            return true;
        }

        similar_text($a, $b, $percent);

        return $percent >= self::SIMILARITY;
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
