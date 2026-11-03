<?php

/*
 * Configuration du module Agenda : config('agenda.*').
 * Le fournisseur et les prix de l'IA sont dans config/ai.php, les clés d'API dans config/services.php.
 */

return [

    /** Nombre de jours couverts par un scan. */
    'days' => 60,

    /** Fréquence de scan proposée par défaut pour une nouvelle source (jours). */
    'default_scan_every_days' => 7,

    /** Échecs consécutifs avant désactivation automatique d'une source. */
    'max_failures' => 3,

    /** Délai entre deux vérifications d'un lot envoyé à l'API Batch d'OpenAI (secondes). */
    'batch_poll_seconds' => 300,

];
