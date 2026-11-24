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
 * Importe une course Garmin à partir de son résumé (courbes, tours, tracé et analyse demandés à part).
 */
class ImportGarminActivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * @param  array<string, mixed>  $summary  élément de la liste des activités Garmin
     */
    public function __construct(public int $accountId, public array $summary, public bool $notify = true) {}

    public function handle(GarminImporter $importer): void
    {
        $account = GarminAccount::find($this->accountId);
        if (! $account) {
            return;
        }

        try {
            $importer->import($account, $this->summary, $this->notify);
        } catch (GarminUnavailable $e) {
            if ($e->rateLimited) {
                self::dispatch($this->accountId, $this->summary, $this->notify)->delay(now()->addMinutes(15));

                return;
            }
            Log::warning('Garmin : course non importée', ['account' => $account->id, 'activity' => $this->summary['activityId'] ?? null, 'error' => $e->getMessage()]);
            $account->update(['last_error' => ucfirst($e->reason).'. Détail : '.$e->detail]);
        }
    }
}
