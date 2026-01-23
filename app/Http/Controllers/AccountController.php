<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Mail\VerificationCodeMail;
use App\Mail\PasswordChangedNotification;

class AccountController extends Controller
{
    /**
     * Met à jour les informations de compte utilisateur (nom, email, téléphone, mot de passe).
     * Pour le changement d'email, envoie un code de vérification à la nouvelle adresse.
     */
    public function update(Request $request)
    {
        $user = $request->user();

        // Validation des données
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'email',
                Rule::unique('users')->ignore($user->id)
            ],
            'phone' => 'nullable|string|regex:/^\+224[0-9]{9}$/',
            'company_name' => 'sometimes|required|string|max:255',
            'current_password' => 'required_with:password',
            'password' => 'required_with:current_password|min:8|confirmed',
        ]);

        // Si changement d'email
        if ($request->has('email') && $request->email !== $user->email) {
            $newEmail = $request->email;
            
            // Vérifier que le nouvel email n'est pas déjà utilisé
            $existingUser = \App\Models\User::where('email', $newEmail)
                ->where('id', '!=', $user->id)
                ->first();
            
            if ($existingUser) {
                return response()->json([
                    'message' => 'Cette adresse email est déjà utilisée.',
                    'errors' => ['email' => ['Cette adresse email est déjà utilisée.']]
                ], 422);
            }

            // Générer un code de vérification
            $code = rand(100000, 999999);
            
            // Stocker le nouvel email et le code dans la base de données
            $user->pending_email = $newEmail;
            $user->email_change_code = (string)$code;
            $user->email_change_code_expires_at = now()->addMinutes(15);
            $user->save();

            // Envoyer le code de vérification à la nouvelle adresse email
            try {
                Mail::to($newEmail)->send(new VerificationCodeMail($code));
            } catch (\Exception $e) {
                Log::error('Échec de l\'envoi de l\'email de vérification à ' . $newEmail . ': ' . $e->getMessage());
                return response()->json([
                    'message' => 'Erreur lors de l\'envoi de l\'email de vérification. Veuillez réessayer.',
                ], 500);
            }

            // Ne pas mettre à jour l'email immédiatement - attendre la vérification
            unset($validated['email']);
            
            return response()->json([
                'message' => 'Un code de vérification a été envoyé à votre nouvelle adresse email. Veuillez le saisir pour confirmer le changement.',
                'requires_verification' => true,
                'pending_email' => $newEmail,
                'user' => $user->fresh()
            ]);
        }

        // Si le téléphone est fourni, mettre à jour aussi vcard_phone et phone_numbers
        if ($request->has('phone')) {
            $phone = $request->phone;
            if ($phone === null || $phone === '') {
                // Permettre de supprimer le téléphone
                $validated['vcard_phone'] = null;
                // Garder phone_numbers tel quel ou le vider
                $phoneNumbers = $user->phone_numbers ?? [];
                if (!empty($phoneNumbers) && is_array($phoneNumbers)) {
                    // Retirer le premier élément (téléphone principal) s'il existe
                    array_shift($phoneNumbers);
                    $validated['phone_numbers'] = array_values($phoneNumbers);
                } else {
                    $validated['phone_numbers'] = [];
                }
            } else {
                // Mettre à jour avec le nouveau téléphone
                $validated['vcard_phone'] = $phone;
                // Mettre à jour phone_numbers avec le nouveau téléphone en premier
                $phoneNumbers = $user->phone_numbers ?? [];
                if (!empty($phoneNumbers) && is_array($phoneNumbers)) {
                    // Retirer l'ancien téléphone s'il existe et ajouter le nouveau en premier
                    $phoneNumbers = array_values(array_filter($phoneNumbers, function($p) use ($phone) {
                        return $p !== $phone;
                    }));
                    array_unshift($phoneNumbers, $phone);
                } else {
                    $phoneNumbers = [$phone];
                }
                $validated['phone_numbers'] = $phoneNumbers;
            }
            unset($validated['phone']); // Ne pas stocker 'phone' directement
        }

        // Vérifier le mot de passe actuel si changement de mot de passe
        $passwordChanged = false;
        if ($request->has('current_password')) {
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'message' => 'Le mot de passe actuel est incorrect.'
                ], 400);
            }
            $validated['password'] = Hash::make($validated['password']);
            // Supprimer les champs de validation qui ne doivent pas être stockés
            unset($validated['current_password']);
            unset($validated['password_confirmation']);
            $passwordChanged = true;
        }

        // Mettre à jour uniquement les champs fournis
        $user->fill($validated);
        $user->save();

        // Envoyer un email de notification si le mot de passe a été changé
        if ($passwordChanged) {
            try {
                Mail::to($user->email)->send(new PasswordChangedNotification($user));
            } catch (\Exception $e) {
                Log::error('Échec de l\'envoi de l\'email de notification de changement de mot de passe à ' . $user->email . ': ' . $e->getMessage());
                // Ne pas bloquer la réponse même si l'email échoue
            }
        }

        return response()->json([
            'message' => 'Informations mises à jour avec succès',
            'user' => $user->fresh()
        ]);
    }

    /**
     * Vérifie le code de changement d'email et finalise le changement.
     */
    public function verifyEmailChange(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'code' => 'required|string|size:6',
        ]);

        // Vérifier que l'utilisateur a un changement d'email en attente
        if (!$user->pending_email || !$user->email_change_code) {
            return response()->json([
                'message' => 'Aucun changement d\'email en attente.',
            ], 400);
        }

        // Vérifier que le code n'a pas expiré
        if (!$user->email_change_code_expires_at || now()->gt($user->email_change_code_expires_at)) {
            // Nettoyer les champs
            $user->pending_email = null;
            $user->email_change_code = null;
            $user->email_change_code_expires_at = null;
            $user->save();

            return response()->json([
                'message' => 'Le code de vérification a expiré. Veuillez recommencer le processus de changement d\'email.',
            ], 400);
        }

        // Vérifier le code
        if ($user->email_change_code !== $validated['code']) {
            return response()->json([
                'message' => 'Code de vérification incorrect.',
                'errors' => ['code' => ['Le code de vérification est incorrect.']]
            ], 422);
        }

        // Le code est valide, finaliser le changement d'email
        $newEmail = $user->pending_email;
        $user->email = $newEmail;
        $user->pending_email = null;
        $user->email_change_code = null;
        $user->email_change_code_expires_at = null;
        $user->email_verified_at = null; // Réinitialiser la vérification d'email
        $user->save();

        return response()->json([
            'message' => 'Adresse email mise à jour avec succès !',
            'user' => $user->fresh()
        ]);
    }

    /**
     * Renvoie un nouveau code de vérification pour le changement d'email.
     */
    public function resendEmailChangeCode(Request $request)
    {
        $user = $request->user();

        // Vérifier que l'utilisateur a un changement d'email en attente
        if (!$user->pending_email) {
            return response()->json([
                'message' => 'Aucun changement d\'email en attente.',
            ], 400);
        }

        // Générer un nouveau code
        $code = rand(100000, 999999);
        $user->email_change_code = (string)$code;
        $user->email_change_code_expires_at = now()->addMinutes(15);
        $user->save();

        // Envoyer le code à la nouvelle adresse email
        try {
            Mail::to($user->pending_email)->send(new VerificationCodeMail($code));
        } catch (\Exception $e) {
            Log::error('Échec de l\'envoi de l\'email de vérification à ' . $user->pending_email . ': ' . $e->getMessage());
            return response()->json([
                'message' => 'Erreur lors de l\'envoi de l\'email de vérification. Veuillez réessayer.',
            ], 500);
        }

        return response()->json([
            'message' => 'Un nouveau code de vérification a été envoyé à votre nouvelle adresse email.',
        ]);
    }

    /**
     * Active ou désactive la vérification 2FA pour l'utilisateur connecté
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggleTwoFactor(Request $request)
    {
        $user = $request->user();

        // Empêcher de modifier la 2FA d'un super admin
        if ($user->is_admin) {
            return response()->json([
                'message' => 'Impossible de modifier la 2FA d\'un super administrateur'
            ], 403);
        }

        // Basculer le statut de la 2FA
        $user->two_factor_enabled = !$user->two_factor_enabled;
        $user->save();

        // Logger l'action
        Log::info('User 2FA toggled by user', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'two_factor_enabled' => $user->two_factor_enabled,
            'timestamp' => now(),
        ]);

        $message = $user->two_factor_enabled
            ? 'Authentification à double facteur activée avec succès'
            : 'Authentification à double facteur désactivée avec succès';

        return response()->json([
            'message' => $message,
            'user' => $user->fresh(),
            'two_factor_enabled' => $user->two_factor_enabled
        ]);
    }

    /**
     * Récupère tous les comptes associés à la même adresse email
     */
    public function getLinkedAccounts(Request $request)
    {
        $user = $request->user();
        
        // Récupérer tous les comptes avec le même email
        $linkedAccounts = \App\Models\User::where('email', $user->email)
            ->where('is_profile_complete', true) // Seulement les comptes complets
            ->select('id', 'name', 'email', 'role', 'company_name', 'username', 'avatar_url', 'created_at')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function($account) {
                return [
                    'id' => $account->id,
                    'name' => $account->name,
                    'email' => $account->email,
                    'role' => $account->role,
                    'role_label' => $account->role === 'business_admin' ? 'Entreprise' : ($account->role === 'individual' ? 'Particulier' : 'Employé'),
                    'company_name' => $account->company_name,
                    'username' => $account->username,
                    'avatar_url' => $account->avatar_url,
                    'is_current' => $account->id === auth()->id(),
                    'created_at' => $account->created_at,
                ];
            });

        return response()->json([
            'linked_accounts' => $linkedAccounts,
            'current_account_id' => $user->id,
        ]);
    }

    /**
     * Crée un nouveau compte avec le même email
     */
    public function createLinkedAccount(Request $request)
    {
        $user = $request->user();
        
        $validated = $request->validate([
            'account_type' => 'required|in:individual,business',
            'name' => 'required|string|max:255',
            'company_name' => 'required_if:account_type,business|nullable|string|max:255',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $role = ($validated['account_type'] === 'business') ? 'business_admin' : 'individual';
        
        // Vérifier qu'un compte avec ce rôle n'existe pas déjà avec cet email
        $existingAccount = \App\Models\User::where('email', $user->email)
            ->where('role', $role)
            ->where('is_profile_complete', true)
            ->first();

        if ($existingAccount) {
            return response()->json([
                'message' => 'Vous avez déjà un compte ' . ($role === 'business_admin' ? 'entreprise' : 'particulier') . ' avec cet email.',
                'errors' => ['account_type' => ['Ce type de compte existe déjà.']]
            ], 422);
        }

        // Vérifier les restrictions selon le rôle actuel
        if ($user->role === 'employee') {
            // Les employés peuvent créer particulier et business_admin
            if ($role === 'employee') {
                return response()->json([
                    'message' => 'Vous ne pouvez pas créer un autre compte employé.',
                    'errors' => ['account_type' => ['Type de compte non autorisé.']]
                ], 422);
            }
        } elseif ($user->role === 'business_admin') {
            // Les business_admin peuvent créer particulier
            if ($role === 'business_admin') {
                return response()->json([
                    'message' => 'Vous avez déjà un compte entreprise.',
                    'errors' => ['account_type' => ['Type de compte non autorisé.']]
                ], 422);
            }
        } elseif ($user->role === 'individual') {
            // Les particuliers peuvent créer business_admin
            if ($role === 'individual') {
                return response()->json([
                    'message' => 'Vous avez déjà un compte particulier.',
                    'errors' => ['account_type' => ['Type de compte non autorisé.']]
                ], 422);
            }
        }

        // Générer un username unique
        $baseUsername = Str::slug($validated['name']);
        $username = $baseUsername;
        $counter = 1;
        while (\App\Models\User::where('username', $username)->exists()) {
            $username = $baseUsername . $counter;
            $counter++;
        }

        // Créer le nouveau compte
        $newUser = \App\Models\User::create([
            'name' => $validated['name'],
            'email' => $user->email,
            'password' => Hash::make($validated['password']),
            'role' => $role,
            'company_name' => $validated['company_name'] ?? null,
            'username' => $username,
            'is_profile_complete' => true,
            'email_verified_at' => $user->email_verified_at, // Utiliser la même date de vérification
        ]);

        return response()->json([
            'message' => 'Compte créé avec succès.',
            'account' => [
                'id' => $newUser->id,
                'name' => $newUser->name,
                'email' => $newUser->email,
                'role' => $newUser->role,
                'role_label' => $newUser->role === 'business_admin' ? 'Entreprise' : 'Particulier',
                'company_name' => $newUser->company_name,
                'username' => $newUser->username,
            ]
        ], 201);
    }

    /**
     * Bascule vers un autre compte lié (même email) sans demander de mot de passe.
     * L'utilisateur est déjà authentifié, donc on peut basculer directement.
     */
    public function switchToLinkedAccount(Request $request)
    {
        $currentUser = $request->user();

        $validated = $request->validate([
            'target_account_id' => 'required|integer|exists:users,id',
        ]);

        $targetAccountId = $validated['target_account_id'];

        // Vérifier que le compte cible existe et a le même email
        $targetAccount = \App\Models\User::where('id', $targetAccountId)
            ->where('email', $currentUser->email)
            ->where('is_profile_complete', true) // Seulement les comptes complets
            ->first();

        if (!$targetAccount) {
            return response()->json([
                'message' => 'Compte introuvable ou non autorisé.',
                'errors' => ['target_account_id' => ['Ce compte n\'existe pas ou n\'est pas lié à votre email.']]
            ], 404);
        }

        // Vérifier que ce n'est pas déjà le compte actuel
        if ($targetAccount->id === $currentUser->id) {
            return response()->json([
                'message' => 'Vous êtes déjà connecté à ce compte.',
                'errors' => ['target_account_id' => ['Ce compte est déjà actif.']]
            ], 422);
        }

        // Vérifier si l'utilisateur est suspendu
        if ($targetAccount->is_suspended) {
            return response()->json([
                'message' => 'Ce compte a été suspendu.',
                'errors' => ['target_account_id' => ['Ce compte a été suspendu. Veuillez contacter l\'administrateur.']]
            ], 403);
        }

        // Connecter l'utilisateur au compte cible via le guard web (pour les sessions)
        Auth::guard('web')->login($targetAccount);

        // Régénérer la session pour la sécurité
        $request->session()->regenerate();
        $request->session()->save();

        // Retourner les données du nouveau compte
        return response()->json([
            'message' => 'Basculement vers le compte ' . ($targetAccount->role === 'business_admin' ? 'entreprise' : ($targetAccount->role === 'individual' ? 'particulier' : 'employé')) . ' réussi !',
            'user' => [
                'id' => $targetAccount->id,
                'name' => $targetAccount->name,
                'email' => $targetAccount->email,
                'role' => $targetAccount->role,
                'username' => $targetAccount->username,
                'avatar_url' => $targetAccount->avatar_url,
                'company_name' => $targetAccount->company_name,
                'email_verified_at' => $targetAccount->email_verified_at,
                'is_admin' => $targetAccount->is_admin ?? false,
                'is_profile_complete' => $targetAccount->is_profile_complete ?? false,
            ]
        ], 200);
    }
}
