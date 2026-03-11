<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Google Gemini API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration pour l'API Google Gemini utilisée pour la génération
    | de contenu marketing et l'extraction de texte depuis des documents.
    |
    */

    'api_key' => env('GEMINI_API_KEY', ''),

    'api_url' => env('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent'),

    /*
    | Timeout (secondes) pour les appels API : extraction PDF/image et génération de contenu.
    | Les PDF lourds ou l'API chargée peuvent dépasser 10s ; 180s aligne particuliers et business.
    */
    'timeout' => (int) env('GEMINI_TIMEOUT', 180),
    'connect_timeout' => (int) env('GEMINI_CONNECT_TIMEOUT', 30),

    /*
    | Limite de tokens en sortie pour éviter la troncature (ex: formations manquantes).
    | Gemini 2.5 Flash supporte jusqu'à 8192+ ; 16384 améliore l'exhaustivité.
    */
    'max_output_tokens' => (int) env('GEMINI_MAX_OUTPUT_TOKENS', 16384),
];

