<?php

namespace App\Modules\Coach\Jobs;

use App\Modules\Coach\Models\GoogleHealthAccount;
use App\Modules\Coach\Services\GoogleHealth\GoogleHealthImporter;
use App\Modules\Coach\Services\GoogleHealth\GoogleHealthUnavailable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Synchronisation Google Health d'un athlète : courses depuis la dernière synchronisation (l'historique la première fois),
 * chacune importée par sa propre tâche.
 */
class SyncGoogleHealthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Écart entre deux imports de course (1 requête chacun, pour la FC), pour ménager l'API. */
    public const SPACING_SECONDS = 3;

    public int $tries = 1;

    public int $timeout = 280;

    public function __construct(public int $accountId) {}

    public function handle(GoogleHealthImporter $importer): void
    {
        $account = GoogleHealthAccount::find($this->accountId);
        if (! $account) {
            return;
        }

        $firstSync = $account->backfilled_at === null;
        $since = $firstSync
            ? today()->subMonths((int) config('coach.google_health.backfill_months'))
            : $account->last_synced_at->copy()->subDay()->startOfDay();

        try {
            $runs = $importer->runsBetween($account, $since, today());
        } catch (GoogleHealthUnavailable $e) {
            if ($e->rateLimited) {
                self::dispatch($this->accountId)->delay(now()->addMinutes(15));

                return;
            }
            Log::warning('Google Health : synchronisation impossible', ['account' => $account->id, 'error' => $e->getMessage()]);
            $account->update(['last_error' => ucfirst($e->reason).'. Détail : '.$e->detail]);

            return;
        }

        foreach ($runs->values() as $index => $point) {
            // L'historique n'ouvre pas de débrief ; une synchro ensuite, si la course est récente (voir GoogleHealthImporter).
            ImportGoogleHealthActivityJob::dispatch($account->id, $point, ! $firstSync)->delay(now()->addSeconds($index * self::SPACING_SECONDS));
        }

        $account->update(['last_synced_at' => now(), 'backfilled_at' => $account->backfilled_at ?? now(), 'last_error' => null]);
    }
}
