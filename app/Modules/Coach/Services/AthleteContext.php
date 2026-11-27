<?php

namespace App\Modules\Coach\Services;

use App\Models\User;
use App\Modules\Coach\Models\Conversation;
use App\Modules\Coach\Models\DailyWellness;
use App\Modules\Coach\Models\Equipment;
use App\Modules\Coach\Models\Exercise;
use App\Modules\Coach\Models\Feedback;
use App\Modules\Coach\Models\GarminAccount;
use App\Modules\Coach\Models\Gear;
use App\Modules\Coach\Models\Goal;
use App\Modules\Coach\Models\Injury;
use App\Modules\Coach\Models\Memory;
use App\Modules\Coach\Models\PastRace;
use App\Modules\Coach\Models\Profile;
use App\Modules\Coach\Models\Proposal;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Models\RunningPlan;
use App\Modules\Coach\Models\RunningSession;
use App\Modules\Coach\Models\StravaAccount;
use App\Modules\Coach\Models\Unavailability;
use App\Modules\Coach\Models\WeightEntry;
use App\Modules\Coach\Models\Workout;
use App\Modules\Coach\Models\WorkoutSet;
use Illuminate\Support\Carbon;

/**
 * Fiche à jour de l'athlète, envoyée au coach dans la conversation quand elle a changé :
 * profil (physique, historique, blessures, disponibilités), objectifs, repères calculés, niveau et charge,
 * programme, séances, ressentis, fitness (si ouvert), alertes, cycle, mémoire, propositions.
 */
class AthleteContext
{
    public function __construct(
        private TrainingLoad $trainingLoad,
        private ReferencePaces $referencePaces,
        private CycleTracker $cycleTracker,
        private RunInsights $runInsights,
    ) {}

    public function build(User $user, Conversation $conversation): string
    {
        $profile = Profile::ownedBy($user)->first();

        $sections = [
            '# Fiche athlète à jour — '.$this->day(today()).' '.today()->format('Y'),
            $this->athlete($user, $profile),
            $this->background($user, $profile),
            $this->constraints($user, $profile),
            $this->cycle($user),
            $this->recovery($user),
            $this->goals($user),
            $this->references($user),
            $this->level($user),
            $this->plan($user),
            $this->sessions($user),
            $this->freeRuns($user),
            $this->fitness($user, $profile),
            $this->feedbacks($user),
            $this->signals($user),
            $this->missing($user, $profile),
            $this->memory($user),
            $this->proposals($user),
            $this->conversation($conversation),
        ];

        return implode("\n\n", array_filter($sections));
    }

    private function athlete(User $user, ?Profile $profile): string
    {
        $weights = WeightEntry::ownedBy($user)->orderByDesc('measured_on')->limit(30)->get();
        $latest = $weights->first();
        $monthAgo = $weights->first(fn (WeightEntry $entry) => $entry->measured_on->lte(today()->subDays(28)));

        $lines = ['## Athlète', '- Prénom : '.strtok($user->name, ' ')];
        $lines[] = '- Sexe : '.(Profile::SEXES[$profile?->sex] ?? 'non renseigné');
        $lines[] = '- Âge : '.($profile?->age() !== null ? $profile->age().' ans' : 'non renseigné');
        $lines[] = '- Taille : '.($profile?->height_cm ? $profile->height_cm.' cm' : 'non renseignée');
        if ($latest) {
            $trend = $monthAgo ? sprintf(' (%+.1f kg en un mois)', $latest->weight_kg - $monthAgo->weight_kg) : '';
            $lines[] = '- Poids : '.number_format($latest->weight_kg, 1, ',', '').' kg le '.$latest->measured_on->format('d/m').$trend;
        }
        $lines[] = '- FC repos / max : '.($profile?->resting_hr ?: '?').' / '.($profile?->max_hr ?: '?').($profile?->threshold_hr ? ' ; FC au seuil : '.$profile->threshold_hr : '');
        if ($profile?->vo2max) {
            $lines[] = '- VO2 max estimée par la montre : '.number_format($profile->vo2max, 0).' (le '.$profile->vo2max_on?->format('d/m').')';
        }

        return implode("\n", $lines);
    }

