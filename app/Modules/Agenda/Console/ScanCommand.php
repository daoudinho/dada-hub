<?php

namespace App\Modules\Agenda\Console;

use App\Modules\Agenda\Categories;
use App\Modules\Agenda\Services\ScanLauncher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agenda:scan {--filter=* : Filtres (sport, barca, musique, culturel, expos) ; tous par défaut} {--web : Recherche web libre au lieu des sources} {--force : Ré-analyse aussi les pages inchangées}')]
#[Description('Lance un scan de l\'agenda (les jobs sont traités par « php artisan queue:work »)')]
class ScanCommand extends Command
{
    public function handle(ScanLauncher $launcher): int
    {
        $filters = $this->option('filter') ?: array_keys(Categories::FILTERS);
        $unknown = array_diff($filters, array_keys(Categories::FILTERS));
        if ($unknown !== []) {
            $this->error('Filtre inconnu : '.implode(', ', $unknown));

            return self::FAILURE;
        }

        if ($running = $launcher->running()) {
            $this->warn('Un scan est déjà en cours (#'.$running->id.').');

            return self::SUCCESS;
        }

        $scan = $launcher->start($filters, $this->option('web') ? 'web' : 'sources', (bool) $this->option('force'));

        if ($scan->status === 'failed') {
            $this->error($scan->error);

            return self::FAILURE;
        }

        $this->info('Scan #'.$scan->id.' lancé'.($scan->sources_total ? ' ('.$scan->sources_total.' sources)' : '').'.');

        return self::SUCCESS;
    }
}
