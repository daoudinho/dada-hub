<?php

namespace App\Modules\Coach\Services;

use App\Models\User;
use App\Modules\Coach\Jobs\ImportGarminActivityJob;
use App\Modules\Coach\Jobs\ImportStravaActivityJob;
use App\Modules\Coach\Models\BestEffort;
use App\Modules\Coach\Models\Conversation;
use App\Modules\Coach\Models\Exercise;
use App\Modules\Coach\Models\Feedback;
use App\Modules\Coach\Models\GarminAccount;
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
use App\Modules\Coach\Models\Workout;
use App\Modules\Coach\Services\Garmin\GarminImporter;
use App\Modules\Coach\Services\Garmin\GarminUnavailable;
use App\Modules\Coach\Services\Strava\StravaImporter;
use App\Modules\Coach\Services\Strava\StravaRateLimited;
use App\Modules\Coach\Services\Strava\StravaUnavailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Outils que le coach peut appeler. Les programmes et ajustements ne sont que des propositions :
 * l'athlète les accepte ou les refuse dans le chat (PlanApplier).
 */
class CoachTools
{
    /** Nombre maximal de nouvelles courses Strava importées immédiatement ; au-delà, en arrière-plan. */
    private const FETCH_NOW_MAX = 5;

    /** Écart entre deux imports en arrière-plan (2 requêtes Strava chacun, limite de 100 par quart d'heure). */
    private const FETCH_SPACING_SECONDS = 20;

    /** Outils réservés aux comptes à qui le Fitness est ouvert. */
    private const FITNESS_TOOLS = ['propose_exercises', 'record_workout', 'get_fitness_history'];

    public function __construct(
        private AvailabilityCheck $availability,
        private RunInsights $runInsights,
        private GymCatalog $gym,
        private WorkoutRecorder $workouts,
    ) {}

