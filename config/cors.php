<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout', 'auth/*'], // Routes API avec le préfixe /api + routes d'authentification Google

    'allowed_methods' => ['*'], // Allows all HTTP methods (GET, POST, PUT, DELETE, etc.)

    'allowed_origins' => [
        'http://localhost:5173',
        'http://localhost:5174', // ✅ Frontend Admin (port 5174)
        'http://localhost:8000', // ✅ AJOUT: Autoriser localhost:8000 pour les rechargements de page
        'http://192.168.1.126:5173',
        'https://digicard.arccenciel.com',
        'https://digicard-admin.arccenciel.com',
    ],

    'allowed_origins_patterns' => [
        '/^https?:\/\/.*\.arccenciel\.com$/',
        '/^https?:\/\/.*\.digicard\.arccenciel\.com$/',
    ],

    'allowed_headers' => ['*'], // Allows all headers

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true, // IMPORTANT: Must be true for Sanctum authentication to work

];
