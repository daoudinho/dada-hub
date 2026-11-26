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
 * Importe une course Google Health à partir de son point de données (la courbe cardiaque est demandée à part).
 */
class ImportGoogleHealthActivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * @param  array<string, mixed>  $point  point de données « exercise » de Google Health
     */
    public function __construct(public int $accountId, public array $point, public bool $notify = true) {}

    public function handle(GoogleHealthImporter $importer): void
    {
        $account = GoogleHealthAccount::find($this->accountId);
        if (! $account) {
            return; // compte déconnecté entre-temps
        }

        try {
            $importer->import($account, $this->point, $this->notify);
        } catch (GoogleHealthUnavailable $e) {
            if ($e->rateLimited) {
                self::dispatch($this->accountId, $this->point, $this->notify)->delay(now()->addMinutes(15));

                return;
            }
            Log::warning('Google Health : course non importée', ['account' => $account->id, 'activity' => $this->point['name'] ?? null, 'error' => $e->getMessage()]);
            $account->update(['last_error' => ucfirst($e->reason).'. Détail : '.$e->detail]);
        }
    }
}
