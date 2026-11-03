<?php

/*
 * Configuration du module Recettes : config('recettes.*').
 * Le fournisseur d'IA est dans config/ai.php, les clés d'API dans config/services.php.
 */

return [

    'image' => [
        /** Largeur maximale des images enregistrées (pixels). */
        'max_width' => 1200,

        /** Qualité de compression WebP / JPEG (0 à 100). */
        'quality' => 80,

        /** Taille maximale d'une image envoyée depuis le formulaire (Ko). */
        'max_upload_kb' => 10240,

        /** Taille maximale d'une image téléchargée lors d'un import (Ko). */
        'max_download_kb' => 10240,
    ],

    /** Nombre de personnes proposé par défaut. */
    'default_servings' => 2,

];
