<?php

namespace App\Modules\Coach\Jobs;

use App\Modules\Coach\Models\GarminAccount;
use App\Modules\Coach\Services\Garmin\GarminImporter;
use App\Modules\Coach\Services\Garmin\GarminUnavailable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Synchronisation Garmin d'un athlète : profil (zones, VO2 max), récupération quotidienne, puis courses
 * (2 ans la première fois, depuis la dernière synchronisation ensuite), chacune importée par sa propre tâche.
 */
class SyncGarminJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Historique importé à la première synchronisation (années). */
    public const BACKFILL_YEARS = 2;

    /** Récupération quotidienne importée à la première synchronisation (jours). */
    public const WELLNESS_BACKFILL_DAYS = 90;

    /** Écart entre deux imports de course (1 requête chacun), pour ménager l'API. */
    public const SPACING_SECONDS = 3;

    public int $tries = 1;

    public int $timeout = 280;

    public function __construct(public int $accountId) {}

    public function handle(GarminImporter $importer): void
    {
        $account = GarminAccount::find($this->accountId);
        if (! $account) {
            return;
        }

        $firstSync = $account->backfilled_at === null;
        $since = $firstSync ? today()->subYears(self::BACKFILL_YEARS) : $account->last_synced_at->copy()->subDay()->startOfDay();

        try {
            $importer->syncProfile($account);
            $importer->syncWellness($account, $firstSync ? today()->subDays(self::WELLNESS_BACKFILL_DAYS) : $since, today());
            $runs = $importer->runsBetween($account, $since, today());
        } catch (GarminUnavailable $e) {
            if ($e->rateLimited) {
                self::dispatch($this->accountId)->delay(now()->addMinutes(15));

                return;
            }
            Log::warning('Garmin : synchronisation impossible', ['account' => $account->id, 'error' => $e->getMessage()]);
            $account->update(['last_error' => ucfirst($e->reason).'. Détail : '.$e->detail]);

            return;
        }

        foreach ($runs->values() as $index => $summary) {
            // L'historique n'ouvre pas de débrief ; une synchro manuelle, si la course est récente (voir GarminImporter).
            ImportGarminActivityJob::dispatch($account->id, $summary, ! $firstSync)->delay(now()->addSeconds($index * self::SPACING_SECONDS));
        }

        $account->update(['last_synced_at' => now(), 'backfilled_at' => $account->backfilled_at ?? now(), 'last_error' => null, 'last_sync_runs' => $runs->count()]
            + ($firstSync ? ['history_total' => $runs->count()] : []));
    }
}
