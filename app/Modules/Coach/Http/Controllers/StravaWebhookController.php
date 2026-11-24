<?php

namespace App\Modules\Coach\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coach\Jobs\ImportStravaActivityJob;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Models\StravaAccount;
use App\Modules\Coach\Services\Strava\StravaImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhook Strava (public, sans session) : validation de l'abonnement, puis événements
 * « activité créée / modifiée / supprimée » et « accès révoqué ».
 *
 * Strava ne signe pas ses événements : on vérifie l'identifiant d'abonnement, et chaque activité
 * est relue depuis l'API avec le jeton de son propriétaire (un faux événement ne peut rien injecter).
 * Strava attend une réponse en moins de 2 secondes : l'import se fait en file d'attente.
 */
class StravaWebhookController extends Controller
{
    /** Validation de l'abonnement : renvoyer le défi si le jeton de vérification est le bon. */
    public function verify(Request $request): JsonResponse
    {
        // PHP remplace les points des paramètres (« hub.mode ») par des soulignés.
        $token = (string) $request->query('hub_verify_token');
        $expected = (string) config('services.strava.webhook_verify_token');

        abort_unless($request->query('hub_mode') === 'subscribe' && $expected !== '' && hash_equals($expected, $token), 403);

        return response()->json(['hub.challenge' => (string) $request->query('hub_challenge')]);
    }

    public function handle(Request $request, StravaImporter $importer): Response
    {
        $subscription = config('services.strava.subscription_id');
        if (filled($subscription) && (string) $request->input('subscription_id') !== (string) $subscription) {
            return response()->noContent(403);
        }

        $account = StravaAccount::where('athlete_id', (int) $request->input('owner_id'))->first();
        if (! $account) {
            return response()->noContent(); // athlète inconnu : on accuse réception sans rien faire
        }

        $objectId = (int) $request->input('object_id');
        $aspect = $request->input('aspect_type');

        if ($request->input('object_type') === 'athlete' && $request->input('updates.authorized') === 'false') {
            $account->delete(); // l'athlète a retiré l'accès depuis Strava
        } elseif ($request->input('object_type') === 'activity' && in_array($aspect, ['create', 'update'], true)) {
            ImportStravaActivityJob::dispatch($account->id, $objectId, true);
        } elseif ($request->input('object_type') === 'activity' && $aspect === 'delete') {
            $activity = RunningActivity::ownedBy($account->user)->where('source', 'strava')->where('external_id', (string) $objectId)->first();
            $activity && $importer->forget($activity);
        }

        return response()->noContent();
    }
}
