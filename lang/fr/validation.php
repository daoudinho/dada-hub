<?php

/*
 * Messages de validation utilisés par les formulaires du hub.
 * Les règles absentes d'ici retombent sur l'anglais (APP_FALLBACK_LOCALE).
 */

return [
    'after' => 'Le champ :attribute doit être une date postérieure au :date.',
    'array' => 'Le champ :attribute doit être une liste.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'current_password' => 'Le mot de passe est incorrect.',
    'date' => 'Le champ :attribute n\'est pas une date valide.',
    'date_format' => 'Le champ :attribute doit respecter le format :format.',
    'email' => 'Le champ :attribute doit être une adresse e-mail valide.',
    'in' => 'La valeur du champ :attribute est invalide.',
    'lowercase' => 'Le champ :attribute doit être en minuscules.',
    'max' => [
        'array' => 'Le champ :attribute ne doit pas contenir plus de :max éléments.',
        'numeric' => 'Le champ :attribute ne doit pas dépasser :max.',
        'string' => 'Le champ :attribute ne doit pas dépasser :max caractères.',
    ],
    'min' => [
        'array' => 'Le champ :attribute doit contenir au moins :min éléments.',
        'numeric' => 'Le champ :attribute doit être au moins :min.',
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
    ],
    'password' => [
        'letters' => 'Le champ :attribute doit contenir au moins une lettre.',
        'mixed' => 'Le champ :attribute doit contenir au moins une majuscule et une minuscule.',
        'numbers' => 'Le champ :attribute doit contenir au moins un chiffre.',
        'symbols' => 'Le champ :attribute doit contenir au moins un symbole.',
        'uncompromised' => 'Ce :attribute est apparu dans une fuite de données. Choisissez-en un autre.',
    ],
    'required' => 'Le champ :attribute est obligatoire.',
    'string' => 'Le champ :attribute doit être une chaîne de caractères.',
    'unique' => 'Cette valeur de :attribute est déjà utilisée.',
    'url' => 'Le champ :attribute doit être une URL valide.',

    'attributes' => [
        'name' => 'nom',
        'email' => 'e-mail',
        'password' => 'mot de passe',
        'current_password' => 'mot de passe actuel',
        'title' => 'titre',
        'starts_on' => 'date',
        'ends_on' => 'date de fin',
        'time' => 'heure',
        'place' => 'lieu',
        'category' => 'catégorie',
        'filters' => 'filtres',
    ],
];
