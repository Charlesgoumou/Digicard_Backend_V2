<?php

namespace App\Http\Controllers;

use App\Models\SharedContact;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SharedContactController extends Controller
{
    /**
     * Recevoir un contact partagé par un visiteur (route publique)
     */
    public function store(Request $request, User $user)
    {
        Log::info("SharedContactController@store appelé", [
            'user_id' => $user->id,
            'request_data' => $request->all()
        ]);

        try {
            $validated = $request->validate([
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:50',
                'company' => 'nullable|string|max:255',
                'note' => 'nullable|string|max:1000',
                'order_id' => 'nullable|exists:orders,id',
            ]);

            // Au moins un email ou un téléphone requis
            if (empty($validated['email']) && empty($validated['phone'])) {
                Log::warning("Partage de contact rejeté: email et téléphone manquants");
                return response()->json([
                    'message' => 'Veuillez fournir au moins un email ou un numéro de téléphone.'
                ], 422);
            }

            $contact = SharedContact::create([
                'user_id' => $user->id,
                'order_id' => $validated['order_id'] ?? null,
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'company' => $validated['company'] ?? null,
                'note' => $validated['note'] ?? null,
            ]);

            Log::info("✅ Contact partagé avec succès", [
                'contact_id' => $contact->id,
                'user_id' => $user->id,
                'full_name' => $contact->full_name
            ]);

            return response()->json([
                'message' => 'Contact partagé avec succès !',
                'contact' => $contact,
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error("Erreur de validation lors du partage de contact", [
                'errors' => $e->errors()
            ]);
            return response()->json([
                'message' => 'Données invalides.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error("Erreur lors du partage de contact: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Une erreur est survenue lors du partage du contact.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Liste des contacts partagés de l'utilisateur connecté
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        $contacts = SharedContact::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'contacts' => $contacts,
            'total' => $contacts->count(),
            'new_count' => $contacts->where('is_downloaded', false)->count(),
        ]);
    }

    /**
     * Télécharger un contact au format vCard
     */
    public function downloadVCard(Request $request, SharedContact $contact)
    {
        $user = $request->user();
        
        // Vérifier que le contact appartient à l'utilisateur
        if ($contact->user_id !== $user->id) {
            return response()->json(['message' => 'Contact non trouvé.'], 404);
        }

        // Générer le contenu vCard
        $vcardContent = $contact->generateVCardContent();
        
        // Marquer comme téléchargé
        $contact->update([
            'is_downloaded' => true,
            'downloaded_at' => now(),
        ]);

        // Nom du fichier
        $filename = Str::slug($contact->full_name, '_') . '.vcf';

        return response($vcardContent)
            ->header('Content-Type', 'text/vcard; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * Télécharger tous les contacts non téléchargés en un seul fichier vCard
     */
    public function downloadAllVCards(Request $request)
    {
        $user = $request->user();
        
        $contacts = SharedContact::where('user_id', $user->id)
            ->notDownloaded()
            ->get();

        if ($contacts->isEmpty()) {
            return response()->json(['message' => 'Aucun nouveau contact à télécharger.'], 404);
        }

        // Générer un fichier vCard combiné
        $vcardContent = '';
        foreach ($contacts as $contact) {
            $vcardContent .= $contact->generateVCardContent();
            
            // Marquer comme téléchargé
            $contact->update([
                'is_downloaded' => true,
                'downloaded_at' => now(),
            ]);
        }

        $filename = 'contacts_' . date('Y-m-d_H-i-s') . '.vcf';

        return response($vcardContent)
            ->header('Content-Type', 'text/vcard; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * Supprimer un contact
     */
    public function destroy(Request $request, SharedContact $contact)
    {
        $user = $request->user();
        
        // Vérifier que le contact appartient à l'utilisateur
        if ($contact->user_id !== $user->id) {
            return response()->json(['message' => 'Contact non trouvé.'], 404);
        }

        $contact->delete();

        return response()->json(['message' => 'Contact supprimé avec succès.']);
    }

    /**
     * Supprimer les vCards expirés (plus de 24h après téléchargement)
     * Cette méthode peut être appelée via une commande Artisan ou un cron job
     */
    public static function cleanupExpiredVCards()
    {
        $expiredContacts = SharedContact::expiredVCards()->get();
        $count = $expiredContacts->count();
        
        foreach ($expiredContacts as $contact) {
            // Supprimer le fichier vCard s'il existe
            if ($contact->vcard_path && Storage::exists($contact->vcard_path)) {
                Storage::delete($contact->vcard_path);
            }
            
            // Supprimer le contact de la base de données
            $contact->delete();
        }
        
        if ($count > 0) {
            Log::info("Nettoyage: {$count} contact(s) expiré(s) supprimé(s).");
        }
        
        return $count;
    }
}

