<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Perplexity AI API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration pour l'API Perplexity AI utilisée pour explorer
    | le contenu des sites web et déterminer les besoins des utilisateurs.
    |
    */

    'api_key' => env('PERPLEXITY_API_KEY', ''),

    'api_url' => env('PERPLEXITY_API_URL', 'https://api.perplexity.ai/chat/completions'),

    /*
    | Modèle à utiliser pour l'exploration de sites web
    */
    'model' => env('PERPLEXITY_MODEL', 'llama-3.1-sonar-large-128k-online'),
];
