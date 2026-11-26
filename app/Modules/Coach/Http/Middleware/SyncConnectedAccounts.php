<?php

namespace App\Modules\Coach\Http\Middleware;

use App\Modules\Coach\Jobs\SyncGarminJob;
use App\Modules\Coach\Jobs\SyncGoogleHealthJob;
use App\Modules\Coach\Models\GarminAccount;
use App\Modules\Coach\Models\GoogleHealthAccount;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Synchronisation automatique à l'ouverture d'une page du coach : chaque compte relié sans webhook (Garmin, Google Health)
 * dont la dernière synchronisation date de plus de config('coach.auto_sync_minutes') est resynchronisé en arrière-plan.
 * Strava n'est pas concerné : son webhook importe déjà chaque nouvelle course. Le bouton de synchronisation reste disponible.
 */
class SyncConnectedAccounts
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && ! $request->expectsJson() && $request->user()) {
            $this->syncStaleAccounts($request);
        }

        return $next($request);
    }

    private function syncStaleAccounts(Request $request): void
    {
        $user = $request->user();
        $minutes = (int) config('coach.auto_sync_minutes');

        $garmin = GarminAccount::ownedBy($user)->first();
        if ($garmin && ! $garmin->keyExpired() && $this->isStale($garmin->backfilled_at, $garmin->last_synced_at, $minutes)
            && $this->claim('garmin', $garmin->id, $minutes)) {
            SyncGarminJob::dispatch($garmin->id);
        }

        $google = GoogleHealthAccount::ownedBy($user)->first();
        if ($google && ! $google->needsReconnect() && $this->isStale($google->backfilled_at, $google->last_synced_at, $minutes)
            && $this->claim('google-health', $google->id, $minutes)) {
            SyncGoogleHealthJob::dispatch($google->id);
        }
    }

    /** Historique déjà importé (sinon la première synchronisation est en cours) et dernière synchronisation trop ancienne. */
    private function isStale(?\DateTimeInterface $backfilledAt, ?\DateTimeInterface $lastSyncedAt, int $minutes): bool
    {
        return $backfilledAt !== null && ($lastSyncedAt === null || $lastSyncedAt < now()->subMinutes($minutes));
    }

    /** Une seule synchronisation automatique par compte et par période, même si plusieurs pages s'ouvrent avant qu'elle finisse. */
    private function claim(string $source, int $accountId, int $minutes): bool
    {
        return Cache::add('coach:auto-sync:'.$source.':'.$accountId, true, now()->addMinutes($minutes));
    }
}
