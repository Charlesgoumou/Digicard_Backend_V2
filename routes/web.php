<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\PublicProfileController;
use App\Http\Controllers\StorageController;
use App\Http\Controllers\SocialController;
use App\Http\Controllers\OrderController;

// --- ROUTE DE TEST ---
// Route de test pour vérifier que Laravel fonctionne
Route::get('/test-storage-route', function () {
    return response()->json([
        'message' => 'Route Laravel accessible',
        'timestamp' => now(),
        'storage_path' => storage_path('app/public'),
        'storage_exists' => file_exists(storage_path('app/public')),
    ]);
});

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

// --- ROUTE RACINE POUR L'APPLICATION VUE.JS ---
// ✅ AJOUT: Route spécifique pour la racine '/' pour éviter l'erreur ArgumentCountError
Route::get('/', function () {
    // S'assure que la vue 'index' (qui charge Vue) existe
    if (view()->exists('index')) {
        return view('index');
    }
    // Gère le cas où l'application Vue n'est pas configurée
    return "Application non trouvée. Assurez-vous d'avoir une vue 'index.blade.php' à la racine de 'resources/views'.";
});

// --- ROUTE FALLBACK POUR L'APPLICATION VUE.JS ---
// ✅ CORRECTION : La route /storage/{path} est définie AVANT cette route fallback
// donc elle sera toujours prioritaire. Cette route ne capturera que les routes qui
// ne correspondent pas à /storage/*
Route::get('/{any}', function ($any) {
    // S'assure que la vue 'index' (qui charge Vue) existe
    if (view()->exists('index')) {
        return view('index');
    }
    // Gère le cas où l'application Vue n'est pas configurée
    return "Application non trouvée. Assurez-vous d'avoir une vue 'index.blade.php' à la racine de 'resources/views'.";
})->where('any', '.*'); // Capture toutes les autres routes (mais /storage/{path} est prioritaire car défini avant)

// L'accolade fermante en trop a été supprimée d'ici.
