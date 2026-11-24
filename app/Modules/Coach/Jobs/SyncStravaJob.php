<?php

namespace App\Modules\Coach\Jobs;

use App\Modules\Coach\Models\StravaAccount;
use App\Modules\Coach\Services\Strava\StravaImporter;
use App\Modules\Coach\Services\Strava\StravaRateLimited;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Synchronisation d'un compte Strava : chaussures, puis courses depuis la dernière synchronisation
 * (les 12 dernières semaines la première fois). Chaque course est importée par sa propre tâche,
 * espacées pour rester sous la limite de 100 requêtes par quart d'heure.
 */
class SyncStravaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Écart entre deux imports (2 requêtes chacun) : ~45 courses par quart d'heure. */
    private const SPACING_SECONDS = 20;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $accountId) {}

    public function handle(StravaImporter $importer): void
    {
        $account = StravaAccount::find($this->accountId);
        if (! $account) {
            return;
        }

        $firstSync = $account->backfilled_at === null;
        $since = $firstSync
            ? today()->subWeeks((int) config('coach.strava.backfill_weeks'))
            : $account->last_synced_at->copy()->subDay();

        try {
            $importer->syncGear($account);
            $ids = $importer->runIdsSince($account, $since);
        } catch (StravaRateLimited) {
            self::dispatch($this->accountId)->delay(StravaRateLimited::retryAt());

            return;
        }

        foreach (array_values($ids) as $index => $id) {
            // L'historique n'ouvre pas de débrief ; une synchro manuelle, si la course est récente (voir StravaImporter).
            ImportStravaActivityJob::dispatch($account->id, $id, ! $firstSync)->delay(now()->addSeconds($index * self::SPACING_SECONDS));
        }

        $account->update(['last_synced_at' => now(), 'backfilled_at' => $account->backfilled_at ?? now()]);
    }
}
