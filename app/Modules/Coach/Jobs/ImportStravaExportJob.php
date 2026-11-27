<?php

namespace App\Modules\Coach\Jobs;

use App\Models\User;
use App\Modules\Coach\Services\SamsungHealth\SamsungHealthImporter;
use App\Modules\Coach\Services\Strava\StravaExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Import de l'historique d'un export Strava envoyé depuis le profil, sans débrief ni annonce de record. Les courses
 * sont importées par lots : au-delà de BATCH_SECONDS, la tâche se relance pour la suite (le worker coupe à 280 s).
 * L'avancement est gardé en cache pour le profil ; le fichier est supprimé à la fin.
 */
class ImportStravaExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Durée d'un lot avant de passer la main à la tâche suivante. */
    private const BATCH_SECONDS = 150;

    public int $tries = 1;

    public int $timeout = 270;

    /**
     * @param  string  $path  chemin du zip sur le disque local
     * @param  array{created: int, merged: int, updated: int}  $counts  courses déjà traitées par les lots précédents
     */
    public function __construct(
        public int $userId,
        public string $path,
        public int $offset = 0,
        public array $counts = ['created' => 0, 'merged' => 0, 'updated' => 0],
    ) {}

    /** Clé de cache de l'avancement de l'import d'un athlète. */
    public static function statusKey(int $userId): string
    {
        return 'coach.strava_export.'.$userId;
    }

    public function handle(SamsungHealthImporter $importer): void
    {
        $user = User::find($this->userId);
        if (! $user || ! Storage::disk('local')->exists($this->path)) {
            return;
        }

        $export = new StravaExport(Storage::disk('local')->path($this->path));
        $rows = $export->runRows();
        $startedAt = microtime(true);

        for ($index = $this->offset; $index < count($rows); $index++) {
            if (microtime(true) - $startedAt > self::BATCH_SECONDS) {
                $this->status('running', $index, count($rows));
                self::dispatch($this->userId, $this->path, $index, $this->counts);

                return;
            }
            $run = $export->run($rows[$index], StravaExport::TIMEZONE);
            $this->counts[$importer->import($user, $run, notify: false, source: 'strava')['status']]++;
        }

        $this->status('done', count($rows), count($rows), $export->skipped);
        $export->close();
        Storage::disk('local')->delete($this->path);
    }

    public function failed(?Throwable $exception): void
    {
        Cache::forever(self::statusKey($this->userId), ['state' => 'failed', 'error' => mb_substr((string) $exception?->getMessage(), 0, 300), 'at' => now()->toIso8601String()]);
        Storage::disk('local')->delete($this->path);
    }

    private function status(string $state, int $done, int $total, int $skipped = 0): void
    {
        Cache::forever(self::statusKey($this->userId), $this->counts + ['state' => $state, 'done' => $done, 'total' => $total, 'skipped' => $skipped, 'at' => now()->toIso8601String()]);
    }
}