    private function background(User $user, ?Profile $profile): string
    {
        $races = PastRace::ownedBy($user)->orderByDesc('raced_on')->limit(15)->get();
        $injuries = Injury::ownedBy($user)->orderByDesc('current')->latest()->get();

        $lines = ['## Historique'];
        $lines[] = '- Court depuis : '.($profile?->running_since ? $profile->running_since.' ('.(today()->year - $profile->running_since).' ans)' : 'non renseigné');
        $lines[] = '- Courses passées : '.($races->isEmpty() ? 'aucune renseignée' : '');
        foreach ($races as $race) {
            $lines[] = '  - '.$race->label();
        }
        $lines[] = '- Blessures et fragilités : '.($injuries->isEmpty() ? 'aucune renseignée' : '');
        foreach ($injuries as $injury) {
            $lines[] = '  - '.$injury->label();
        }

        return implode("\n", $lines);
    }

    private function constraints(User $user, ?Profile $profile): string
    {
        $lines = ['## Contraintes de vie (les séances proposées doivent les respecter : l\'application refuse les autres)'];

        if (empty($profile?->availability)) {
            $lines[] = '- Disponibilités : non renseignées.';
        } else {
            $days = collect(Profile::DAYS)->map(function (string $label, string $key) use ($profile) {
                $day = $profile->dayAvailability($key);

                return $day['available']
                    ? '  - '.$label.' : oui'.($day['max_minutes'] ? ', '.$day['max_minutes'].' min max' : '').($day['slot'] ? ', '.mb_strtolower(Profile::SLOTS[$day['slot']] ?? $day['slot']) : '')
                    : '  - '.$label.' : non';
            });
            $lines[] = '- Disponibilités ('.collect(Profile::DAYS)->keys()->filter(fn (string $key) => $profile->dayAvailability($key)['available'])->count().' jours par semaine) :';
            $lines = array_merge($lines, $days->values()->all());
        }
        if ($profile?->availability_notes) {
            $lines[] = '- Info complémentaire de l\'athlète : « '.$profile->availability_notes.' »';
        }
        $lines[] = '- Jour de sortie longue : '.(Profile::DAYS[$profile?->long_run_day] ?? 'non précisé');
        $lines[] = '- Autres sports / travail physique : '.($profile?->other_activities ?: 'non renseigné');

        $unavailable = Unavailability::ownedBy($user)->where('ends_on', '>=', today())->orderBy('starts_on')->get();
        $lines[] = '- Indisponibilités à venir : '.($unavailable->isEmpty() ? 'aucune' : $unavailable->map(fn (Unavailability $period) => $period->label())->implode(' ; '));

        return implode("\n", $lines);
    }

    private function cycle(User $user): ?string
    {
        $status = $this->cycleTracker->status($user);
        if (! $status) {
            return null;
        }

        // Prévisions jusqu'à la course principale (au plus 26 semaines), sinon sur 12 semaines : pour placer les séances clés.
        $raceDay = Goal::ownedBy($user)->where('status', 'active')->where('priority', 'main')->whereDate('event_date', '>', today())->value('event_date');
        $horizon = $raceDay ? Carbon::parse($raceDay)->min(today()->addWeeks(26)) : today()->addWeeks(12);
        $forecast = collect($this->cycleTracker->forecast($user, today(), $horizon))->map(fn (array $cycle) => '  - Cycle '.($cycle['expected'] ? 'prévu ' : 'en cours, ').'du '.$cycle['start']->format('d/m').' : '
            .collect($cycle['phases'])->map(fn (array $dates, string $phase) => $phase.' '.$dates[0]->format('d/m').'–'.$dates[1]->format('d/m'))->implode(' · '));

        return implode("\n", array_filter([
            '## Cycle menstruel (suivi activé par l\'athlète)',
            '- Jour '.$status['day'].' : '.$status['phase'].($status['hormonal'] ? ' (contraception hormonale : pas de phases à interpréter)' : ''),
            '- Cycle moyen : '.$status['average_length'].' jours ; prochaines règles attendues vers le '.$status['next_expected']->format('d/m'),
            $status['late'] ? '- Règles en retard par rapport à la moyenne : ne pas en tirer de conclusion, écouter son ressenti.' : null,
            $forecast->isNotEmpty() ? '- Prévisions jusqu\'au '.$horizon->format('d/m/Y').' (estimation d\'après ses cycles passés, à confirmer par son ressenti ; '
                .'s\'en servir pour placer les séances clés avec souplesse, jamais pour imposer) :' : null,
            ...$forecast->all(),
        ]));
    }

