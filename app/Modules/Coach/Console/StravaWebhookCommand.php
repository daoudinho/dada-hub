<?php

namespace App\Modules\Coach\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

#[Signature('coach:strava-webhook {--create : Abonne l\'application aux événements Strava} {--delete= : Supprime l\'abonnement de cet identifiant}')]
#[Description('Affiche, crée ou supprime l\'abonnement au webhook Strava (une seule par application)')]
class StravaWebhookCommand extends Command
{
    private const URL = 'https://www.strava.com/api/v3/push_subscriptions';

    public function handle(): int
    {
        $credentials = ['client_id' => config('services.strava.client_id'), 'client_secret' => config('services.strava.client_secret')];
        if (blank($credentials['client_id']) || blank($credentials['client_secret'])) {
            $this->error('Renseigne STRAVA_CLIENT_ID et STRAVA_CLIENT_SECRET.');

            return self::FAILURE;
        }

        if ($this->option('delete')) {
            $response = Http::delete(self::URL.'/'.$this->option('delete').'?'.http_build_query($credentials));
            $response->successful() ? $this->info('Abonnement supprimé.') : $this->error('Échec : '.$response->body());

            return $response->successful() ? self::SUCCESS : self::FAILURE;
        }

        if ($this->option('create')) {
            if (blank(config('services.strava.webhook_verify_token'))) {
                $this->error('Renseigne STRAVA_WEBHOOK_VERIFY_TOKEN (une chaîne au hasard).');

                return self::FAILURE;
            }
            $callback = route('coach.strava.webhook.verify');
            if (! str_starts_with($callback, 'https://')) {
                $this->error('Strava doit joindre le site en HTTPS depuis Internet : '.$callback.' ne convient pas (APP_URL).');

                return self::FAILURE;
            }

            // Strava appelle aussitôt l'URL pour vérifier le jeton : le site doit être en ligne avec la même configuration.
            $response = Http::asForm()->timeout(30)->post(self::URL, $credentials + [
                'callback_url' => $callback,
                'verify_token' => config('services.strava.webhook_verify_token'),
            ]);
            if ($response->failed()) {
                $this->error('Échec : '.$response->body());

                return self::FAILURE;
            }
            $this->info('Abonnement créé. Ajoute dans l\'environnement du serveur : STRAVA_SUBSCRIPTION_ID='.$response->json('id'));

            return self::SUCCESS;
        }

        $subscriptions = Http::get(self::URL, $credentials)->json() ?? [];
        if (! $subscriptions) {
            $this->line('Aucun abonnement. Crée-le avec --create depuis le serveur en ligne.');
        }
        foreach ($subscriptions as $subscription) {
            $this->line('#'.$subscription['id'].' → '.$subscription['callback_url']);
        }

        return self::SUCCESS;
    }
}
