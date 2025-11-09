<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PublicProfileController;

// --- ROUTE DU PROFIL PUBLIC ---
Route::get('/profil/{user:username}', [PublicProfileController::class, 'show'])
     ->name('profile.public.show');

// --- NOUVELLE ROUTE PUBLIQUE POUR LA vCard ---
// Elle utilise le 'username' pour trouver l'utilisateur et déclencher le téléchargement
Route::get('/profil/{user:username}/vcard', [PublicProfileController::class, 'downloadVcard'])
     ->name('profile.public.vcard');


// --- ROUTE FALLBACK POUR L'APPLICATION VUE.JS ---
Route::get('/{any?}', function () {
    // S'assure que la vue 'index' (qui charge Vue) existe
    if (view()->exists('index')) {
        return view('index');
    }
    // Gère le cas où l'application Vue n'est pas configurée
    return "Application non trouvée. Assurez-vous d'avoir une vue 'index.blade.php' à la racine de 'resources/views'.";
})->where('any', '.*'); // Capture toutes les autres routes

// L'accolade fermante en trop a été supprimée d'ici.
