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
 * Importe une activité Strava (nouvelle course signalée par le webhook, ou historique).
 * Limite de requêtes atteinte : la tâche se replanifie au quart d'heure suivant.
 */
class ImportStravaActivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $accountId, public int $stravaId, public bool $notify = true) {}

    public function handle(StravaImporter $importer): void
    {
        $account = StravaAccount::find($this->accountId);
        if (! $account) {
            return; // compte déconnecté entre-temps
        }

        try {
            $importer->import($account, $this->stravaId, $this->notify);
        } catch (StravaRateLimited) {
            self::dispatch($this->accountId, $this->stravaId, $this->notify)->delay(StravaRateLimited::retryAt());
        }
    }
}
