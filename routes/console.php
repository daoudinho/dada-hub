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

// Agenda : scan des sources chaque lundi à 6 h (heure de Barcelone), extraction via l'API Batch (moitié prix).
// Schedule::command('agenda:scan --batch')
//     ->weeklyOn(1, '06:00')
//     ->timezone('Europe/Madrid')
//     ->withoutOverlapping();
