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
