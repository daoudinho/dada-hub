<?php

namespace App\Modules\Coach\Services\Strava;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use ZipArchive;

/**
 * Lecture d'un export de compte Strava (« Télécharger vos données », le .zip ou son dossier décompressé) : les courses
 * de activities.csv, avec le tracé et les courbes de leur fichier TCX ou GPX, au format de SamsungHealthImporter.
 *
 * Le CSV est lu par position de colonne : ses en-têtes sont traduits dans la langue du compte et certains reviennent
 * deux fois. Ses dates sont en UTC ; les fichiers FIT ne sont pas lus (résumé du CSV seulement).
 */
class StravaExport
{
    /** Colonnes de activities.csv utilisées. */
    private const ID = 0;

    private const DATE = 1;

    private const NAME = 2;

    private const TYPE = 3;

    private const DESCRIPTION = 4;

    private const FILENAME = 12;

    private const ELAPSED = 15;

    private const MOVING = 16;

    private const DISTANCE = 17;

    private const ELEVATION_GAIN = 20;

    private const AVG_CADENCE = 29;

    private const MAX_HR = 30;

    private const AVG_HR = 31;

    private const PERCEIVED_EFFORT = 43;

    private const TAGS = 103;

    /** Types d'activité « course », dans les langues d'export courantes. */
    private const RUN_TYPES = ['run', 'trail run', 'virtual run', 'course à pied', 'course à pied virtuelle', 'trail'];

    /** Fuseau de l'athlète, pour l'heure locale des courses (le CSV et les fichiers sont en UTC). */
    public const TIMEZONE = 'Europe/Paris';

    /** Distance (mètres) en dessous de laquelle une course est un faux départ, ignoré. */
    public const MIN_DISTANCE_M = 200;

    /** Mesure (FC, vitesse, cadence) gardée au plus tant de secondes, faute de nouvelle valeur. */
    private const CARRY_SECONDS = 30;

    private const MONTHS = ['janv' => 1, 'févr' => 2, 'mars' => 3, 'avr' => 4, 'mai' => 5, 'juin' => 6, 'juil' => 7, 'août' => 8,
        'sept' => 9, 'oct' => 10, 'nov' => 11, 'déc' => 12];

    private ?ZipArchive $zip = null;

    private string $folder = '';

    public function __construct(string $path)
    {
        if (is_dir($path)) {
            $this->folder = rtrim($path, '/\\');
        } elseif (is_file($path) && ($zip = new ZipArchive)->open($path) === true) {
            $this->zip = $zip;
        } else {
            throw new InvalidArgumentException('Export introuvable ou illisible : '.$path);
        }

        if ($this->read('activities.csv') === null) {
            throw new InvalidArgumentException('activities.csv est absent de l\'export.');
        }
    }

    /** Ferme le zip (sous Windows, un fichier ouvert ne peut pas être supprimé). */
    public function close(): void
    {
        $this->zip?->close();
        $this->zip = null;
    }

    /** Activités de l'export qui ne sont pas des courses, ou des faux départs. */
    public int $skipped = 0;

    /**
     * Lignes du CSV qui sont des courses, de la plus ancienne à la plus récente ; chacune se lit ensuite avec run(),
     * une à la fois (les tracés de tout l'historique ne tiennent pas ensemble en mémoire).
     *
     * @return list<list<string>>
     */
    public function runRows(): array
    {
        $this->skipped = 0;
        $runs = [];
        foreach ($this->rows() as $row) {
            $isRun = in_array(mb_strtolower(trim($row[self::TYPE])), self::RUN_TYPES, true) || in_array('running', self::tags($row), true);
            if (! $isRun || self::number($row[self::DISTANCE]) < self::MIN_DISTANCE_M) {
                $this->skipped++;

                continue;
            }
            $runs[] = $row;
        }

        return array_reverse($runs);
    }