    /** Récupération mesurée par la montre sur 7 jours, comparée aux 30 jours précédents. */
    private function recovery(User $user): ?string
    {
        $days = DailyWellness::ownedBy($user)->where('day', '>=', today()->subDays(37))->orderBy('day')->get();
        $week = $days->filter(fn (DailyWellness $day) => $day->day->isAfter(today()->subDays(8)));
        if ($week->isEmpty()) {
            return null;
        }

        $baselineHr = $days->reject(fn (DailyWellness $day) => $week->contains($day))->avg('resting_hr');
        $lines = ['## Récupération (montre, 7 derniers jours ; jour : FC repos · Body Battery min-max · stress · sommeil · HRV)'];
        foreach ($week as $day) {
            $lines[] = '- '.$this->day($day->day).' : '.collect([
                $day->resting_hr ? 'FC repos '.$day->resting_hr : null,
                $day->body_battery_max !== null ? 'BB '.$day->body_battery_min.'-'.$day->body_battery_max : null,
                $day->stress_avg !== null ? 'stress '.$day->stress_avg : null,
                $day->sleepLabel() ? 'sommeil '.$day->sleepLabel().($day->sleep_score ? ' (score '.$day->sleep_score.')' : '') : null,
                $day->hrv_last_night ? 'HRV '.$day->hrv_last_night.($day->hrv_weekly_avg ? ' (moy. 7 j '.$day->hrv_weekly_avg.')' : '').($day->hrv_status ? ' '.$day->hrv_status : '') : null,
            ])->filter()->implode(' · ');
        }
        if ($baselineHr) {
            $lines[] = '- FC de repos de référence (30 jours d\'avant) : '.round($baselineHr);
        }
        $lines[] = 'Le sommeil et la HRV n\'existent que les nuits où l\'athlète dort avec sa montre : leur absence n\'est pas un signal.';

        return implode("\n", $lines);
    }

    private function goals(User $user): string
    {
        $goals = Goal::ownedBy($user)->where('status', 'active')->orderByRaw("case when priority = 'main' then 0 else 1 end")->orderBy('event_date')->get();
        if ($goals->isEmpty()) {
            return "## Objectifs\nAucun objectif fixé pour l'instant.";
        }

        return "## Objectifs\n".$goals->map(fn (Goal $goal) => '- '.(Goal::PRIORITIES[$goal->priority] ?? $goal->priority).' : '.$goal->title
            .($goal->event_name ? ' — '.$goal->event_name : '')
            .($goal->event_date ? ' — '.$this->day($goal->event_date).' '.$goal->event_date->format('Y').($goal->daysLeft() !== null ? ' (J-'.$goal->daysLeft().')' : ' (passée)') : '')
            .($goal->distance_km ? ' — '.$goal->distance_km.' km' : '')
            .($goal->targetLabel() ? ' — objectif : '.$goal->targetLabel() : '')
            .($goal->notes ? "\n  Notes : ".$goal->notes : ''))->implode("\n");
    }

