<?php

namespace App\Http\Controllers;

use App\Services\ImageCompressionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
// ❗️ JeroenDesloovere\VCard\VCard n'est plus nécessaire ici

class ProfileController extends Controller
{
    /**
     * Récupère le profil complet de l'utilisateur connecté.
     */
    public function show(Request $request)
    {
        return response()->json($request->user());
    }

    /**
     * Met à jour le profil de l'utilisateur connecté.
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $validatedData = $request->validate([
            // Le username n'est plus modifiable - il est généré automatiquement
            'name'     => 'required|string|max:255',
            'title'    => 'nullable|string|max:255',
            'vcard_phone'   => 'nullable|string|max:255',
            'vcard_email'   => 'nullable|email|max:255',
            'vcard_company' => 'nullable|string|max:255',
            'vcard_address' => 'nullable|string',
            'whatsapp_url' => 'nullable|url:http,https',
            'linkedin_url' => 'nullable|url:http,https',
            'facebook_url' => 'nullable|url:http,https',
            'twitter_url'  => 'nullable|url:http,https',
            'youtube_url'  => 'nullable|url:http,https',
            'deezer_url'   => 'nullable|url:http,https',
            'spotify_url'  => 'nullable|url:http,https',

            // --- Nouveaux champs de personnalisation ---
            'profile_border_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'save_contact_button_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'services_button_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'phone_numbers' => 'nullable|array',
            'phone_numbers.*' => 'nullable|string|max:50',
            'emails' => 'nullable|array',
            'emails.*' => 'nullable|email|max:255',
            'birth_day' => 'nullable|integer|min:1|max:31',
            'birth_month' => 'nullable|integer|min:1|max:12',
            'website_url' => 'nullable|url:http,https',
            'address_neighborhood' => 'nullable|string|max:255',
            'address_commune' => 'nullable|string|max:255',
            'address_city' => 'nullable|string|max:255',
            'address_country' => 'nullable|string|max:255',
        ]);

        $user->update($validatedData);

        return response()->json($user->fresh());
    }

    /**
     * Met à jour l'avatar de l'utilisateur connecté.
     */
    public function updateAvatar(Request $request)
    {
        // ... (La logique updateAvatar reste identique) ...
        $request->validate(['avatar' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048']);
        $user = $request->user();
        
        // ✅ CORRECTION : Nettoyer le chemin correctement pour supprimer l'ancienne photo
        if ($user->avatar_url) {
            // Supprimer /storage/ du début si présent, puis supprimer le fichier
            $oldPath = preg_replace('#^/storage/#', '', $user->avatar_url);
            // Supprimer aussi les URLs complètes (http://...) si présentes
            $oldPath = preg_replace('#^https?://[^/]+/storage/#', '', $oldPath);
            if (Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
        }
        
        // Compresser et stocker l'avatar
        $compressionService = new ImageCompressionService();
        $result = $compressionService->compressImage($request->file('avatar'), 'avatars');
        
        // ✅ CORRECTION : Vérifier que le fichier a bien été créé
        if (!isset($result['path']) || !Storage::disk('public')->exists($result['path'])) {
            Log::error('ProfileController::updateAvatar - Fichier non créé après compression', [
                'result' => $result,
                'user_id' => $user->id,
            ]);
            return response()->json([
                'message' => 'Erreur lors du stockage de la photo.',
            ], 500);
        }
        
        // ✅ CORRECTION : Utiliser le format /storage/ pour la cohérence avec OrderController
        // Le frontend construira l'URL complète avec VITE_APP_URL_BACKEND
        $user->avatar_url = '/storage/' . $result['path'];
        $user->save();
        
        Log::info('ProfileController::updateAvatar - Photo uploadée avec succès', [
            'user_id' => $user->id,
            'avatar_url' => $user->avatar_url,
            'file_exists' => Storage::disk('public')->exists($result['path']),
        ]);
        
        return response()->json(['avatar_url' => $user->avatar_url]);
    }

    // ⛔️ La méthode downloadVcard() a été SUPPRIMÉE d'ici.
}
