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
];

