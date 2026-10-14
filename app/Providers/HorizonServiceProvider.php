<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Accès au tableau de bord /horizon hors environnement local.
     *
     * Les comptes du hub sont créés à la main (inscription fermée) : tout utilisateur
     * connecté y a accès. Pour restreindre, lister les e-mails dans HORIZON_ADMINS.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user = null): bool {
            $admins = config('horizon.admins', []);

            return $user !== null && ($admins === [] || in_array($user->email, $admins, true));
        });
    }
}