    private function references(User $user): string
    {
        $references = $this->referencePaces->forUser($user);
        $lines = ['## Repères calculés par l\'application (utilise-les pour les allures et les zones, ne les invente pas)'];

        if ($references['vdot']) {
            $lines[] = '- VDOT '.number_format($references['vdot'], 1, ',', '').' d\'après la '.$references['source'];
            $lines[] = '- Allures : '.collect($references['paces'])->map(fn (array $pace) => $pace['label'].' '.Duration::format($pace['from']).'-'.Duration::format($pace['to']).' /km')->implode(' ; ');
            $lines[] = '- Temps prévisibles à ce niveau : '.collect($references['predictions'])->map(fn (int $seconds, string $label) => $label.' '.Duration::format($seconds))->implode(' ; ');
        } else {
            $lines[] = '- Pas de VDOT : aucune course récente ni test. Propose un test (demi-Cooper 6 min, 5 km chrono ou 30 min à fond) ou demande des courses passées.';
        }
        $lines[] = $references['zones']
            ? '- Zones cardiaques ('.$references['zones_basis'].') : '.collect($references['zones'])->map(fn (array $zone, string $label) => $label.' '.$zone['from'].'-'.$zone['to'])->implode(' ; ')
            : '- Zones cardiaques : FC max inconnue.';

        return implode("\n", $lines);
    }

    private function level(User $user): string
    {
        $summary = $this->trainingLoad->summary($user);
        $weeks = $summary['weeks'];
        $last8 = $weeks->slice(-9, 8); // 8 semaines complètes avant la semaine en cours

        return implode("\n", array_filter([
            '## Niveau et charge (calculés)',
            '- 12 dernières semaines (km / heures / sorties), de la plus ancienne à la semaine en cours : '
                .$weeks->map(fn (array $week) => $week['km'].'/'.$week['hours'].'h/'.$week['runs'])->implode(' · '),
            sprintf('- Moyenne des 8 semaines complètes : %.1f km, %.1f h, %.1f sorties par semaine', $last8->avg('km'), $last8->avg('hours'), $last8->avg('runs')),
            '- Régularité : '.$summary['active_weeks'].' semaines courues sur 12, '.$summary['streak'].' semaines d\'affilée sans trou',
            '- Plus longue sortie (12 semaines) : '.($summary['longest'] ? $summary['longest']->metrics().' le '.$summary['longest']->started_at->format('d/m') : 'aucune'),
            '- Ratio aigu/chronique : charge '.$this->trainingLoad->formatRatio($summary['load_ratio']).', volume '.$this->trainingLoad->formatRatio($summary['volume_ratio'])
                .' (vigilance > '.TrainingLoad::RATIO_WATCH.', alerte > '.TrainingLoad::RATIO_ALERT.')',
            '- Part d\'intensité sur 28 jours : '.($summary['hard_share'] !== null ? round($summary['hard_share'] * 100).' % (cible ~20 %)' : 'pas assez de courses classées'),
            $this->aerobicTrend($user),
            $this->gear($user),
            $this->strava($user),
        ]));
    }

    /** Allure en zone 2 (endurance) : récente contre 5 à 12 semaines plus tôt. Plus rapide à FC égale = progrès. */
    private function aerobicTrend(User $user): string
    {
        $runs = RunningActivity::ownedBy($user)->where('started_at', '>=', today()->subWeeks(12))->whereNotNull('analysis')->get(['started_at', 'analysis'])
            ->filter(fn (RunningActivity $run) => isset($run->analysis['z2_pace']));
        $recent = $runs->filter(fn (RunningActivity $run) => $run->started_at->isAfter(today()->subWeeks(4)))->avg(fn (RunningActivity $run) => $run->analysis['z2_pace']);
        $before = $runs->filter(fn (RunningActivity $run) => ! $run->started_at->isAfter(today()->subWeeks(4)))->avg(fn (RunningActivity $run) => $run->analysis['z2_pace']);

        if (! $recent) {
            return '- Allure en zone 2 : pas encore de données cardiaques (Strava).';
        }

        return '- Allure en zone 2 (4 dernières semaines) : '.Duration::format((int) round($recent)).' /km'
            .($before ? ' contre '.Duration::format((int) round($before)).' /km les 8 semaines d\'avant' : '');
    }

