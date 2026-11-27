<?php

namespace App\Modules\Coach\Http\Middleware;

use App\Modules\Coach\Models\SyncAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appels de l'app Dada Sync : la clé personnelle de l'athlète (en-tête « Authorization: Bearer … ») désigne son compte.
 * Le compte trouvé est rangé dans les attributs de la requête (« syncAccount »).
 */
class AuthenticateSyncAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        $account = $token !== '' ? SyncAccount::findByToken($token) : null;

        if (! $account || ! $account->user?->canAccessModule('coach')) {
            return response()->json(['message' => 'Clé Dada Sync inconnue ou révoquée : crée une nouvelle clé dans ton profil Coach.'], 401);
        }

        $account->forceFill([
            'last_seen_at' => now(),
            'app_version' => mb_substr((string) $request->header('X-App-Version', $account->app_version), 0, 30) ?: null,
        ])->save();
        $request->attributes->set('syncAccount', $account);

        return $next($request);
    }
}
