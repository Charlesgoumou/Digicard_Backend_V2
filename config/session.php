<?php

use Illuminate\Support\Str;

// ✅ CORRECTION: Détection automatique de l'environnement pour les cookies
// En production (HTTPS), utiliser 'none' pour permettre les redirections externes
// En local (HTTP), utiliser 'lax' car 'none' nécessite HTTPS
// Note: On utilise env('APP_ENV') directement car app() n'est pas disponible dans les fichiers de config
$appEnv = env('APP_ENV', 'local');
$isProduction = $appEnv === 'production';
$sessionSameSite = env('SESSION_SAME_SITE', $isProduction ? 'none' : 'lax');
$sessionSecure = env('SESSION_SECURE_COOKIE', $isProduction ? true : null);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Session Driver
    |--------------------------------------------------------------------------
    | ... (description) ...
    */
    'driver' => env('SESSION_DRIVER', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Session Lifetime
    |--------------------------------------------------------------------------
    | ... (description) ...
    */
    'lifetime' => (int) env('SESSION_LIFETIME', 120),
    'expire_on_close' => env('SESSION_EXPIRE_ON_CLOSE', false),

    /*
    |--------------------------------------------------------------------------
    | Session Encryption
    |--------------------------------------------------------------------------
    | ... (description) ...
    */
    'encrypt' => env('SESSION_ENCRYPT', false),

    /*
    |--------------------------------------------------------------------------
    | Session File Location
    |--------------------------------------------------------------------------
    | ... (description) ...
    */
    'files' => storage_path('framework/sessions'),

    /*
    |--------------------------------------------------------------------------
    | Session Database Connection
    |--------------------------------------------------------------------------
    | ... (description) ...
    */
    'connection' => env('SESSION_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Session Database Table
    |--------------------------------------------------------------------------
    | ... (description) ...
    */
    'table' => env('SESSION_TABLE', 'sessions'),

    /*
    |--------------------------------------------------------------------------
    | Session Cache Store
    |--------------------------------------------------------------------------
    | ... (description) ...
    */
    'store' => env('SESSION_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Session Sweeping Lottery
    |--------------------------------------------------------------------------
    | ... (description) ...
    */
    'lottery' => [2, 100],

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Name
    |--------------------------------------------------------------------------
    | ... (description) ...
    */
    'cookie' => env(
        'SESSION_COOKIE',
        Str::slug((string) env('APP_NAME', 'laravel')).'-session'
    ),

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Path
    |--------------------------------------------------------------------------
    | ... (description) ...
    */
    'path' => env('SESSION_PATH', '/'),

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Domain
    |--------------------------------------------------------------------------
    | ... (description) ...
    | 
    | ✅ IMPORTANT: Pour localhost, laissez cette valeur à null (ne pas définir SESSION_DOMAIN dans .env)
    | Définir un domaine peut causer des problèmes avec les cookies sur localhost
    */
    'domain' => env('SESSION_DOMAIN', null),

    /*
    |--------------------------------------------------------------------------
    | HTTPS Only Cookies
    |--------------------------------------------------------------------------
    | ... (description) ...
    | 
    | ✅ IMPORTANT: En développement local (HTTP), cette valeur doit être false ou null
    | En production (HTTPS), cette valeur doit être true
    | 
    | ✅ CORRECTION: Détection automatique basée sur l'environnement si non défini
    | - En production : true (HTTPS requis)
    | - En local : null (HTTP accepté)
    */
    'secure' => $sessionSecure === null ? null : filter_var($sessionSecure, FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | HTTP Access Only
    |--------------------------------------------------------------------------
    | ... (description) ...
    */
    'http_only' => env('SESSION_HTTP_ONLY', true),

    /*
    |--------------------------------------------------------------------------
    | Same-Site Cookies
    |--------------------------------------------------------------------------
    | ... (description) ...
    | Supported: "lax", "strict", "none", null
    | 
    | ✅ IMPORTANT: 
    | - 'lax' (défaut en local) : Permet aux cookies d'être envoyés lors des redirections GET
    |   depuis des domaines externes (comme après un paiement Chap Chap Pay).
    |   Convient pour le développement local.
    | 
    | - 'strict' : Bloque les cookies lors des redirections externes.
    |   Ne convient pas pour les paiements avec redirections externes.
    | 
    | - 'none' (recommandé en production) : Nécessite SESSION_SECURE_COOKIE=true (HTTPS uniquement).
    |   ✅ RECOMMANDÉ pour la production avec redirections externes (passerelles de paiement).
    |   Permet aux cookies d'être envoyés dans tous les contextes cross-site.
    |   ⚠️ OBLIGATOIRE: SESSION_SECURE_COOKIE=true doit être défini en production.
    | 
    | ✅ CORRECTION: Configuration dynamique basée sur l'environnement
    | - En local (HTTP) : 'lax' (fonctionne avec HTTP)
    | - En production (HTTPS) : 'none' (nécessite HTTPS, permet les redirections externes)
    | 
    | Configuration recommandée pour production :
    |   SESSION_SAME_SITE=none
    |   SESSION_SECURE_COOKIE=true
    */

    'same_site' => $sessionSameSite,

    /*
    |--------------------------------------------------------------------------
    | Partitioned Cookies
    |--------------------------------------------------------------------------
    | ... (description) ...
    */
    'partitioned' => env('SESSION_PARTITIONED_COOKIE', false),

];