    /**
     * @return list<list<string>>
     */
    private function rows(): array
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $this->read('activities.csv'));
        rewind($stream);

        $header = fgetcsv($stream, escape: '');
        if (! $header || count($header) <= self::TAGS) {
            throw new InvalidArgumentException('Format de activities.csv inconnu ('.count($header ?: []).' colonnes).');
        }

        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            if (count($row) > self::TAGS && ctype_digit($row[self::ID])) {
                $rows[] = $row;
            }
        }
        fclose($stream);

        return $rows;
    }

    /**
     * Course au format de SamsungHealthImporter, avec le tracé et les courbes de son fichier.
     *
     * @param  list<string>  $row  ligne donnée par runRows()
     * @return array<string, mixed>
     */
    public function run(array $row, string $timezone): array
    {
        $tags = self::tags($row);
        $points = $this->points($row[self::FILENAME]);
        $start = ($points ? Carbon::createFromTimestamp($points[0]['time']) : self::csvDate($row[self::DATE]))->setTimezone($timezone);
        $elapsed = (int) round(self::number($row[self::ELAPSED]) ?: ($points ? end($points)['time'] - $points[0]['time'] : 0));
        $cadence = self::number($row[self::AVG_CADENCE]);
        $effort = self::number($row[self::PERCEIVED_EFFORT]);

        [$route, $samples] = $this->streams($points);

        return [
            'id' => $row[self::ID],
            'type' => match (true) {
                in_array('virtual', $tags, true) || str_contains(mb_strtolower($row[self::TYPE]), 'virtu') => 'VirtualRun',
                in_array('offroad', $tags, true) || str_contains(mb_strtolower($row[self::TYPE]), 'trail') => 'TrailRun',
                default => 'Run',
            },
            'title' => $row[self::NAME],
            'notes' => trim($row[self::DESCRIPTION]) ?: null,
            'rpe' => $effort >= 1 && $effort <= 10 ? (int) round($effort) : null,
            'start' => $start->toIso8601String(),
            'end' => $start->copy()->addSeconds($elapsed)->toIso8601String(),
            'duration_s' => self::number($row[self::MOVING]) ?: null,
            'distance_m' => self::number($row[self::DISTANCE]),
            'mean_hr' => self::number($row[self::AVG_HR]) ?: null,
            'max_hr' => self::number($row[self::MAX_HR]) ?: null,
            // Strava compte les pas d'une seule jambe : ×2 pour des pas par minute.
            'mean_cadence' => $cadence ? $cadence * 2 : null,
            'elevation_gain_m' => $row[self::ELEVATION_GAIN] !== '' ? self::number($row[self::ELEVATION_GAIN]) : null,
            'route' => $route,
            'samples' => $samples,
        ];
    }

    /**
     * Tracé [[s, lat, lon, alt?]…] et journal [[s, fc, vitesse, cadence]…] depuis le départ ; une mesure manquante
     * reprend la précédente si elle date de moins de 30 s (le TCX ne répète pas la FC à chaque point).
     *
     * @param  list<array<string, mixed>>  $points
     * @return array{0: list<list<float|int|null>>, 1: list<list<float|int|null>>}
     */
    private function streams(array $points): array
    {
        if ($points === []) {
            return [[], []];
        }

        $origin = $points[0]['time'];
        $route = $samples = [];
        $last = ['hr' => [null, 0], 'speed' => [null, 0], 'cadence' => [null, 0]];
        foreach ($points as $point) {
            $second = $point['time'] - $origin;
            if ($point['lat'] !== null && $point['lon'] !== null) {
                $route[] = [$second, $point['lat'], $point['lon'], $point['alt']];
            }
            $sample = [$second];
            foreach (['hr', 'speed', 'cadence'] as $measure) {
                if ($point[$measure] !== null) {
                    $last[$measure] = [$point[$measure], $second];
                }
                $sample[] = $second - $last[$measure][1] <= self::CARRY_SECONDS ? $last[$measure][0] : null;
            }
            if ($sample[1] !== null || $sample[2] !== null || $sample[3] !== null) {
                $samples[] = $sample;
            }
        }

        return [$route, $samples];
    }

    /**
     * Points horodatés du fichier de l'activité (TCX ou GPX, compressés ou non). Vide pour un FIT ou sans fichier.
     *
     * @return list<array{time: int, lat: ?float, lon: ?float, alt: ?float, hr: ?float, speed: ?float, cadence: ?float}>
     */
    private function points(string $filename): array
    {
        $format = strtolower(pathinfo(preg_replace('/\.gz$/i', '', $filename), PATHINFO_EXTENSION));
        if (! in_array($format, ['tcx', 'gpx'], true) || ($content = $this->read($filename)) === null) {
            return [];
        }
        if (str_ends_with(strtolower($filename), '.gz')) {
            $content = @gzdecode($content);
        }

        $document = new DOMDocument;
        if (! $content || ! @$document->loadXML(trim($content), LIBXML_NONET)) {
            return [];
        }
        $xpath = new DOMXPath($document);
        $value = fn (DOMElement $node, string $path) => is_numeric($text = trim((string) $xpath->evaluate('string('.$path.')', $node))) ? (float) $text : null;

        $points = [];
        $nodes = $format === 'tcx' ? $xpath->query("//*[local-name()='Trackpoint']") : $xpath->query("//*[local-name()='trkpt']");
        foreach ($nodes as $node) {
            $time = strtotime(trim((string) $xpath->evaluate("string(*[local-name()='Time' or local-name()='time'])", $node)));
            if ($time === false) {
                continue;
            }
            $cadence = $format === 'tcx'
                ? $value($node, "*[local-name()='Cadence']") ?? $value($node, ".//*[local-name()='RunCadence']")
                : $value($node, ".//*[local-name()='cad']");
            $points[] = [
                'time' => $time,
                'lat' => $format === 'tcx' ? $value($node, ".//*[local-name()='LatitudeDegrees']") : (float) $node->getAttribute('lat'),
                'lon' => $format === 'tcx' ? $value($node, ".//*[local-name()='LongitudeDegrees']") : (float) $node->getAttribute('lon'),
                'alt' => $value($node, "*[local-name()='AltitudeMeters' or local-name()='ele']"),
                'hr' => $format === 'tcx' ? $value($node, ".//*[local-name()='HeartRateBpm']/*[local-name()='Value']") : $value($node, ".//*[local-name()='hr']"),
                'speed' => $value($node, ".//*[local-name()='Speed']"),
                'cadence' => $cadence ? $cadence * 2 : null,
            ];
        }
        usort($points, fn (array $a, array $b) => $a['time'] <=> $b['time']);

        return $points;
    }

    /**
     * Étiquettes de l'activité (« offroad|running »).
     *
     * @param  list<string>  $row
     * @return list<string>
     */
    private static function tags(array $row): array
    {
        return explode('|', strtolower($row[self::TAGS] ?? ''));
    }

    private function read(string $name): ?string
    {
        if ($name === '' || str_contains($name, '..')) {
            return null;
        }
        if ($this->zip) {
            $content = $this->zip->getFromName($name);

            return $content === false ? null : $content;
        }
        $path = $this->folder.DIRECTORY_SEPARATOR.$name;

        return is_file($path) ? file_get_contents($path) : null;
    }

    /** Date du CSV, en UTC : « 19 juin 2026, 19:21:49 » (export en français) ou « Jun 19, 2026, 7:21:49 PM ». */
    private static function csvDate(string $date): Carbon
    {
        if (preg_match('/^(\d{1,2}) ([^\s.]+)\.? (\d{4}), (\d{1,2}:\d{2}:\d{2})$/u', trim($date), $parts) && isset(self::MONTHS[mb_strtolower($parts[2])])) {
            return Carbon::parse(sprintf('%s-%02d-%02d %s', $parts[3], self::MONTHS[mb_strtolower($parts[2])], $parts[1], $parts[4]), 'UTC');
        }

        return Carbon::parse($date, 'UTC');
    }

    /** Nombre du CSV : « 5363.5 », ou « 5,36 » (virgule décimale de l'export en français). 0 si vide. */
    private static function number(?string $value): float
    {
        $value = str_replace(',', '.', trim((string) $value));

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