    /**
     * Définitions envoyées à l'API (ordre fixe : il fait partie du préfixe mis en cache), outils fitness en dernier.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(bool $fitness = false): array
    {
        return $fitness ? [...$this->runningDefinitions(), ...$this->fitnessDefinitions()] : $this->runningDefinitions();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runningDefinitions(): array
    {
        $session = [
            'type' => 'object',
            'properties' => [
                'date' => ['type' => 'string', 'description' => 'Date AAAA-MM-JJ, aujourd\'hui ou plus tard.'],
                'type' => ['type' => 'string', 'enum' => array_keys(RunningSession::TYPES), 'description' => 'easy=footing, long=sortie longue, intervals=fractionné, tempo=seuil, recovery=récupération, test, race=course.'],
                'title' => ['type' => 'string', 'description' => 'Titre court : « 10 × 400 m », « Footing en aisance ».'],
                'description' => ['type' => 'string', 'description' => 'Déroulé : échauffement, blocs, récupérations, retour au calme, consignes.'],
                'duration_minutes' => ['type' => 'integer'],
                'distance_km' => ['type' => 'number'],
                'pace_from' => ['type' => 'string', 'description' => 'Allure cible la plus rapide, « m:ss » par km (ex. « 5:30 »), tirée des repères calculés.'],
                'pace_to' => ['type' => 'string', 'description' => 'Allure cible la plus lente, « m:ss » par km (ex. « 5:45 »).'],
                'hr_from' => ['type' => 'integer', 'description' => 'FC cible basse (bpm), tirée des zones calculées.'],
                'hr_to' => ['type' => 'integer', 'description' => 'FC cible haute (bpm).'],
            ],
            'required' => ['date', 'type', 'title'],
        ];

        return [
            [
                'name' => 'set_goal',
                'description' => 'Enregistre un objectif validé avec l\'athlète. Une course principale remplace la précédente ; '
                    .'une course intermédiaire (préparation, test grandeur nature) s\'ajoute aux autres.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Ex. « Semi-marathon de Paris en 1h45 ».'],
                        'event_name' => ['type' => 'string', 'description' => 'Nom de la course précédé de son article, ex. « le semi-marathon de Paris ».'],
                        'event_date' => ['type' => 'string', 'description' => 'AAAA-MM-JJ'],
                        'distance_km' => ['type' => 'number'],
                        'target_time' => ['type' => 'string', 'description' => 'Temps visé, ex. « 1:45:00 ».'],
                        'finish_only' => ['type' => 'boolean', 'description' => 'Objectif « finir », sans temps visé.'],
                        'priority' => ['type' => 'string', 'enum' => array_keys(Goal::PRIORITIES), 'description' => 'main : course principale (défaut) ; intermediate : course intermédiaire.'],
                        'notes' => ['type' => 'string', 'description' => 'Motivation, objectif ambitieux, ce que « réussir » veut dire pour lui.'],
                    ],
                    'required' => ['title'],
                ],
            ],
            [
                'name' => 'propose_plan',
                'description' => 'Propose un programme complet jusqu\'à l\'objectif (remplacera les séances à venir s\'il est accepté). '
                    .'L\'athlète voit la proposition dans le chat et l\'accepte ou la refuse.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'feasibility' => ['type' => 'string', 'enum' => array_keys(RunningPlan::FEASIBILITY)],
                        'summary' => ['type' => 'string', 'description' => 'Structure du programme : phases, volume, séances clés.'],
                        'rationale' => ['type' => 'string', 'description' => 'Pourquoi ce programme et cette faisabilité, à partir des données.'],
                        'sessions' => ['type' => 'array', 'items' => $session, 'description' => 'Toutes les séances, de la première à la course.'],
                    ],
                    'required' => ['feasibility', 'summary', 'sessions'],
                ],
            ],
            [
                'name' => 'propose_adjustment',
                'description' => 'Propose de modifier des séances à venir : en ajouter (dont des tests), en modifier, en retirer. '
                    .'L\'athlète l\'accepte ou la refuse dans le chat.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'reason' => ['type' => 'string', 'description' => 'Raison en une phrase, montrée à l\'athlète.'],
                        'add' => ['type' => 'array', 'items' => $session],
                        'update' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'properties' => ['id' => ['type' => 'integer', 'description' => 'Identifiant [#id] de la séance prévue.']] + $session['properties'],
                            'required' => ['id'],
                        ], 'description' => 'Séances prévues à modifier : id + champs qui changent.'],
                        'remove' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Identifiants des séances prévues à retirer.'],
                    ],
                    'required' => ['reason'],
                ],
            ],
            [
                'name' => 'record_feedback',
                'description' => 'Enregistre le ressenti de l\'athlète pour cette course ou cette séance annulée (clôt le débrief en attente).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'summary' => ['type' => 'string', 'description' => 'Synthèse en 1 à 3 phrases : sensations, contexte, ce qu\'il faut retenir.'],
                        'rpe' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'description' => 'Effort ressenti.'],
                        'fatigue' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5, 'description' => 'Forme avec laquelle l\'athlète est arrivé à la séance, fatigue accumulée (1 pleine forme, 5 épuisé) — pas la fatigue due à la séance, qui relève de l\'effort ressenti.'],
                        'mood' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5, 'description' => 'Moral.'],
                        'sleep' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5, 'description' => 'Qualité du sommeil récent.'],
                        'pains' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'properties' => [
                                'zone' => ['type' => 'string'],
                                'intensity' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10],
                                'note' => ['type' => 'string'],
                            ],
                            'required' => ['zone', 'intensity'],
                        ]],
                    ],
                    'required' => ['summary'],
                ],
            ],
            [
                'name' => 'update_athlete_facts',
                'description' => 'Complète le profil de l\'athlète avec des faits donnés par l\'athlète dans la conversation (il les voit et peut les corriger dans son profil). '
                    .'Seuls les champs fournis changent ; les listes s\'ajoutent à l\'existant.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'running_since' => ['type' => 'integer', 'description' => 'Année des débuts en course à pied.'],
                        'other_activities' => ['type' => 'string', 'description' => 'Autres sports, travail physique, avec leur fréquence.'],
                        'long_run_day' => ['type' => 'string', 'enum' => array_keys(Profile::DAYS)],
                        'availability' => ['type' => 'array', 'description' => 'Disponibilités, un élément par jour concerné (remplace celles de ces jours).', 'items' => [
                            'type' => 'object',
                            'properties' => [
                                'day' => ['type' => 'string', 'enum' => array_keys(Profile::DAYS)],
                                'available' => ['type' => 'boolean'],
                                'max_minutes' => ['type' => 'integer'],
                                'slot' => ['type' => 'string', 'enum' => array_keys(Profile::SLOTS)],
                            ],
                            'required' => ['day', 'available'],
                        ]],
                        'past_races' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'properties' => [
                                'date' => ['type' => 'string', 'description' => 'AAAA-MM-JJ (le 1er du mois si seul le mois est connu).'],
                                'name' => ['type' => 'string'],
                                'distance_km' => ['type' => 'number'],
                                'time' => ['type' => 'string', 'description' => '« 52:10 » ou « 1:58:30 ».'],
                            ],
                            'required' => ['date', 'distance_km', 'time'],
                        ]],
                        'injuries' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'properties' => [
                                'zone' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'period' => ['type' => 'string', 'description' => 'Quand : « 2024 », « l\'an dernier ».'],
                                'recurrent' => ['type' => 'boolean'],
                                'current' => ['type' => 'boolean', 'description' => 'Encore gênante aujourd\'hui.'],
                            ],
                            'required' => ['zone'],
                        ]],
                        'unavailabilities' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'properties' => [
                                'starts_on' => ['type' => 'string', 'description' => 'AAAA-MM-JJ'],
                                'ends_on' => ['type' => 'string', 'description' => 'AAAA-MM-JJ'],
                                'kind' => ['type' => 'string', 'enum' => array_keys(Unavailability::KINDS), 'description' => 'none : pas de course ; reduced : volume réduit.'],
                                'reason' => ['type' => 'string'],
                            ],
                            'required' => ['starts_on', 'ends_on', 'kind'],
                        ]],
                    ],
                ],
            ],
            [
                'name' => 'update_memory',
                'description' => 'Réécrit ta mémoire durable de l\'athlète (texte complet, pas un ajout) : historique, blessures, contraintes, '
                    .'disponibilités, ce qui le motive ou le freine, préférences. Garde-la concise (300 mots max).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['content' => ['type' => 'string']],
                    'required' => ['content'],
                ],
            ],
            [
                'name' => 'get_running_history',
                'description' => 'Liste les courses et ressentis plus anciens que ceux de la fiche (pour une tendance de fond).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'from' => ['type' => 'string', 'description' => 'AAAA-MM-JJ'],
                        'to' => ['type' => 'string', 'description' => 'AAAA-MM-JJ'],
                    ],
                    'required' => ['from', 'to'],
                ],
            ],
            [
                'name' => 'fetch_strava_activities',
                'description' => 'Récupère sur Strava les courses d\'une période (même ancienne : « mon semi du 9 janvier 2025 », « toutes mes courses de l\'an dernier ») '
                    .'et les enregistre dans l\'historique, sans les analyser. Jusqu\'à '.self::FETCH_NOW_MAX.' nouvelles courses : importées tout de suite ; '
                    .'au-delà : import en arrière-plan. Les courses déjà présentes ne sont pas réimportées. N\'analyse ensuite que si l\'athlète le demande (get_activity_details).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'from' => ['type' => 'string', 'description' => 'AAAA-MM-JJ'],
                        'to' => ['type' => 'string', 'description' => 'AAAA-MM-JJ (inclus)'],
                    ],
                    'required' => ['from', 'to'],
                ],
            ],
            [
                'name' => 'get_activity_details',
                'description' => 'Détail complet d\'une course enregistrée (tours, FC, analyse, dynamique, meilleurs temps, ressenti), pour l\'analyser quand l\'athlète le demande. '
                    .'L\'identifiant [#id] figure dans la fiche, get_running_history et les outils de récupération.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['activity_id' => ['type' => 'integer']],
                    'required' => ['activity_id'],
                ],
            ],
            [
                'name' => 'fetch_garmin_activities',
                'description' => 'Récupère sur Garmin les courses d\'une période (« mes courses de septembre », « mon 10 km du 12 octobre ») et les enregistre dans l\'historique, '
                    .'sans les analyser. Source à privilégier si Garmin est relié. Jusqu\'à '.self::FETCH_NOW_MAX.' nouvelles courses : importées tout de suite ; '
                    .'au-delà : import en arrière-plan. Les courses déjà présentes ne sont pas réimportées.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'from' => ['type' => 'string', 'description' => 'AAAA-MM-JJ'],
                        'to' => ['type' => 'string', 'description' => 'AAAA-MM-JJ (inclus)'],
                    ],
                    'required' => ['from', 'to'],
                ],
            ],
            [
                'name' => 'write_session_review',
                'description' => 'Rédige (ou réécrit) le bilan de la course débriefée, affiché sur sa fiche sous l\'analyse : à appeler à la fin du débrief, '
                    .'après record_feedback, en croisant les chiffres et le ressenti.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'verdict' => ['type' => 'string', 'enum' => array_keys(RunningActivity::VERDICTS), 'description' => 'Objectif de la séance : reached (atteint), partial (en partie), missed (manqué), free (course libre, sans séance prévue).'],
                        'summary' => ['type' => 'string', 'description' => 'Lecture de la séance en 2 ou 3 phrases, qui relie les chiffres au ressenti et au contexte.'],
                        'strengths' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Points forts, une phrase courte chacun (4 au plus).'],
                        'watchpoints' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Points de vigilance, une phrase courte chacun (4 au plus), éventuellement aucun.'],
                        'next' => ['type' => 'string', 'description' => 'Ce que ça change pour la suite : rien, séance allégée, ajustement proposé…'],
                    ],
                    'required' => ['verdict', 'summary', 'next'],
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fitnessDefinitions(): array
    {
        $muscles = ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(Exercise::MUSCLES)]];

        return [
            [
                'name' => 'propose_exercises',
                'description' => 'Propose des exercices à ajouter au catalogue de l\'athlète, avec la machine ou l\'accessoire dont chacun a besoin. '
                    .'Pour chacun, il répond « J\'ai » (machine et exercice ajoutés), « Pas dans ma salle » (machine notée absente) ou « Non merci » (sans machine). '
                    .'C\'est ainsi que tu découvres sa salle : ne propose pas une machine déjà notée absente, ni un exercice déjà dans son catalogue.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'reason' => ['type' => 'string', 'description' => 'Pourquoi ces exercices, en une phrase, montrée à l\'athlète.'],
                        'items' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string', 'description' => 'Nom courant en français, ex. « Presse à cuisses », « Clamshell ».'],
                                'equipment' => ['type' => 'string', 'description' => 'Machine ou accessoire nécessaire, ex. « Presse à cuisses », « Élastique » ; absent pour un exercice au poids du corps. Reprends le nom exact d\'une machine déjà connue.'],
                                'measure' => ['type' => 'string', 'enum' => array_keys(Exercise::MEASURES), 'description' => 'load_reps : charge × répétitions ; reps : répétitions sans charge ; duration : durée (gainage).'],
                                'muscles' => $muscles + ['description' => 'Muscles principaux.'],
                                'secondary_muscles' => $muscles,
                                'unilateral' => ['type' => 'boolean', 'description' => 'Séries faites de chaque côté.'],
                                'notes' => ['type' => 'string', 'description' => 'Consigne d\'exécution en une ou deux phrases.'],
                            ],
                            'required' => ['name', 'measure', 'muscles'],
                        ], 'description' => '8 exercices au plus.'],
                    ],
                    'required' => ['reason', 'items'],
                ],
            ],
            [
                'name' => 'record_workout',
                'description' => 'Enregistre une séance de fitness racontée par l\'athlète (« aujourd\'hui 3 séries de presse à 100 kg »). '
                    .'Chaque ligne : un exercice de son catalogue ([#id] de la fiche), le nombre de séries identiques, les répétitions (ou la durée) et la charge. '
                    .'Des séries différentes font plusieurs lignes. Un exercice absent du catalogue se propose d\'abord (propose_exercises).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'performed_on' => ['type' => 'string', 'description' => 'AAAA-MM-JJ, aujourd\'hui ou avant.'],
                        'duration_minutes' => ['type' => 'integer'],
                        'rpe' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'description' => 'Effort ressenti de la séance, s\'il le donne.'],
                        'notes' => ['type' => 'string', 'description' => 'Ce qu\'il a dit de la séance, en une phrase.'],
                        'entries' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'properties' => [
                                'exercise_id' => ['type' => 'integer'],
                                'sets' => ['type' => 'integer', 'description' => 'Nombre de séries identiques (1 par défaut).'],
                                'reps' => ['type' => 'integer'],
                                'load_kg' => ['type' => 'number', 'description' => 'Charge ajoutée, en kg (rien au poids du corps).'],
                                'duration_seconds' => ['type' => 'integer', 'description' => 'Durée d\'une série, pour un exercice mesuré en durée.'],
                            ],
                            'required' => ['exercise_id'],
                        ]],
                    ],
                    'required' => ['performed_on', 'entries'],
                ],
            ],
            [
                'name' => 'get_fitness_history',
                'description' => 'Liste les séances de fitness d\'une période plus ancienne que celle de la fiche, avec leurs séries.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'from' => ['type' => 'string', 'description' => 'AAAA-MM-JJ'],
                        'to' => ['type' => 'string', 'description' => 'AAAA-MM-JJ'],
                    ],
                    'required' => ['from', 'to'],
                ],
            ],
        ];
    }

    /**
     * Exécute un appel d'outil et renvoie le texte du résultat.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException entrée invalide : renvoyée au coach comme erreur, pour qu'il corrige
     */
    public function run(User $user, Conversation $conversation, string $name, array $input): string
    {
        if (in_array($name, self::FITNESS_TOOLS, true) && ! $user->hasModuleFeature('coach', 'fitness')) {
            throw ValidationException::withMessages(['name' => 'Le Fitness n\'est pas ouvert à cet athlète.']);
        }

        return match ($name) {
            'set_goal' => $this->setGoal($user, $input),
            'propose_plan' => $this->proposePlan($user, $conversation, $input),
            'propose_adjustment' => $this->proposeAdjustment($user, $conversation, $input),
            'record_feedback' => $this->recordFeedback($user, $conversation, $input),
            'update_athlete_facts' => $this->updateAthleteFacts($user, $input),
            'update_memory' => $this->updateMemory($user, $input),
            'get_running_history' => $this->history($user, $input),
            'fetch_strava_activities' => $this->fetchStrava($user, $input),
            'get_activity_details' => $this->activityDetails($user, $input),
            'fetch_garmin_activities' => $this->fetchGarmin($user, $input),
            'write_session_review' => $this->writeSessionReview($conversation, $input),
            'propose_exercises' => $this->proposeExercises($user, $conversation, $input),
            'record_workout' => $this->recordWorkout($user, $input),
            'get_fitness_history' => $this->fitnessHistory($user, $input),
            default => throw ValidationException::withMessages(['name' => 'Outil inconnu : '.$name]),
        };
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function setGoal(User $user, array $input): string
    {
        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:150'],
            'event_name' => ['nullable', 'string', 'max:150'],
            'event_date' => ['nullable', 'date_format:Y-m-d'],
            'distance_km' => ['nullable', 'numeric', 'min:0.1', 'max:1000'],
            'target_time' => ['nullable', 'string', 'max:20'],
            'finish_only' => ['nullable', 'boolean'],
            'priority' => ['nullable', Rule::in(array_keys(Goal::PRIORITIES))],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        $priority = $data['priority'] ?? 'main';

        $targetSeconds = Duration::parse($data['target_time'] ?? null);
        if (! empty($data['target_time']) && $targetSeconds === null) {
            throw ValidationException::withMessages(['target_time' => 'Temps illisible, format attendu « 1:45:00 ».']);
        }

        if ($priority === 'main') {
            Goal::ownedBy($user)->where('status', 'active')->where('priority', 'main')->update(['status' => 'abandoned']);
        }
        $goal = new Goal([
            'sport' => 'running',
            'title' => $data['title'],
            'event_name' => $data['event_name'] ?? null,
            'event_date' => $data['event_date'] ?? null,
            'distance_km' => $data['distance_km'] ?? null,
            'target_seconds' => empty($data['finish_only']) ? $targetSeconds : null,
            'finish_only' => (bool) ($data['finish_only'] ?? false),
            'priority' => $priority,
            'notes' => $data['notes'] ?? null,
            'status' => 'active',
        ]);
        $goal->user()->associate($user)->save();

        return ($priority === 'main' ? 'Objectif principal' : 'Course intermédiaire').' enregistré(e) : '.$goal->title.'. Visible sur le tableau de bord de l\'athlète.';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function proposePlan(User $user, Conversation $conversation, array $input): string
    {
        $data = Validator::make($input, [
            'feasibility' => ['required', Rule::in(array_keys(RunningPlan::FEASIBILITY))],
            'summary' => ['required', 'string', 'max:5000'],
            'rationale' => ['nullable', 'string', 'max:5000'],
            'sessions' => ['required', 'array', 'min:1', 'max:400'],
            ...$this->sessionRules('sessions.*.'),
        ])->validate();
        $data['sessions'] = array_map(fn (array $session) => $this->normalizeTargets($session), $data['sessions']);
        $this->ensureAvailable($user, $data['sessions']);

        $proposal = $this->propose($user, $conversation, 'plan', 'Programme : '.mb_strimwidth($data['summary'], 0, 200, '…'), $data);

        return 'Proposition #'.$proposal->id.' enregistrée ('.count($data['sessions']).' séances). Elle s\'affiche sous ton message avec les boutons Accepter / Refuser ; '
            .'rien ne change au calendrier avant que l\'athlète l\'accepte. Présente-la brièvement.';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function proposeAdjustment(User $user, Conversation $conversation, array $input): string
    {
        $data = Validator::make($input, [
            'reason' => ['required', 'string', 'max:500'],
            'add' => ['nullable', 'array', 'max:60'],
            ...$this->sessionRules('add.*.'),
            'update' => ['nullable', 'array', 'max:60'],
            'update.*.id' => ['required', 'integer'],
            ...$this->sessionRules('update.*.', partial: true),
            'remove' => ['nullable', 'array', 'max:60'],
            'remove.*' => ['integer'],
        ])->validate();

        $ids = collect($data['update'] ?? [])->pluck('id')->merge($data['remove'] ?? [])->unique();
        $plannedIds = RunningSession::ownedBy($user)->whereIn('id', $ids)->where('status', 'planned')->pluck('id');
        if ($missing = $ids->diff($plannedIds)->values()->all()) {
            throw ValidationException::withMessages(['id' => 'Séances introuvables ou déjà faites / annulées : #'.implode(', #', $missing).'. Seules les séances prévues se modifient.']);
        }
        if (empty($data['add']) && empty($data['update']) && empty($data['remove'])) {
            throw ValidationException::withMessages(['add' => 'Ajustement vide.']);
        }

        $data['add'] = array_map(fn (array $session) => $this->normalizeTargets($session), $data['add'] ?? []);
        $data['update'] = array_map(fn (array $session) => $this->normalizeTargets($session), $data['update'] ?? []);
        $current = RunningSession::ownedBy($user)->whereIn('id', collect($data['update'])->pluck('id'))->get()->keyBy('id');
        $this->ensureAvailable($user, array_merge($data['add'], array_map(fn (array $changes) => $changes + [
            'date' => $current[$changes['id']]->scheduled_on->toDateString(),
            'duration_minutes' => $current[$changes['id']]->duration_minutes,
            'title' => $current[$changes['id']]->title,
        ], $data['update'])));

        $proposal = $this->propose($user, $conversation, 'adjustment', $data['reason'], [
            'add' => $data['add'] ?? [],
            'update' => $data['update'] ?? [],
            'remove' => $data['remove'] ?? [],
        ]);

        return 'Proposition #'.$proposal->id.' enregistrée. Elle s\'affiche sous ton message avec les boutons Accepter / Refuser.';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function recordFeedback(User $user, Conversation $conversation, array $input): string
    {
        $data = Validator::make($input, [
            'summary' => ['required', 'string', 'max:2000'],
            'rpe' => ['nullable', 'integer', 'between:1,10'],
            'fatigue' => ['nullable', 'integer', 'between:1,5'],
            'mood' => ['nullable', 'integer', 'between:1,5'],
            'sleep' => ['nullable', 'integer', 'between:1,5'],
            'pains' => ['nullable', 'array', 'max:10'],
            'pains.*.zone' => ['required', 'string', 'max:60'],
            'pains.*.intensity' => ['required', 'integer', 'between:0,10'],
            'pains.*.note' => ['nullable', 'string', 'max:300'],
        ])->validate();

        $feedback = new Feedback($data + [
            'source' => 'coach',
            'conversation_id' => $conversation->id,
            'session_id' => $conversation->session_id ?? $conversation->activity?->session_id,
            'activity_id' => $conversation->activity_id,
        ]);
        $feedback->user()->associate($user)->save();

        if ($conversation->activity && ! $conversation->activity->rpe && isset($data['rpe'])) {
            $conversation->activity->update(['rpe' => $data['rpe']]);
        }
        if (in_array($conversation->kind, ['debrief', 'cancellation'], true)) {
            $conversation->update(['status' => 'closed']);
        }

        return 'Ressenti enregistré.'.($feedback->maxPain() >= TrainingLoad::PAIN_ALERT ? ' Douleur notable : adapte la suite en conséquence.' : '');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function writeSessionReview(Conversation $conversation, array $input): string
    {
        $activity = $conversation->activity;
        if (! $activity) {
            return 'Pas de course liée à cette conversation : le bilan se rédige dans le débrief d\'une course.';
        }

        $data = Validator::make($input, [
            'verdict' => ['required', Rule::in(array_keys(RunningActivity::VERDICTS))],
            'summary' => ['required', 'string', 'max:1500'],
            'strengths' => ['nullable', 'array', 'max:4'],
            'strengths.*' => ['string', 'max:300'],
            'watchpoints' => ['nullable', 'array', 'max:4'],
            'watchpoints.*' => ['string', 'max:300'],
            'next' => ['required', 'string', 'max:600'],
        ])->validate();

        $activity->update(['review' => [
            'verdict' => $data['verdict'],
            'summary' => $data['summary'],
            'strengths' => array_values($data['strengths'] ?? []),
            'watchpoints' => array_values($data['watchpoints'] ?? []),
            'next' => $data['next'],
            'written_at' => now()->toIso8601String(),
        ]]);

        return 'Bilan enregistré sur la fiche de la course.';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function updateMemory(User $user, array $input): string
    {
        $data = Validator::make($input, ['content' => ['required', 'string', 'max:6000']])->validate();

        $memory = Memory::ownedBy($user)->first() ?? (new Memory)->user()->associate($user);
        $memory->fill($data)->save();

        return 'Mémoire mise à jour.';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function fetchGarmin(User $user, array $input): string
    {
        $data = Validator::make($input, [
            'from' => ['required', 'date_format:Y-m-d', 'after:2008-01-01'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today'],
        ])->validate();

        $account = GarminAccount::ownedBy($user)->first();
        if (! $account) {
            return 'Garmin n\'est pas relié : propose à l\'athlète d\'ajouter sa clé Garmin dans son profil, ou de te donner les chiffres de cette course.';
        }

        // Résolu ici : GarminImporter dépend de la conversation, qui dépend de ces outils.
        $importer = app(GarminImporter::class);
        $period = 'entre le '.Carbon::parse($data['from'])->format('d/m/Y').' et le '.Carbon::parse($data['to'])->format('d/m/Y');

        try {
            $summaries = $importer->runsBetween($account, Carbon::parse($data['from']), Carbon::parse($data['to']));
            $known = RunningActivity::ownedBy($user)->where('source', 'garmin')
                ->whereIn('external_id', $summaries->pluck('activityId')->map(fn ($id) => (string) $id))->pluck('external_id')->all();
            $new = $summaries->reject(fn (array $summary) => in_array((string) $summary['activityId'], $known, true))->values();

            if ($new->count() <= self::FETCH_NOW_MAX) {
                $runs = $new->map(fn (array $summary) => $importer->import($account, $summary, notify: false))->filter();
            }
        } catch (GarminUnavailable $e) {
            return 'Récupération impossible : '.$e->reason.'. Dis simplement à l\'athlète que tu n\'as pas pu récupérer ces données sur Garmin, '
                .'pourquoi en une phrase, et donne-lui ce détail technique à transmettre à la personne qui gère le site : « '.$e->detail.' ». '
                .'Ne réessaie pas dans cette réponse.';
        }

        if ($summaries->isEmpty()) {
            return 'Aucune course sur Garmin '.$period.'. Vérifie la date avec l\'athlète (le jour d\'avant ou d\'après ?).';
        }
        $already = count($known) ? ' '.count($known).' déjà présente(s) dans l\'historique.' : '';

        if (isset($runs)) {
            return ($runs->isEmpty() ? 'Rien de nouveau à importer '.$period.'.' : $runs->count().' course(s) importée(s) '.$period.' :'."\n"
                .$runs->sortBy('started_at')->map(fn (RunningActivity $run) => '- [#'.$run->id.'] '.$run->started_at->format('d/m/Y').' « '.($run->name ?? 'Course').' » · '
                    .number_format($run->distanceKm(), 2, ',', '').' km en '.Duration::format($run->moving_seconds))->implode("\n"))
                .$already.' N\'analyse pas sans qu\'on te le demande ; pour le détail d\'une course : get_activity_details.';
        }

        foreach ($new as $index => $summary) {
            ImportGarminActivityJob::dispatch($account->id, $summary, false)->delay(now()->addSeconds($index * 3));
        }

        return $new->count().' courses trouvées '.$period.', import en arrière-plan (environ '.max(1, (int) ceil($new->count() * 3 / 60)).' min).'
            .$already.' Elles apparaîtront au fur et à mesure dans « Mes courses » et dans ton historique.';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function fetchStrava(User $user, array $input): string
    {
        $data = Validator::make($input, [
            'from' => ['required', 'date_format:Y-m-d', 'after:2008-01-01'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today'],
        ])->validate();

        $account = StravaAccount::ownedBy($user)->first();
        if (! $account) {
            return 'Strava n\'est pas relié : propose à l\'athlète de le relier dans son profil, ou de te donner les chiffres de cette course.';
        }

        // Résolu ici : StravaImporter dépend de la conversation, qui dépend de ces outils.
        $importer = app(StravaImporter::class);

        try {
            $ids = $importer->runIdsBetween($account, Carbon::parse($data['from']), Carbon::parse($data['to']));
            $known = RunningActivity::ownedBy($user)->where('source', 'strava')->whereIn('external_id', array_map('strval', $ids))->pluck('external_id')->all();
            $new = array_values(array_diff($ids, array_map('intval', $known)));

            // Peu de courses : importées tout de suite (disponibles pour la suite de l'échange).
            if (count($new) <= self::FETCH_NOW_MAX) {
                $runs = collect($new)->map(fn (int $id) => $importer->import($account, $id, notify: false))->filter();
            }
        } catch (StravaRateLimited) {
            return 'Limite de requêtes Strava atteinte : dis à l\'athlète de réessayer dans un quart d\'heure.';
        } catch (StravaUnavailable $e) {
            return 'Récupération impossible : '.$e->reason.'. Dis simplement à l\'athlète que tu n\'as pas pu récupérer ces données sur Strava, '
                .'pourquoi en une phrase, et donne-lui ce détail technique à transmettre à la personne qui gère le site : « '.$e->detail.' ». '
                .'Ne réessaie pas dans cette réponse.';
        }

        $period = 'entre le '.Carbon::parse($data['from'])->format('d/m/Y').' et le '.Carbon::parse($data['to'])->format('d/m/Y');
        if (! $ids) {
            return 'Aucune course sur Strava '.$period.'. Vérifie la date avec l\'athlète (le jour d\'avant ou d\'après ?).';
        }
        $already = count($known) ? ' '.count($known).' déjà présente(s) dans l\'historique.' : '';

        if (isset($runs)) {
            return ($runs->isEmpty() ? 'Rien de nouveau à importer '.$period.'.' : $runs->count().' course(s) importée(s) '.$period.' :'."\n"
                .$runs->sortBy('started_at')->map(fn (RunningActivity $run) => '- [#'.$run->id.'] '.$run->started_at->format('d/m/Y').' « '.($run->name ?? 'Course').' » · '
                    .number_format($run->distanceKm(), 2, ',', '').' km en '.Duration::format($run->moving_seconds))->implode("\n"))
                .$already.' N\'analyse pas sans qu\'on te le demande ; pour le détail d\'une course : get_activity_details.';
        }

        foreach ($new as $index => $id) {
            ImportStravaActivityJob::dispatch($account->id, $id, false)->delay(now()->addSeconds($index * self::FETCH_SPACING_SECONDS));
        }

        return count($new).' courses trouvées '.$period.', import en arrière-plan (environ '.max(1, (int) ceil(count($new) * self::FETCH_SPACING_SECONDS / 60))
            .' min, limites de Strava).'.$already.' Elles apparaîtront au fur et à mesure dans « Mes courses » et dans ton historique.';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function activityDetails(User $user, array $input): string
    {
        $data = Validator::make($input, ['activity_id' => ['required', 'integer']])->validate();

        $run = RunningActivity::ownedBy($user)->with('session')->find($data['activity_id']);
        if (! $run) {
            return 'Course #'.$data['activity_id'].' introuvable. Utilise get_running_history pour retrouver son identifiant.';
        }

        $lines = ['Course [#'.$run->id.'] du '.$run->started_at->format('d/m/Y H:i').' « '.($run->name ?? 'Course').' » ('.$run->sourceLabel().') : '.$run->metrics()];
        if ($run->session) {
            $lines[] = 'Séance prévue : '.$run->session->typeLabel().' · '.$run->session->title.($run->session->targets() ? ' · cible '.$run->session->targets() : '');
        }
        array_push($lines, ...$this->runInsights->coachReading($run));
        if ($run->review) {
            $lines[] = 'Ton bilan : '.$run->verdictLabel().' — '.$run->review['summary'].' Suite : '.$run->review['next'];
        }
        foreach (BestEffort::where('activity_id', $run->id)->orderBy('distance_m')->get() as $effort) {
            $lines[] = 'Meilleur temps : '.$effort->label();
        }
        foreach (Feedback::where('activity_id', $run->id)->oldest()->get() as $feedback) {
            $lines[] = 'Ressenti : '.collect([$feedback->scoresLabel(), $feedback->pains ? 'douleurs : '.$feedback->painsLabel() : null, $feedback->summary])->filter()->implode(', ');
        }
        if ($run->notes) {
            $lines[] = 'Note : « '.$run->notes.' »';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function history(User $user, array $input): string
    {
        $data = Validator::make($input, [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ])->validate();

        $runs = RunningActivity::ownedBy($user)->whereBetween('started_at', [$data['from'], $data['to'].' 23:59:59'])->orderBy('started_at')->limit(200)->get();
        $feedbacks = Feedback::ownedBy($user)->whereBetween('created_at', [$data['from'], $data['to'].' 23:59:59'])->orderBy('created_at')->limit(200)->get();

        if ($runs->isEmpty() && $feedbacks->isEmpty()) {
            return 'Rien sur cette période.';
        }

        return "Courses ([#id] pour get_activity_details) :\n".($runs->map(fn (RunningActivity $run) => '- [#'.$run->id.'] '.$run->started_at->format('d/m/Y').' · '.($run->name ? '« '.$run->name.' » · ' : '').$run->metrics())->implode("\n") ?: '- aucune')
            ."\nRessentis :\n".($feedbacks->map(fn (Feedback $feedback) => '- '.$feedback->created_at->format('d/m/Y').' · '.$feedback->summary)->implode("\n") ?: '- aucun');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function proposeExercises(User $user, Conversation $conversation, array $input): string
    {
        $data = Validator::make($input, [
            'reason' => ['required', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:8'],
            ...GymCatalog::exerciseRules('items.*.'),
            'items.*.equipment' => ['nullable', 'string', 'max:100'],
        ])->validate();

        $problems = [];
        foreach ($data['items'] as $item) {
            if ($this->gym->findExercise($user, $item['name'])) {
                $problems[] = '« '.$item['name'].' » est déjà dans son catalogue';
            }
            if (filled($item['equipment'] ?? null) && $this->gym->findEquipment($user, $item['equipment'])?->status === 'missing') {
                $problems[] = '« '.$item['equipment'].' » n\'est pas dans sa salle';
            }
        }
        if (collect($data['items'])->map(fn (array $item) => mb_strtolower(trim($item['name'])))->duplicates()->isNotEmpty()) {
            $problems[] = 'un exercice apparaît deux fois';
        }
        if ($problems) {
            throw ValidationException::withMessages(['items' => ucfirst(implode(' ; ', $problems)).'. Propose autre chose à la place.']);
        }

        $items = array_map(fn (array $item) => collect($item)->only(['name', 'equipment', 'measure', 'muscles', 'secondary_muscles', 'unilateral', 'notes'])->all(), $data['items']);
        $proposal = $this->propose($user, $conversation, 'exercises', $data['reason'], ['items' => $items]);

        return 'Proposition #'.$proposal->id.' enregistrée ('.count($items).' exercices). Elle s\'affiche sous ton message : l\'athlète répond exercice par exercice. '
            .'Les réponses apparaîtront dans sa salle (fiche) ; ne programme que les exercices ajoutés.';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function recordWorkout(User $user, array $input): string
    {
        $data = Validator::make($input, WorkoutRecorder::rules())->validate();
        $workout = $this->workouts->record($user, $data, 'chat');

        return 'Séance [#'.$workout->id.'] enregistrée : '.$workout->summary().'. Elle apparaît dans son onglet Fitness.';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function fitnessHistory(User $user, array $input): string
    {
        $data = Validator::make($input, [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ])->validate();

        $workouts = Workout::ownedBy($user)->with('sets.exercise')->whereBetween('performed_on', [$data['from'], $data['to']])->orderBy('performed_on')->limit(100)->get();

        return $workouts->isEmpty()
            ? 'Aucune séance de fitness sur cette période.'
            : "Séances de fitness :\n".$workouts->map(fn (Workout $workout) => '- [#'.$workout->id.'] '.$workout->summary())->implode("\n");
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function propose(User $user, Conversation $conversation, string $kind, string $reason, array $payload): Proposal
    {
        // Une nouvelle proposition remplace celles du même genre encore en attente dans cette conversation
        // (programme et ajustement d'un côté, exercices de l'autre).
        $conversation->proposals()->where('status', 'pending')
            ->when($kind === 'exercises', fn ($query) => $query->where('kind', 'exercises'), fn ($query) => $query->where('kind', '!=', 'exercises'))
            ->update(['status' => 'refused']);

        $proposal = new Proposal(['conversation_id' => $conversation->id, 'kind' => $kind, 'reason' => $reason, 'payload' => $payload, 'status' => 'pending']);
        $proposal->user()->associate($user)->save();

        return $proposal;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function updateAthleteFacts(User $user, array $input): string
    {
        $data = Validator::make($input, [
            'running_since' => ['nullable', 'integer', 'between:1950,'.today()->year],
            'other_activities' => ['nullable', 'string', 'max:1000'],
            'long_run_day' => ['nullable', Rule::in(array_keys(Profile::DAYS))],
            'availability' => ['nullable', 'array', 'max:7'],
            'availability.*.day' => ['required', Rule::in(array_keys(Profile::DAYS))],
            'availability.*.available' => ['required', 'boolean'],
            'availability.*.max_minutes' => ['nullable', 'integer', 'between:10,600'],
            'availability.*.slot' => ['nullable', Rule::in(array_keys(Profile::SLOTS))],
            'past_races' => ['nullable', 'array', 'max:20'],
            'past_races.*.date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'past_races.*.name' => ['nullable', 'string', 'max:150'],
            'past_races.*.distance_km' => ['required', 'numeric', 'between:0.4,300'],
            'past_races.*.time' => ['required', 'string', 'max:12'],
            'injuries' => ['nullable', 'array', 'max:20'],
            'injuries.*.zone' => ['required', 'string', 'max:80'],
            'injuries.*.description' => ['nullable', 'string', 'max:1000'],
            'injuries.*.period' => ['nullable', 'string', 'max:40'],
            'injuries.*.recurrent' => ['nullable', 'boolean'],
            'injuries.*.current' => ['nullable', 'boolean'],
            'unavailabilities' => ['nullable', 'array', 'max:20'],
            'unavailabilities.*.starts_on' => ['required', 'date_format:Y-m-d'],
            'unavailabilities.*.ends_on' => ['required', 'date_format:Y-m-d'],
            'unavailabilities.*.kind' => ['required', Rule::in(array_keys(Unavailability::KINDS))],
            'unavailabilities.*.reason' => ['nullable', 'string', 'max:150'],
        ])->validate();

        foreach ($data['past_races'] ?? [] as $index => $race) {
            if (Duration::parse($race['time']) === null) {
                throw ValidationException::withMessages(["past_races.$index.time" => 'Temps illisible : « '.$race['time'].' ».']);
            }
        }
        foreach ($data['unavailabilities'] ?? [] as $index => $period) {
            if ($period['ends_on'] < $period['starts_on']) {
                throw ValidationException::withMessages(["unavailabilities.$index.ends_on" => 'La fin est avant le début.']);
            }
        }

        $saved = [];
        $profile = Profile::ownedBy($user)->first() ?? (new Profile)->user()->associate($user);
        $fields = collect($data)->only(['running_since', 'other_activities', 'long_run_day'])->filter(fn ($value) => $value !== null)->all();
        if (! empty($data['availability'])) {
            $availability = $profile->availability ?: collect(Profile::DAYS)->map(fn () => ['available' => false, 'max_minutes' => null, 'slot' => null])->all();
            foreach ($data['availability'] as $day) {
                $availability[$day['day']] = ['available' => (bool) $day['available'], 'max_minutes' => $day['max_minutes'] ?? null, 'slot' => $day['slot'] ?? null];
            }
            $fields['availability'] = $availability;
        }
        if ($fields) {
            $profile->fill($fields)->save();
            $saved[] = 'profil ('.implode(', ', array_keys($fields)).')';
        }

        foreach ($data['past_races'] ?? [] as $race) {
            (new PastRace(['raced_on' => $race['date'], 'name' => $race['name'] ?? null, 'distance_km' => $race['distance_km'], 'time_seconds' => Duration::parse($race['time'])]))
                ->user()->associate($user)->save();
        }
        foreach ($data['injuries'] ?? [] as $injury) {
            (new Injury($injury))->user()->associate($user)->save();
        }
        foreach ($data['unavailabilities'] ?? [] as $period) {
            (new Unavailability($period))->user()->associate($user)->save();
        }
        foreach (['past_races' => 'course(s) passée(s)', 'injuries' => 'blessure(s)', 'unavailabilities' => 'indisponibilité(s)'] as $key => $label) {
            if (! empty($data[$key])) {
                $saved[] = count($data[$key]).' '.$label;
            }
        }

        return $saved ? 'Enregistré : '.implode(', ', $saved).'. Les repères et la fiche seront recalculés au prochain message.' : 'Rien à enregistrer.';
    }

    /**
     * Allures « m:ss » converties en secondes par km.
     *
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    private function normalizeTargets(array $session): array
    {
        foreach (['pace_from', 'pace_to'] as $key) {
            if (isset($session[$key])) {
                $session[$key] = Duration::parse($session[$key]);
            }
        }

        return $session;
    }

    /**
     * @param  list<array<string, mixed>>  $sessions
     *
     * @throws ValidationException séances hors disponibilités : le coach doit corriger sa proposition
     */
    private function ensureAvailable(User $user, array $sessions): void
    {
        if ($problems = $this->availability->violations($user, $sessions)) {
            throw ValidationException::withMessages(['sessions' => 'Proposition refusée, hors des disponibilités de l\'athlète : '.implode(' ', $problems)
                .' Corrige ces séances et propose à nouveau (ou demande-lui s\'il peut exceptionnellement).']);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function sessionRules(string $prefix, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            $prefix.'date' => [$required, 'date_format:Y-m-d', 'after_or_equal:today'],
            $prefix.'type' => [$required, Rule::in(array_keys(RunningSession::TYPES))],
            $prefix.'title' => [$required, 'string', 'max:150'],
            $prefix.'description' => ['nullable', 'string', 'max:3000'],
            $prefix.'duration_minutes' => ['nullable', 'integer', 'between:1,600'],
            $prefix.'distance_km' => ['nullable', 'numeric', 'between:0.1,300'],
            $prefix.'pace_from' => ['nullable', 'string', 'regex:/^\d{1,2}:[0-5]\d$/'],
            $prefix.'pace_to' => ['nullable', 'string', 'regex:/^\d{1,2}:[0-5]\d$/'],
            $prefix.'hr_from' => ['nullable', 'integer', 'between:60,230'],
            $prefix.'hr_to' => ['nullable', 'integer', 'between:60,230'],
        ];
    }
}
