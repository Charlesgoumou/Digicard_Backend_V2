<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;
use App\Http\Controllers\PublicProfileController;

// --- ROUTE POUR SERVIR LES FICHIERS DEPUIS STORAGE ---
// ✅ CORRECTION : Route pour servir les fichiers depuis storage/app/public
// Cette route est placée AVANT la route fallback pour qu'elle soit prioritaire
// Utile pour Windows/XAMPP où les liens symboliques peuvent ne pas fonctionner correctement
Route::get('/storage/{path}', function ($path) {
    // Vérifier que le fichier existe
    if (!Storage::disk('public')->exists($path)) {
        abort(404, 'Fichier non trouvé');
    }
    
    // Récupérer le fichier
    $file = Storage::disk('public')->get($path);
    $type = Storage::disk('public')->mimeType($path);
    
    // Retourner le fichier avec les en-têtes appropriés
    return Response::make($file, 200, [
        'Content-Type' => $type,
        'Content-Disposition' => 'inline', // Afficher dans le navigateur plutôt que télécharger
        'Cache-Control' => 'public, max-age=31536000', // Cache pour 1 an
    ]);
})->where('path', '.*');

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
