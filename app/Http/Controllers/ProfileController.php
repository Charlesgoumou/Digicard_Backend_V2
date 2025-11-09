<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
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
        if ($user->avatar_url && str_contains($user->avatar_url, '/storage/')) {
            $oldPath = str_replace(url('/storage'), '', $user->avatar_url);
            Storage::disk('public')->delete($oldPath);
        }
        $path = $request->file('avatar')->store('avatars', 'public');
        $user->avatar_url = Storage::disk('public')->url($path);
        $user->save();
        return response()->json(['avatar_url' => $user->avatar_url]);
    }

    // ⛔️ La méthode downloadVcard() a été SUPPRIMÉE d'ici.
}
