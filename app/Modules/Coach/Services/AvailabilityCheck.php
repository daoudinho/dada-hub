<?php

namespace App\Modules\Coach\Services;

use App\Models\User;
use App\Modules\Coach\Models\Profile;
use App\Modules\Coach\Models\Unavailability;
use Illuminate\Support\Carbon;

/**
 * Garde-fou : une séance proposée doit tomber un jour disponible, dans la durée maximale du jour,
 * et hors des périodes où l'athlète ne peut pas courir.
 */
class AvailabilityCheck
{
    /**
     * Problèmes trouvés, phrases prêtes à renvoyer au coach (vide : tout va bien).
     *
     * @param  iterable<array{date: string, duration_minutes?: ?int, title?: ?string}>  $sessions
     * @return list<string>
     */
    public function violations(User $user, iterable $sessions): array
    {
        $profile = Profile::ownedBy($user)->first();
        $blocked = Unavailability::ownedBy($user)->where('kind', 'none')->where('ends_on', '>=', today())->get();
        $problems = [];

        foreach ($sessions as $session) {
            $date = Carbon::parse($session['date']);
            $name = ($session['title'] ?? 'Séance').' du '.$date->locale('fr')->isoFormat('ddd D/MM');
            $day = $profile?->dayAvailability(Profile::dayKey($date));

            if ($day && ! $day['available']) {
                $problems[] = $name.' : l\'athlète ne court pas le '.mb_strtolower(Profile::DAYS[Profile::dayKey($date)]).'.';
            } elseif ($day && $day['max_minutes'] && ($session['duration_minutes'] ?? 0) > $day['max_minutes']) {
                $problems[] = $name.' : '.$session['duration_minutes'].' min prévues, '.$day['max_minutes'].' min au plus ce jour-là.';
            }

            if ($period = $blocked->first(fn (Unavailability $unavailability) => $unavailability->covers($date))) {
                $problems[] = $name.' : l\'athlète est indisponible '.$period->label().'.';
            }
        }

        return $problems;
    }
}
