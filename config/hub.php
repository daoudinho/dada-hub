<?php

use App\Modules\Agenda\AgendaModule;

return [

    /*
    |--------------------------------------------------------------------------
    | Inscription publique
    |--------------------------------------------------------------------------
    |
    | Désactivée par défaut : les comptes se créent avec "php artisan hub:user".
    | Passer HUB_REGISTRATION=true pour ouvrir la page /register.
    |
    */

    'registration' => (bool) env('HUB_REGISTRATION', false),

    /*
    |--------------------------------------------------------------------------
    | Premier compte
    |--------------------------------------------------------------------------
    |
    | Créé par « php artisan db:seed » s'il n'existe pas encore (jamais modifié
    | ensuite). Pratique sur un hébergeur sans accès au terminal, comme Render.
    |
    */

    'admin' => [
        'email' => env('HUB_ADMIN_EMAIL'),
        'password' => env('HUB_ADMIN_PASSWORD'),
        'name' => env('HUB_ADMIN_NAME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sous-projets du hub
    |--------------------------------------------------------------------------
    |
    | Chaque entrée est la classe principale d'un module (app/Modules/{Nom}).
    | Retirer une ligne désactive le module : ses routes et sa tuile disparaissent.
    |
    */

    'modules' => [
        AgendaModule::class,
    ],

];
