<?php

namespace App\Modules\Coach\Services;

use App\Models\User;
use App\Modules\Coach\Jobs\SyncGarminJob;
use App\Modules\Coach\Models\GarminAccount;
use App\Modules\Coach\Models\Profile;
use App\Modules\Coach\Models\StravaAccount;

/**
 * Parcours de démarrage affiché sur l'Accueil d'un nouvel athlète : profil, montre, puis premier échange avec le coach.
 */
class GettingStarted
{
    /**
     * @return array{profile: array{done: bool, missing: list<string>}, watch: array{state: string, detail: string}, ready: bool}
     */
    public function status(User $user): array
    {
        $profile = Profile::ownedBy($user)->first();
        $missing = array_keys(array_filter([
            'sexe' => ! $profile?->sex,
            'date de naissance' => ! $profile?->birth_date,
            'taille' => ! $profile?->height_cm,
            'disponibilités' => empty($profile?->availability),
        ]));
        $watch = $this->watch($user);

        return [
            'profile' => ['done' => $missing === [], 'missing' => $missing],
            'watch' => $watch,
            // Le coach part sur de bonnes bases : profil rempli et historique importé (ou pas de montre reliée).
            'ready' => $missing === [] && $watch['state'] !== 'pending',
        ];
    }

    /**
     * État de la montre : none (rien de relié), pending (import en cours), error, done.
     *
     * @return array{state: string, detail: string}
     */
    private function watch(User $user): array
    {
        $garmin = GarminAccount::ownedBy($user)->first();
        if (! $garmin) {
            return StravaAccount::ownedBy($user)->exists()
                ? ['state' => 'done', 'detail' => 'Strava relié : tes courses arrivent automatiquement.']
                : ['state' => 'none', 'detail' => 'Ton historique, tes meilleurs temps et tes zones cardiaques sont récupérés automatiquement. Facultatif, mais le coach en saura beaucoup plus.'];
        }

        $progress = $garmin->historyProgress();
        if (! $progress) {
            return $garmin->last_error
                ? ['state' => 'error', 'detail' => 'Synchronisation impossible : voir le détail dans ton profil.']
                : ['state' => 'pending', 'detail' => 'Première synchronisation en cours…'];
        }
        if (! $progress['done']) {
            return ['state' => 'pending', 'detail' => 'Import de ton historique : '.$progress['imported'].' / '.$progress['total'].' courses…'];
        }

        return ['state' => 'done', 'detail' => $progress['total'] > 0
            ? 'Historique importé : '.$progress['imported'].' course'.($progress['imported'] > 1 ? 's' : '').' sur '.SyncGarminJob::BACKFILL_YEARS.' ans.'
            : 'Montre reliée : aucune course sur les 2 dernières années, les prochaines arriveront à chaque synchronisation.'];
    }
}
