<?php

namespace App\Modules\Coach\Services\Strava;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Limite de requêtes Strava atteinte : réessayer au prochain quart d'heure (les fenêtres sont calées sur :00, :15, :30, :45).
 */
class StravaRateLimited extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Strava : limite de requêtes atteinte.');
    }

    public static function retryAt(): Carbon
    {
        $now = now();

        return $now->copy()->startOfHour()->addMinutes((intdiv($now->minute, 15) + 1) * 15)->addSeconds(30);
    }
}
