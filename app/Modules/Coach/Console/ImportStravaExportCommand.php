<?php

namespace App\Modules\Coach\Console;

use App\Models\User;
use App\Modules\Coach\Services\SamsungHealth\SamsungHealthImporter;
use App\Modules\Coach\Services\Strava\StravaExport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('coach:import-strava-export {path : export Strava (.zip ou dossier décompressé)} {user : identifiant ou e-mail de l\'athlète} {--timezone=Europe/Paris : fuseau des courses (le CSV et les fichiers sont en UTC)}')]
#[Description('Importe l\'historique des courses d\'un export de compte Strava, sans débrief ni annonce de record ; une course déjà présente à la même heure est complétée')]
class ImportStravaExportCommand extends Command
{
    public function handle(SamsungHealthImporter $importer): int
    {
        $user = User::where('email', $this->argument('user'))->orWhere('id', $this->argument('user'))->first();
        if (! $user) {
            $this->error('Athlète introuvable : '.$this->argument('user'));

            return self::FAILURE;
        }

        try {
            $export = new StravaExport($this->argument('path'));
            $rows = $export->runRows();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $counts = ['created' => 0, 'merged' => 0, 'updated' => 0];
        $this->withProgressBar($rows, function (array $row) use ($export, $importer, $user, &$counts) {
            $run = $export->run($row, $this->option('timezone'));
            $counts[$importer->import($user, $run, notify: false, source: 'strava')['status']]++;
        });
        $this->newLine();

        $this->info(sprintf('%d course(s) importée(s), %d complétée(s) (déjà présentes), %d mise(s) à jour ; %d activité(s) ignorée(s) (autres sports, faux départs).',
            $counts['created'], $counts['merged'], $counts['updated'], $export->skipped));

        return self::SUCCESS;
    }
}
