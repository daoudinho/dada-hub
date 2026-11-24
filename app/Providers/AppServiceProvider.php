<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Derrière le proxy HTTPS de Render : sans cela, les URL des assets et des formulaires
        // seraient générées en http (CSS/JS bloqués par le navigateur).
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Gestion des comptes, de leurs rôles et de leurs accès aux modules.
        Gate::define('admin', fn (User $user) => $user->isAdmin());
    }
}
