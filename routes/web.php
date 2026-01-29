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

// --- ROUTE DU PROFIL PUBLIC ---
Route::get('/profil/{user:username}', [PublicProfileController::class, 'show'])
     ->name('profile.public.show');

// --- NOUVELLE ROUTE PUBLIQUE POUR LA vCard ---
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