    private function gear(User $user): ?string
    {
        $shoes = Gear::ownedBy($user)->where('retired', false)->orderByDesc('primary')->get();

        return $shoes->isEmpty() ? null : '- Chaussures (Strava) : '.$shoes->map(fn (Gear $gear) => $gear->name.' '.$gear->km().' km'.($gear->primary ? ' (principales)' : ''))->implode(' ; ');
    }

    private function strava(User $user): string
    {
        $sources = array_keys(array_filter([
            'Garmin' => GarminAccount::ownedBy($user)->exists(),
            'Strava' => StravaAccount::ownedBy($user)->exists(),
        ]));

        $feelings = 'Le ressenti (effort, fatigue, sommeil, douleur avec zone et intensité) est saisi par l\'athlète dans une carte rapide après chaque course.';

        return $sources
            ? '- '.implode(' et ', $sources).' relié : chaque course arrive automatiquement et valide la séance prévue du jour. Tu reçois au débrief distance, durée, allure, '
                .'FC moyenne et max, FC et allure par tour (par km), cadence, dénivelé, dérive cardiaque, temps par zone, écart à la cible'
                .(in_array('Garmin', $sources, true) ? ', dynamique de course et effet d\'entraînement ; et chaque jour la récupération (voir plus bas)' : '').'. '
                .$feelings.' Ne demande donc jamais à l\'athlète de relever ou de noter ces données, ni de valider la séance : décris seulement quoi faire.'
            : '- Aucune montre reliée : l\'athlète saisit ses courses à la main (distance, durée, FC moyenne et max, dénivelé), sans données par tour ni flux cardiaque. '
                .$feelings.' Ne lui demande pas d\'autres relevés ; propose-lui plutôt d\'ajouter sa clé Garmin dans son profil.';
    }

    private function plan(User $user): string
    {
        $plan = RunningPlan::ownedBy($user)->where('status', 'active')->latest()->first();
        if (! $plan) {
            return "## Programme\nAucun programme en cours.";
        }

        return implode("\n", array_filter([
            '## Programme en cours (depuis le '.$plan->created_at->format('d/m').')',
            '- Faisabilité : '.(RunningPlan::FEASIBILITY[$plan->feasibility] ?? 'non évaluée'),
            '- Résumé : '.$plan->summary,
            $plan->rationale ? '- Justification : '.$plan->rationale : null,
        ]));
    }

    private function sessions(User $user): string
    {
        $sessions = RunningSession::ownedBy($user)
            ->with(['activity' => fn ($query) => $query->summary()])
            ->whereBetween('scheduled_on', [today()->subDays(14), today()->addDays(21)])
            ->orderBy('scheduled_on')
            ->get();

        if ($sessions->isEmpty()) {
            return "## Séances (14 jours passés, 21 à venir)\nAucune séance au calendrier.";
        }

        $lines = $sessions->map(function (RunningSession $session) {
            $status = match ($session->status) {
                'done' => 'FAITE'.($session->activity ? ' → '.$session->activity->metrics() : ''),
                'cancelled' => 'ANNULÉE'.($session->cancel_reason ? ' (« '.$session->cancel_reason.' »)' : ''),
                default => $session->scheduled_on->isBefore(today()) ? 'NON FAITE (ni validée ni annulée)' : 'prévue',
            };

            return '- [#'.$session->id.'] '.$this->day($session->scheduled_on).' · '.$session->typeLabel().' · '.$session->title
                .($session->targets() ? ' · cible '.$session->targets() : '').' — '.$status;
        });

        return "## Séances (14 jours passés, 21 à venir) — [#id] à utiliser pour les ajustements\n".$lines->implode("\n");
    }

