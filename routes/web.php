<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\PublicProfileController;
use App\Http\Controllers\StorageController;
use App\Http\Controllers\SocialController;
use App\Http\Controllers\OrderController;

// --- ROUTE POUR SERVIR LES FICHIERS DEPUIS STORAGE ---
// ✅ CORRECTION : Utiliser un contrôleur dédié pour plus de fiabilité
// Cette route est placée AVANT la route fallback pour qu'elle soit prioritaire
// Utile pour Windows/XAMPP où les liens symboliques peuvent ne pas fonctionner correctement
Route::get('/storage/{path}', [StorageController::class, 'serve'])
    ->where('path', '.*')
    ->name('storage.serve');

// --- ROUTE DU PROFIL PUBLIC (ancienne URL, conservée pour compat) ---
Route::get('/profil/{user:username}', [PublicProfileController::class, 'show'])
     ->name('profile.public.show');

// --- ROUTES COURTES (URL-safe) protégées par throttle ---
Route::middleware('throttle:20,1')->group(function () {
    // /p/{code} : profil public via short_code (Order)
    Route::get('/p/{code}', [PublicProfileController::class, 'showByShortCode'])
        ->name('profile.public.short.show');

    // vCard courte : /p/{code}/vcard
    Route::get('/p/{code}/vcard', [PublicProfileController::class, 'downloadVcardByShortCode'])
        ->name('profile.public.short.vcard');

    // Route services / portfolio simplifiée : /p/{code}/services
    Route::get('/p/{code}/services', [PublicProfileController::class, 'redirectToServices'])
        ->name('profile.public.short.services');
});

// --- Anciennes routes courtes avec username (compatibilité) ---
// /p/{code}/{username} : support des cartes employés existants via short_code + username
Route::get('/p/{code}/{user:username}', [PublicProfileController::class, 'showByShortCodeForUser'])
    ->name('profile.public.short.show-user');
Route::get('/p/{code}/{user:username}/vcard', [PublicProfileController::class, 'downloadVcardByShortCodeForUser'])
    ->name('profile.public.short.vcard-user');

// --- NOUVELLE ROUTE PUBLIQUE POUR LA vCard (ancienne URL, compat) ---
// Elle utilise le 'username' pour trouver l'utilisateur et déclencher le téléchargement
Route::get('/profil/{user:username}/vcard', [PublicProfileController::class, 'downloadVcard'])
     ->name('profile.public.vcard');

// --- ROUTES GOOGLE OAUTH ---
Route::get('/auth/google', [SocialController::class, 'redirect'])->name('google.redirect');
Route::get('/auth/google/callback', [SocialController::class, 'callback'])->name('google.callback');

// --- ROUTE DE CALLBACK POUR LES PAIEMENTS (Restauration de session serveur-à-serveur) ---
// ✅ NOUVEAU: Route WEB (pas API) pour bénéficier nativement des cookies de session
// Cette route restaure la session avant de rediriger vers le frontend
Route::get('/payment/callback', [OrderController::class, 'handlePaymentCallback'])->name('payment.callback');

// --- ROUTE RACINE POUR L'API BACKEND ---
// ✅ CORRECTION: Le backend est une API, il ne doit pas servir de HTML/Vue.js
// Retourner une réponse JSON simple indiquant que c'est l'API backend
Route::get('/', function () {
    return response()->json([
        'message' => 'DigiCard API Backend',
        'version' => '1.0',
        'status' => 'online',
        'endpoints' => [
            'api' => '/api',
            'documentation' => 'Voir la documentation API pour plus d\'informations',
        ],
    ], 200, [], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
});

// ✅ SUPPRIMÉ: Route fallback pour Vue.js - Le backend ne doit pas servir le frontend
// Le frontend doit être servi depuis un autre domaine (digicard.arccenciel.com)

// L'accolade fermante en trop a été supprimée d'ici.
