<?php

/*
 * Configuration du module Coach : config('coach.*').
 * Le coach utilise toujours Claude (clé ANTHROPIC_API_KEY dans config/services.php),
 * quel que soit AI_PROVIDER, qui reste le choix des autres modules.
 */

return [

    'ai' => [
        /** Modèle du coach. */
        'model' => env('COACH_AI_MODEL', 'claude-opus-5-5'),

        /** Profondeur de réflexion : low, medium, high (le coût augmente avec). */
        'effort' => env('COACH_AI_EFFORT', 'medium'),

        /** Taille maximale d'une réponse (tokens). */
        'max_tokens' => 16000,

        /** Nombre maximal d'allers-retours d'outils (proposition de programme, ressenti…) pour une réponse. */
        'max_tool_rounds' => 6,
    ],

    /** Synchronisation automatique (Garmin, Google Health) à l'ouverture du coach, si la dernière date de plus de (minutes). */
    'auto_sync_minutes' => 30,

    /** Graphique du poids : période affichée (jours). */
    'weight_chart_days' => 180,

    /** Graphique des kilomètres : nombre de semaines affichées. */
    'distance_chart_weeks' => 12,

    /*
     * Strava (identifiants de l'application dans config/services.php, section « strava »).
     */
    'strava' => [
        /** Historique importé à la connexion (semaines). */
        'backfill_weeks' => 12,

        /** Une course importée plus récente que ce délai ouvre un débrief (heures) ; les plus anciennes non. */
        'debrief_within_hours' => 72,

        /** Kilométrage à partir duquel des chaussures sont signalées comme usées. */
        'shoe_alert_km' => 700,

        /** Types d'activité Strava importés comme courses. */
        'run_types' => ['Run', 'TrailRun', 'VirtualRun'],
    ],

    /*
     * Google Health (client OAuth dans config/services.php, section « google_health ») : les courses de la montre
     * arrivées par Health Connect (Samsung Health…). Le délai du débrief est celui de Strava.
     */
    'google_health' => [
        /** Historique importé à la connexion (mois). */
        'backfill_months' => 24,

        /** Types de séance Google Health importés comme courses. */
        'run_types' => ['RUNNING', 'TRAIL_RUN', 'TREADMILL', 'INCLINE_RUN'],
    ],

];