    private function freeRuns(User $user): ?string
    {
        $runs = RunningActivity::ownedBy($user)->summary()->whereNull('session_id')->where('started_at', '>=', today()->subDays(28))->orderBy('started_at')->get();

        if ($runs->isEmpty()) {
            return null;
        }

        return "## Courses hors programme (28 jours)\n".$runs->map(fn (RunningActivity $run) => '- '.$this->day($run->started_at).' · '.$run->metrics().($run->notes ? ' · « '.$run->notes.' »' : ''))->implode("\n");
    }

    private function feedbacks(User $user): ?string
    {
        $feedbacks = Feedback::ownedBy($user)->with(['activity' => fn ($query) => $query->summary()])->where('created_at', '>=', today()->subDays(28))->orderBy('created_at')->get();

        if ($feedbacks->isEmpty()) {
            return null;
        }

        return "## Ressentis (28 jours)\n".$feedbacks->map(function (Feedback $feedback) {
            $scores = $feedback->scoresLabel();
            $pains = $feedback->painsLabel();
            $about = $feedback->activity ? 'course du '.$feedback->activity->started_at->format('d/m') : 'le '.$feedback->created_at->format('d/m');

            return '- '.$about.($feedback->source === 'form' ? ' (saisie rapide)' : ' (débrief)').' : '
                .collect([$feedback->summary, $scores ? '('.$scores.')' : null, $pains ? 'douleurs : '.$pains : null])->filter()->implode(' ');
        })->implode("\n");
    }

    private function signals(User $user): string
    {
        $signals = $this->trainingLoad->signals($user);

        return "## Alertes calculées (à respecter)\n".($signals ? collect($signals)->map(fn (string $signal) => '- ⚠ '.$signal)->implode("\n") : '- Aucune.');
    }

    /** Champs du profil encore vides : à demander dans la conversation (puis enregistrer avec update_athlete_facts). */
    private function missing(User $user, ?Profile $profile): ?string
    {
        $missing = array_keys(array_filter([
            'sexe' => ! $profile?->sex,
            'date de naissance' => ! $profile?->birth_date,
            'taille' => ! $profile?->height_cm,
            'poids' => WeightEntry::ownedBy($user)->doesntExist(),
            'ancienneté en course' => ! $profile?->running_since,
            'courses passées et temps' => PastRace::ownedBy($user)->doesntExist(),
            'disponibilités par jour' => empty($profile?->availability),
            'jour de sortie longue' => ! $profile?->long_run_day,
            'autres sports / travail physique' => ! $profile?->other_activities,
            'objectif' => Goal::ownedBy($user)->where('status', 'active')->doesntExist(),
        ]));

        return $missing ? "## Profil incomplet\nÀ demander au fil de la conversation (sans questionnaire) : ".implode(', ', $missing).'. Les blessures passées sont à demander aussi si rien n\'est renseigné.' : null;
    }

