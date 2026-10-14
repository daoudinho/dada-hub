<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

// use Illuminate\Support\Facades\Schedule; // à décommenter avec les tâches planifiées ci-dessous

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tâches planifiées (équivalent de l'ancien app/Console/Kernel.php)
|--------------------------------------------------------------------------
|
| Exécutées par « php artisan schedule:work » (local) ou par une tâche cron
| appelant « php artisan schedule:run » chaque minute (production).
| Vérifier la liste : php artisan schedule:list
|
*/

// Horizon (production) : métriques des files pour les graphiques du tableau de bord.
// Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Agenda : scan des sources chaque lundi à 6 h (heure de Barcelone).
// Schedule::command('agenda:scan')
//     ->weeklyOn(1, '06:00')
//     ->timezone('Europe/Madrid')
//     ->withoutOverlapping();
