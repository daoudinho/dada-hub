<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'agenda' => [
        'provider' => env('AGENDA_AI_PROVIDER', 'openai'), // openai ou anthropic
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-5.5'), // recherche web libre
        'extract_model' => env('OPENAI_EXTRACT_MODEL', 'gpt-5-nano'), // extraction depuis les pages des sources
        // Effort de raisonnement des modèles qui raisonnent (gpt-5-nano : minimal). Vide pour un modèle qui ne raisonne pas.
        'extract_reasoning' => env('OPENAI_EXTRACT_REASONING', 'minimal'),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
        'extract_model' => env('ANTHROPIC_EXTRACT_MODEL', 'claude-haiku-5-5'),
    ],

    'firecrawl' => [
        'key' => env('FIRECRAWL_API_KEY'),
        'max_age' => (int) env('FIRECRAWL_MAX_AGE', 3600000), // ms : copie en cache Firecrawl acceptée (1 h)
        'max_chars' => (int) env('FIRECRAWL_MAX_CHARS', 40000), // taille max d'une page envoyée à l'IA
    ],

];