    /** Salle, catalogue et séances de fitness, pour un compte à qui le Fitness est ouvert. */
    private function fitness(User $user, ?Profile $profile): ?string
    {
        if (! $user->hasModuleFeature('coach', 'fitness')) {
            return null;
        }

        $lines = ['## Fitness'];
        $lines[] = '- Objectif : '.(Profile::FITNESS_GOALS[$profile?->fitness_goal] ?? 'non renseigné')
            .' ; niveau : '.(Profile::FITNESS_LEVELS[$profile?->fitness_level] ?? 'non renseigné')
            .' ; '.($profile?->fitness_sessions_per_week ? $profile->fitness_sessions_per_week.' séance(s) par semaine' : 'fréquence non renseignée')
            .' ; '.($profile?->fitness_session_minutes ? $profile->fitness_session_minutes.' min par séance' : 'durée non renseignée');

        $equipment = Equipment::ownedBy($user)->orderBy('name')->get()->groupBy('status');
        $lines[] = '- Machines disponibles : '.(($equipment['available'] ?? collect())->map(fn (Equipment $item) => $item->name.($item->load_step_kg ? ' (pas de '.WorkoutSet::kg($item->load_step_kg).')' : ''))->implode(', ') ?: 'aucune confirmée pour l\'instant');
        $lines[] = '- Pas dans sa salle (ne pas proposer) : '.(($equipment['missing'] ?? collect())->pluck('name')->implode(', ') ?: 'rien de noté');

        $exercises = Exercise::ownedBy($user)->with('equipment')->orderBy('name')->get();
        $lines[] = '- Catalogue d\'exercices ([#id] pour record_workout) : '.($exercises->isEmpty() ? 'vide (commence par propose_exercises)' : '');
        foreach ($exercises as $exercise) {
            $lines[] = '  - [#'.$exercise->id.'] '.$exercise->name.' · '.($exercise->equipment?->name ?? 'poids du corps').' · '.mb_strtolower(Exercise::MEASURES[$exercise->measure])
                .' · '.$exercise->musclesLabel().($exercise->unilateral ? ' · unilatéral' : '');
        }

        $workouts = Workout::ownedBy($user)->with('sets.exercise')->where('performed_on', '>=', today()->subDays(28))->orderBy('performed_on')->get();
        $lines[] = '- Séances des 4 dernières semaines : '.($workouts->isEmpty() ? 'aucune' : '');
        foreach ($workouts as $workout) {
            $lines[] = '  - [#'.$workout->id.'] '.$workout->summary();
        }

        $weekSets = $workouts->filter(fn (Workout $workout) => $workout->performed_on->gte(today()->subDays(6)))
            ->flatMap(fn (Workout $workout) => $workout->sets)
            ->flatMap(fn (WorkoutSet $set) => $set->exercise->muscles ?? [])
            ->countBy();
        if ($weekSets->isNotEmpty()) {
            $lines[] = '- Séries par muscle sur 7 jours : '.collect(Exercise::MUSCLES)->filter(fn (string $label, string $key) => $weekSets->has($key))
                ->map(fn (string $label, string $key) => $label.' '.$weekSets[$key])->implode(', ');
        }

        return implode("\n", $lines);
    }

    private function memory(User $user): string
    {
        $memory = Memory::ownedBy($user)->value('content');

        return "## Ta mémoire de l'athlète (outil update_memory pour la tenir à jour)\n".($memory ?: 'Vide pour l\'instant.');
    }

    private function proposals(User $user): ?string
    {
        $proposals = Proposal::ownedBy($user)->where('updated_at', '>=', today()->subDays(14))->orderBy('id')->get();

        if ($proposals->isEmpty()) {
            return null;
        }

        $statuses = ['pending' => 'en attente de réponse', 'accepted' => 'ACCEPTÉE', 'refused' => 'REFUSÉE'];

        $kinds = ['plan' => 'programme', 'adjustment' => 'ajustement', 'exercises' => 'exercices'];
        $answers = ['have' => 'il l\'a, ajouté', 'missing' => 'machine absente', 'declined' => 'écarté'];
        // Exercices proposés : la réponse de chacun (sans réponse tant qu'il n'a pas cliqué).
        $exerciseAnswers = fn (Proposal $proposal) => $proposal->kind !== 'exercises' ? '' : ' ('.collect($proposal->payload['items'] ?? [])
            ->map(fn (array $item) => $item['name'].' : '.($answers[$item['answer'] ?? ''] ?? 'sans réponse'))->implode(', ').')';

        return "## Tes propositions récentes\n".$proposals->map(fn (Proposal $proposal) => '- #'.$proposal->id.' ('.($kinds[$proposal->kind] ?? $proposal->kind).', '.$proposal->created_at->format('d/m').') : '.$proposal->reason.' — '.$statuses[$proposal->status].$exerciseAnswers($proposal))->implode("\n");
    }

