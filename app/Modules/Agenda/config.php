<?php

/*
 * Configuration du module Agenda : config('agenda.*').
 * Les clés d'API et le choix des modèles restent dans config/services.php.
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

    /*
     * Prix en dollars par million de tokens [entrée, sortie], tarif standard.
     * Source : https://developers.openai.com/api/docs/pricing (octobre 2026).
     * Un modèle absent de la liste : le coût affiché est « inconnu », les tokens restent comptés.
     */
    'prices' => [
        'gpt-5-nano' => [0.05, 0.40],
        'gpt-4.1-nano' => [0.10, 0.40],
        'gpt-6-luna' => [0.10, 0.50],
        'gpt-5-mini' => [0.25, 2.00],
        'gpt-6.1-sol' => [2.00, 10.00],
        'gpt-5.5' => [5.00, 30.00],
        'gpt-6-astra' => [10.00, 50.00],
    ],

    /** Réduction de l'API Batch d'OpenAI (0.5 = moitié prix). */
    'batch_price_factor' => 0.5,

];
