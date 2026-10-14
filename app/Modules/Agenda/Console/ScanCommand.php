<?php

namespace App\Modules\Agenda\Console;

use App\Modules\Agenda\Categories;
use App\Modules\Agenda\Services\ScanLauncher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agenda:scan {--filter=* : Filtres (sport, barca, musique, culturel, expos) ; tous par défaut} {--web : Recherche web libre au lieu des sources} {--force : Ignore la fréquence des sources et ré-analyse les pages inchangées} {--batch : Extraction via l\'API Batch d\'OpenAI (moitié prix, résultat sous 24 h)}')]
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

        $scan = $launcher->start($filters, $this->option('web') ? 'web' : 'sources', (bool) $this->option('force'), (bool) $this->option('batch'));

        if ($scan->status === 'failed') {
            $this->error($scan->error);

            return self::FAILURE;
        }

        if ($scan->mode === 'sources' && $scan->sources_total === 0) {
            $this->info('Rien à scanner : les '.$scan->sources_skipped.' sources ont été analysées récemment (--force pour ignorer).');

            return self::SUCCESS;
        }

        $this->info('Scan #'.$scan->id.' lancé'
            .($scan->sources_total ? ' ('.$scan->sources_total.' sources'.($scan->sources_skipped ? ', '.$scan->sources_skipped.' récentes ignorées' : '').')' : '')
            .($scan->batch ? ', extraction via l\'API Batch' : '').'.');

        return self::SUCCESS;
    }
}