    private function conversation(Conversation $conversation): string
    {
        $lines = ['## Cette conversation : '.$conversation->kindLabel()];

        if ($conversation->kind === 'onboarding') {
            $lines[] = 'Premier échange. Appuie-toi sur ce que l\'athlète a déjà rempli dans son profil, ne le redemande pas. '
                .'Fais connaissance (motivation, doutes, ce que « réussir » veut dire), complète le profil incomplet au fil de la discussion (update_athlete_facts), '
                .'puis fixe l\'objectif principal (set_goal) et les éventuelles courses intermédiaires. Ensuite, si les repères manquent pour juger la faisabilité, '
                .'propose 2 ou 3 séances de test (propose_adjustment, type « test ») ; sinon propose directement le programme (propose_plan), avec un avis honnête sur la faisabilité '
                .'en comparant le temps visé aux temps prévisibles.';
        }

        if ($conversation->kind === 'debrief' && $activity = $conversation->activity) {
            $lines[] = 'Débrief de la course du '.$this->day($activity->started_at).' : '.$activity->metrics().'.';
            if ($session = $activity->session) {
                $lines[] = 'Séance prévue : '.$session->typeLabel().' · '.$session->title.($session->targets() ? ' · cible '.$session->targets() : '').($session->description ? ' — '.$session->description : '');
            } else {
                $lines[] = 'Course hors programme.';
            }
            if ($activity->notes) {
                $lines[] = 'Note de l\'athlète : « '.$activity->notes.' »';
            }
            $night = DailyWellness::measuredNights($conversation->user, [$activity->started_at])->first();
            if ($night) {
                $lines[] = 'Sommeil de la nuit précédente, mesuré par la montre : '.$night->sleepLabel()
                    .($night->sleep_score ? ' (score '.$night->sleep_score.')' : '').($night->deep_sleep_seconds ? ', dont '.round($night->deep_sleep_seconds / 60).' min de sommeil profond' : '')
                    .'. Ne demande pas la durée ; seulement la qualité ressentie si la séance s\'est mal passée.';
            }
            if ($quick = Feedback::where('activity_id', $activity->id)->where('source', 'form')->latest()->first()) {
                $lines[] = 'Ressenti déjà saisi : '.$quick->scoresLabel().($quick->pains ? ', douleurs : '.$quick->painsLabel() : '').'. Ne le redemande pas, creuse-le.';
            }
            array_push($lines, ...$this->runInsights->coachReading($activity, withSleep: ! $night));
            $lines[] = 'Commence par une lecture courte des métriques (écarts avec la cible, points positifs), puis pose tes questions une ou deux à la fois : '
                .'sensations, douleurs, moral, sommeil, contexte. Quand tu as assez d\'éléments, enregistre le ressenti (record_feedback), '
                .'puis rédige le bilan de la séance (write_session_review) en croisant chiffres, ressenti et contexte du jour, '
                .'et propose un ajustement seulement s\'il est utile.';
        }

        if ($conversation->kind === 'cancellation' && $session = $conversation->session) {
            $lines[] = 'Séance annulée : '.$this->day($session->scheduled_on).' · '.$session->typeLabel().' · '.$session->title
                .($session->cancel_reason ? ' — raison donnée : « '.$session->cancel_reason.' »' : ' — sans raison donnée');
            $lines[] = 'Comprends pourquoi sans culpabiliser (fatigue, douleur, emploi du temps, motivation), enregistre ce qui compte (record_feedback), '
                .'puis propose de replacer ou d\'alléger la suite si besoin, en gardant l\'objectif final en vue.';
        }

        return implode("\n", $lines);
    }

    /** « lun. 16/11 » */
    private function day(Carbon $date): string
    {
        return $date->locale('fr')->isoFormat('ddd DD/MM');
    }
}
