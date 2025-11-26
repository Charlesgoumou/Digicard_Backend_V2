<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Mail;
use App\Mail\VerificationCodeMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    /**
     * Envoie un code de vérification à l'utilisateur.
     */
    private function sendVerificationCode(User $user)
    {
        try {
            $code = rand(100000, 999999);
            $user->forceFill([
                'verification_code' => (string)$code,
                'verification_code_expires_at' => now()->addMinutes(15)
            ])->save();
            Mail::to($user->email)->send(new VerificationCodeMail($code));
        } catch (\Exception $e) {
            Log::error('Échec de l\'envoi de l\'email de vérification à ' . $user->email . ': ' . $e->getMessage());
        }
    }

    /**
     * Gère l'inscription de l'utilisateur.
     */
     public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8|confirmed',
            'user_type' => 'required|in:individual,business',
            'company_name' => 'required_if:user_type,business|nullable|string|max:255',
            // Numéro de téléphone guinéen obligatoire au format +224XXXXXXXXX (9 chiffres)
            'phone' => ['required', 'string', 'regex:/^\+224[0-9]{9}$/'],
        ]);

        $role = ($request->user_type === 'business') ? 'business_admin' : 'individual';
        $companyName = ($role === 'business_admin') ? $request->company_name : null;
        // ✅ MODIFICATION: Définir account_type en fonction de user_type pour l'inscription classique
        $accountType = ($request->user_type === 'business') ? 'company' : 'individual';

        // ✅ Vérifier l'unicité de la combinaison (email, role)
        $existingUser = User::where('email', $request->email)
            ->where('role', $role)
            ->first();

        if ($existingUser) {
            throw ValidationException::withMessages([
                'email' => ['Vous avez déjà un compte ' . ($role === 'individual' ? 'personnel' : 'entreprise') . ' avec cet email.'],
            ]);
        }

        $validatedData = $request->only(['name', 'email', 'password', 'user_type', 'company_name', 'phone']);

        // --- ✅ GÉNÉRATION DU USERNAME UNIQUE ---
        $baseUsername = Str::slug($validatedData['name'], '.'); // ex: mamadi.sylla
        $username = $baseUsername;
        $counter = 1;
        // Boucle tant que le username existe déjà
        while (User::where('username', $username)->exists()) {
            $username = $baseUsername . '.' . $counter;
            $counter++;
        }
        // -----------------------------------------

        $user = User::create([
            'name' => $validatedData['name'],
            'email' => $validatedData['email'],
            'password' => Hash::make($validatedData['password']),
            'username' => $username, // <-- Ajout du username généré
            'role' => $role,
            'account_type' => $accountType, // ✅ MODIFICATION: Définir account_type lors de l'inscription classique
            'company_name' => $companyName,
            'initial_password_set' => ($role !== 'employee'),
            // Sauvegarder le téléphone principal et l'ajouter aussi dans la liste JSON
            'vcard_phone' => $validatedData['phone'],
            'phone_numbers' => [$validatedData['phone']],
        ]);

        $this->sendVerificationCode($user);

        // Notification super admin : nouvel utilisateur créé
        try {
            \App\Models\AdminNotification::create([
                'type' => 'user_registered',
                'user_id' => $user->id,
                'message' => 'Nouvelle inscription: ' . $user->name . ' (' . ($role === 'business_admin' ? 'Entreprise' : 'Particulier') . ')',
                'url' => route('profile.public.show', ['user' => $user->username]),
                'meta' => [
                    'role' => $role,
                    'email' => $user->email,
                ],
            ]);
        } catch (\Throwable $t) {
            // No-op: ne pas bloquer l'inscription si la notification échoue
        }

        return response()->json([
            'message' => 'Inscription réussie. Veuillez vérifier votre email pour le code.',
            'verification_required' => true,
            'email' => $user->email
        ], 201);
    }

    /**
     * Gère la connexion de l'utilisateur.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'account_type' => 'nullable|in:individual,business',
        ]);

        // ✅ Vérifier combien de comptes existent avec cet email
        $users = User::where('email', $request->email)->get();

        if ($users->isEmpty()) {
            throw ValidationException::withMessages([
                'email' => ['Les informations de connexion ne correspondent pas.'],
            ]);
        }

        // ✅ Si c'est un employé, on le traite séparément (pas de choix de compte)
        $employeeUser = $users->firstWhere('role', 'employee');
        if ($employeeUser && !$request->account_type) {
            // Vérifier le mot de passe
            if (!Hash::check($request->password, $employeeUser->password)) {
                throw ValidationException::withMessages([
                    'email' => ['Les informations de connexion ne correspondent pas.'],
                ]);
            }

            // ✅ Vérifier si l'utilisateur est suspendu
            if ($employeeUser->is_suspended) {
                throw ValidationException::withMessages([
                    'email' => ['Votre compte a été suspendu. Veuillez contacter l\'administrateur.'],
                ]);
            }

            // ✅ TOUJOURS envoyer un code 2FA à chaque connexion
            $this->sendVerificationCode($employeeUser);
            
            return response()->json([
                'message' => 'Code de vérification 2FA envoyé par email.',
                'two_factor_required' => true,
                'email' => $employeeUser->email,
                'account_type' => 'employee'
            ]);
        }

        // ✅ Filtrer uniquement les comptes non-employés pour le choix de compte
        $nonEmployeeUsers = $users->whereIn('role', ['individual', 'business_admin'])->values();

        if ($nonEmployeeUsers->isEmpty()) {
            throw ValidationException::withMessages([
                'email' => ['Les informations de connexion ne correspondent pas.'],
            ]);
        }

        // ✅ Si plusieurs comptes non-employés existent et qu'aucun type n'est spécifié
        if ($nonEmployeeUsers->count() > 1 && !$request->account_type) {
            // Vérifier d'abord le mot de passe avec n'importe quel compte
            $passwordValid = false;
            foreach ($nonEmployeeUsers as $user) {
                if (Hash::check($request->password, $user->password)) {
                    $passwordValid = true;
                    break;
                }
            }

            if (!$passwordValid) {
                throw ValidationException::withMessages([
                    'email' => ['Les informations de connexion ne correspondent pas.'],
                ]);
            }

            // Retourner la liste des types de comptes disponibles
            return response()->json([
                'multiple_accounts' => true,
                'message' => 'Vous avez plusieurs comptes. Veuillez choisir le type de compte.',
                'available_accounts' => $nonEmployeeUsers->map(function($u) {
                    return [
                        'type' => $u->role === 'business_admin' ? 'business' : 'individual',
                        'name' => $u->name,
                        'company_name' => $u->company_name,
                    ];
                })->values()
            ], 200);
        }

        // ✅ Déterminer le rôle recherché
        $targetRole = null;
        if ($request->account_type) {
            $targetRole = ($request->account_type === 'business') ? 'business_admin' : 'individual';
        } elseif ($nonEmployeeUsers->count() === 1) {
            $targetRole = $nonEmployeeUsers->first()->role;
        }

        // ✅ Vérifier que le rôle cible est défini
        if (!$targetRole) {
            throw ValidationException::withMessages([
                'email' => ['Impossible de déterminer le type de compte.'],
            ]);
        }

        // ✅ Trouver l'utilisateur spécifique avec (email, role)
        $user = User::where('email', $request->email)
            ->where('role', $targetRole)
            ->first();

        // ✅ Vérifier que l'utilisateur existe
        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['Aucun compte ' . ($targetRole === 'business_admin' ? 'entreprise' : 'personnel') . ' trouvé avec cet email.'],
            ]);
        }

        // ✅ Vérifier le mot de passe
        if (!Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Les informations de connexion ne correspondent pas.'],
            ]);
        }

        // ✅ Vérifier si l'utilisateur est suspendu
        if ($user->is_suspended) {
            throw ValidationException::withMessages([
                'email' => ['Votre compte a été suspendu. Veuillez contacter l\'administrateur.'],
            ]);
        }

        // ✅ TOUJOURS envoyer un code 2FA à chaque connexion
        $this->sendVerificationCode($user);
        
        return response()->json([
            'message' => 'Code de vérification 2FA envoyé par email.',
            'two_factor_required' => true,
            'email' => $user->email,
            'account_type' => $request->account_type
        ]);
    }

    /**
     * Vérifie le code 2FA de l'utilisateur.
     */
    public function verify(Request $request)
    {
        $validatedData = $request->validate([
            'email' => 'required|email|exists:users,email',
            'code' => 'required|string|digits:6',
            'account_type' => 'nullable|in:individual,business,employee',
        ]);

        // ✅ Chercher l'utilisateur par email ET code de vérification
        // Si account_type est fourni, on filtre aussi par rôle
        $query = User::where('email', $validatedData['email'])
            ->where('verification_code', $validatedData['code']);

        // Si un type de compte est spécifié, filtrer par rôle
        if (isset($validatedData['account_type'])) {
            if ($validatedData['account_type'] === 'business') {
                $query->where('role', 'business_admin');
            } elseif ($validatedData['account_type'] === 'individual') {
                $query->where('role', 'individual');
            } elseif ($validatedData['account_type'] === 'employee') {
                $query->where('role', 'employee');
            }
        }

        $user = $query->first();

        if (!$user) {
            return response()->json(['message' => 'Code invalide.'], 422);
        }

        // Vérifier l'expiration du code
        if (!$user->verification_code_expires_at || now()->gt($user->verification_code_expires_at)) {
            $this->sendVerificationCode($user);
            return response()->json(['message' => 'Le code a expiré. Un nouveau code a été envoyé.'], 422);
        }

        // ✅ Marquer l'email comme vérifié si ce n'est pas déjà fait
        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified(); // Définit email_verified_at
        }

        // Nettoyer les codes de vérification
        $user->forceFill([
            'verification_code' => null,
            'verification_code_expires_at' => null
        ])->save();

        // ✅ CRITIQUE: Connecter l'utilisateur et sauvegarder la session
        Auth::login($user);
        
        // ✅ CRITIQUE: Régénérer la session pour la sécurité et forcer l'envoi du cookie
        $request->session()->regenerate();
        
        // ✅ CRITIQUE: Sauvegarder la session pour garantir que le cookie est envoyé
        $request->session()->save();

        // ✅ Vérifier si l'employé doit changer son mot de passe
        if ($user->role === 'employee' && $user->password_reset_required) {
            return response()->json([
                'user' => $user->fresh(),
                'message' => '2FA validé. Veuillez définir votre mot de passe.',
                'password_reset_required' => true
            ]);
        }

        return response()->json([
            'user' => $user->fresh(),
            'message' => '2FA validé avec succès. Connexion réussie!'
        ]);
    }

     /**
     * Renvoie le code de vérification.
     * 
     * Cette méthode peut être utilisée pour :
     * 1. Renvoyer le code lors de l'inscription (compte non vérifié)
     * 2. Renvoyer le code 2FA lors de la connexion (compte vérifié mais 2FA requis)
     */
    public function resendVerification(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'account_type' => 'nullable|in:individual,business,employee',
        ]);

        $email = $request->email;
        $accountType = $request->account_type;

        // Si un type de compte est spécifié, chercher ce compte spécifique
        if ($accountType) {
            $targetRole = null;
            if ($accountType === 'business') {
                $targetRole = 'business_admin';
            } elseif ($accountType === 'individual') {
                $targetRole = 'individual';
            } elseif ($accountType === 'employee') {
                $targetRole = 'employee';
            }

            if ($targetRole) {
                $user = User::where('email', $email)
                    ->where('role', $targetRole)
                    ->first();

                if ($user) {
                    // Vérifier si l'utilisateur est suspendu
                    if ($user->is_suspended) {
                        return response()->json(['message' => 'Votre compte a été suspendu. Veuillez contacter l\'administrateur.'], 403);
                    }

                    // Renvoyer le code (même si le compte est vérifié, car c'est pour le 2FA de connexion)
                    $this->sendVerificationCode($user);
                    return response()->json(['message' => 'Un nouveau code de vérification a été envoyé.']);
                }
            }
        }

        // Si aucun type de compte n'est spécifié, chercher d'abord un compte non vérifié
        $user = User::where('email', $email)
            ->whereNull('email_verified_at')
            ->orderBy('created_at', 'desc')
            ->first();

        // Si aucun compte non vérifié n'est trouvé, chercher le compte le plus récent (pour le 2FA de connexion)
        if (!$user) {
            $user = User::where('email', $email)
                ->orderBy('created_at', 'desc')
                ->first();
        }

        if (!$user) {
            return response()->json(['message' => 'Aucun compte trouvé avec cet email.'], 404);
        }

        // Vérifier si l'utilisateur est suspendu
        if ($user->is_suspended) {
            return response()->json(['message' => 'Votre compte a été suspendu. Veuillez contacter l\'administrateur.'], 403);
        }

        // Renvoyer le code (même si le compte est vérifié, car c'est pour le 2FA de connexion)
        $this->sendVerificationCode($user);
        return response()->json(['message' => 'Un nouveau code de vérification a été envoyé.']);
    }

    /**
     * Récupère l'utilisateur authentifié.
     */
    /**
     * Retourne l'utilisateur actuellement authentifié, ou null si non authentifié.
     * Cette route est publique pour permettre au frontend de vérifier l'état d'authentification.
     */
    public function user(Request $request)
    {
        // Cette route est publique et doit retourner null si aucun utilisateur n'est authentifié
        // IMPORTANT: Pour éviter les problèmes de session "fantôme", nous retournons null
        // sauf si un token Bearer valide est présent OU si l'utilisateur est vraiment authentifié via session
        // 
        // Le problème : Le middleware EnsureFrontendRequestsAreStateful peut créer des sessions
        // automatiquement, mais Auth::guard('web')->check() devrait retourner false si aucun
        // utilisateur n'est vraiment authentifié via Auth::login()
        
        $user = null;
        
        // Méthode 1: Vérifier si un token Bearer est présent (authentification API)
        if ($request->bearerToken()) {
            try {
                $user = $request->user('sanctum');
                if ($user && !\App\Models\User::where('id', $user->id)->exists()) {
                    $user = null;
                }
            } catch (\Exception $e) {
                $user = null;
            }
        }
        
        // Méthode 2: Vérifier l'authentification via session web (SPA Sanctum)
        // IMPORTANT: Pour éviter les sessions "fantôme", nous ne vérifions les sessions
        // QUE si Auth::guard('web')->check() retourne true ET que l'utilisateur existe vraiment.
        // 
        // Le problème : Le middleware EnsureFrontendRequestsAreStateful peut créer des sessions
        // automatiquement pour les requêtes stateful (via /sanctum/csrf-cookie), mais cela ne
        // signifie PAS que l'utilisateur est authentifié. Auth::guard('web')->check() devrait
        // retourner false dans ce cas, mais si des cookies de session d'un utilisateur précédent
        // existent, cela peut créer une session "fantôme".
        //
        // Solution : Vérifier strictement que l'utilisateur existe vraiment dans la base de données
        // et que la session contient vraiment un ID utilisateur valide.
        if (!$user && $request->hasSession()) {
            try {
                // Vérifier si l'utilisateur est authentifié via session
                // Si Auth::guard('web')->check() retourne false, alors pas d'utilisateur authentifié
                if (\Illuminate\Support\Facades\Auth::guard('web')->check()) {
                    $sessionUserId = \Illuminate\Support\Facades\Auth::guard('web')->id();
                    
                    // Si un ID utilisateur est présent dans la session, vérifier qu'il existe vraiment
                    if ($sessionUserId) {
                        // Récupérer l'utilisateur depuis la base de données
                        $freshUser = \App\Models\User::find($sessionUserId);
                        
                        // Vérifier que l'utilisateur existe toujours, n'est pas suspendu et a vérifié son email
                        if ($freshUser && !$freshUser->is_suspended && $freshUser->email_verified_at) {
                        // ✅ SIMPLIFICATION: Utiliser directement l'utilisateur de la session
                        // Auth::guard('web')->user() devrait retourner le même utilisateur que find()
                                $user = $freshUser;
                        } else {
                            // L'utilisateur n'existe plus, est suspendu ou n'a pas vérifié son email
                            // Retourner null sans invalider la session (elle sera invalidée naturellement)
                            $user = null;
                        }
                    } else {
                        // Pas d'ID utilisateur dans la session
                        $user = null;
                    }
                }
                // Si Auth::guard('web')->check() retourne false, alors $user reste null
            } catch (\Exception $e) {
                // En cas d'erreur, continuer avec user = null
                // Ne pas logger l'erreur pour éviter de polluer les logs avec des erreurs attendues
                $user = null;
            }
        }
        
        // Si aucun utilisateur authentifié, retourner null
        if (!$user) {
            return response()->json(['user' => null], 200);
        }

        // ✅ SIMPLIFICATION: Vérifications finales de sécurité sans invalider la session
        // Si l'utilisateur n'existe plus, retourner null
        // La session sera invalidée naturellement lors de la prochaine tentative d'authentification
        $userExists = \App\Models\User::where('id', $user->id)->exists();
        
        if (!$userExists) {
            return response()->json(['user' => null], 200);
        }

        // ✅ SIMPLIFICATION: Si l'utilisateur est suspendu, retourner null sans invalider la session
        // La session sera invalidée naturellement lors de la prochaine tentative d'authentification
        if ($user->is_suspended) {
            return response()->json([
                'user' => null,
                'message' => 'Votre compte a été suspendu.'
            ], 200);
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'username' => $user->username,
                'avatar_url' => $user->avatar_url,
                'email_verified_at' => $user->email_verified_at,
                'is_admin' => $user->is_admin ?? false,
                'is_profile_complete' => $user->is_profile_complete ?? false,
                'account_type' => $user->account_type, // ✅ MODIFICATION: Inclure account_type pour permettre le verrouillage dans FinalizeRegistrationView
                'google_id' => $user->google_id, // ✅ MODIFICATION: Inclure google_id pour détecter les inscriptions classiques vs Google
            ]
        ], 200);
    }

    /**
     * Déconnecte l'utilisateur.
     */
    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['message' => 'Déconnexion réussie.']);
    }

    /**
     * Finalise le profil d'un utilisateur Google (après connexion OAuth).
     * 
     * Règles métier:
     * - Téléphone obligatoire pour tous
     * - Type de compte obligatoire (individual ou company)
     * - Si type = company, nom de l'entreprise obligatoire
     * - Met à jour is_profile_complete à true
     */
    public function completeProfile(Request $request)
    {
        $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+224[0-9]{9}$/'],
            'account_type' => 'required|in:individual,company,employee',
            'company_name' => 'required_if:account_type,company|nullable|string|max:255',
        ]);

        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Utilisateur non authentifié.'], 401);
        }

        // ✅ Si l'utilisateur est un employé invité, forcer account_type à 'employee'
        // et s'assurer que le rôle est 'employee'
        if ($user->role === 'employee') {
            $request->merge(['account_type' => 'employee']);
        }

        // Déterminer le rôle en fonction du type de compte
        if ($request->account_type === 'employee') {
            $role = 'employee';
            $companyName = null;
        } elseif ($request->account_type === 'company') {
            $role = 'business_admin';
            $companyName = $request->company_name;
        } else {
            $role = 'individual';
            $companyName = null;
        }

        // Utiliser une transaction pour garantir l'intégrité des données
        return DB::transaction(function () use ($user, $request, $role, $companyName) {
            // ✅ Pour les employés invités, ne pas faire de fusion de comptes
            // Un employé est déjà créé par le business admin et ne doit pas fusionner avec d'autres comptes
            if ($user->role === 'employee') {
                // Pour un employé, on met simplement à jour le téléphone et is_profile_complete
                // Pas de logique de fusion ni de changement de rôle
                $user = User::where('id', $user->id)->lockForUpdate()->first();
                
                $updateData = [
                    'phone' => $request->phone,
                    'is_profile_complete' => true,
                    'updated_at' => now(),
                ];
                
                // Synchroniser avec vcard_phone si nécessaire
                if (!$user->vcard_phone) {
                    $updateData['vcard_phone'] = $request->phone;
                }
                
                // Ajouter le téléphone dans phone_numbers si ce n'est pas déjà le cas
                $phoneNumbers = $user->phone_numbers ?? [];
                if (!in_array($request->phone, $phoneNumbers)) {
                    $phoneNumbers[] = $request->phone;
                    $updateData['phone_numbers'] = json_encode($phoneNumbers);
                }
                
                // Mettre à jour uniquement les champs nécessaires
                DB::table('users')
                    ->where('id', $user->id)
                    ->update($updateData);
                
                // Rafraîchir l'utilisateur depuis la base de données
                $user = $user->fresh();
                
                return response()->json([
                    'message' => 'Profil d\'employé finalisé avec succès. Vous pouvez maintenant accéder à votre tableau de bord.',
                    'user' => $user,
                ]);
            }
            
            // ✅ ÉTAPE 1: Récupérer le google_id de l'utilisateur actuel (AVANT toute modification)
            $googleId = $user->google_id;

            // ✅ ÉTAPE 2: Vérifier le "Google ID Owner" (PRIORITÉ ABSOLUE)
            // Chercher si un autre utilisateur (excluant l'actuel) possède déjà ce google_id
            // Cette vérification doit être faite AVANT toute modification de $user
            $owner = null;
            if ($googleId) {
                $owner = User::where('google_id', $googleId)
                    ->where('id', '!=', $user->id)
                    ->lockForUpdate() // Verrouiller la ligne pour éviter les conditions de course
                    ->first();
            }

            // ✅ BRANCHING LOGIC - IF: Google ID Owner existe (MERGE SCENARIO)
            if ($owner) {
                Log::info("Account merge: Google ID owner found", [
                    'current_user_id' => $user->id,
                    'owner_user_id' => $owner->id,
                    'google_id' => $googleId
                ]);

                // Mettre à jour le owner avec les nouvelles données (seulement si vides)
                if (!$owner->phone) {
                    $owner->phone = $request->phone;
                }
                if (!$owner->account_type) {
                    $owner->account_type = $request->account_type;
                    $owner->role = $role;
                }
                if (!$owner->company_name && $companyName) {
                    $owner->company_name = $companyName;
                }
                $owner->is_profile_complete = true;
                
                // Synchroniser avec vcard_phone si nécessaire
                if (!$owner->vcard_phone) {
                    $owner->vcard_phone = $request->phone;
                }
                
                // Ajouter le téléphone dans phone_numbers si ce n'est pas déjà le cas
                $phoneNumbers = $owner->phone_numbers ?? [];
                if (!in_array($request->phone, $phoneNumbers)) {
                    $phoneNumbers[] = $request->phone;
                    $owner->phone_numbers = $phoneNumbers;
                }

                // Sauvegarder le owner
                $owner->save();

                // Supprimer l'utilisateur temporaire actuel
                $tempUserId = $user->id;
                $user->delete();
                Log::info("Account merge: Temporary user deleted", ['deleted_user_id' => $tempUserId]);

                // ✅ CRITIQUE: Connecter le owner et sauvegarder la session
                Auth::login($owner);
                $request->session()->regenerate();
                $request->session()->save();

                // Retourner la réponse
                return response()->json([
                    'message' => 'Profil finalisé avec succès. Compte fusionné avec votre compte existant.',
                    'user' => $owner->fresh(),
                ]);
            }

            // ✅ BRANCHING LOGIC - ELSE IF: Vérifier la collision Email + Role
            $target = User::where('email', $user->email)
                ->where('role', $role)
                ->where('id', '!=', $user->id)
                ->first();

            if ($target) {
                Log::info("Account merge: Email + Role collision found", [
                    'current_user_id' => $user->id,
                    'target_user_id' => $target->id,
                    'email' => $user->email,
                    'role' => $role
                ]);

                // ✅ CRITIQUE: Vérifier si le google_id est déjà pris par un autre utilisateur
                // (qui n'est ni le current user ni le target)
                if ($googleId && !$target->google_id) {
                    $googleIdOwner = User::where('google_id', $googleId)
                        ->where('id', '!=', $user->id)
                        ->where('id', '!=', $target->id)
                        ->lockForUpdate() // Verrouiller la ligne pour éviter les conditions de course
                        ->first();
                    
                    if ($googleIdOwner) {
                        // Le google_id est déjà pris par un autre utilisateur
                        // On ne peut pas l'assigner au target, on doit fusionner vers le googleIdOwner
                        Log::info("Account merge: Google ID already owned by another user, redirecting merge", [
                            'google_id_owner_id' => $googleIdOwner->id,
                            'target_user_id' => $target->id,
                            'current_user_id' => $user->id
                        ]);
                        
                        // Mettre à jour le googleIdOwner avec les nouvelles données
                        if (!$googleIdOwner->phone) {
                            $googleIdOwner->phone = $request->phone;
                        }
                        if (!$googleIdOwner->account_type) {
                            $googleIdOwner->account_type = $request->account_type;
                            $googleIdOwner->role = $role;
                        }
                        if (!$googleIdOwner->company_name && $companyName) {
                            $googleIdOwner->company_name = $companyName;
                        }
                        $googleIdOwner->is_profile_complete = true;
                        
                        // Synchroniser avec vcard_phone si nécessaire
                        if (!$googleIdOwner->vcard_phone) {
                            $googleIdOwner->vcard_phone = $request->phone;
                        }
                        
                        // Ajouter le téléphone dans phone_numbers si ce n'est pas déjà le cas
                        $phoneNumbers = $googleIdOwner->phone_numbers ?? [];
                        if (!in_array($request->phone, $phoneNumbers)) {
                            $phoneNumbers[] = $request->phone;
                            $googleIdOwner->phone_numbers = $phoneNumbers;
                        }
                        
                        // Sauvegarder le googleIdOwner
                        $googleIdOwner->save();
                        
                        // Supprimer l'utilisateur temporaire actuel
                        $tempUserId = $user->id;
                        $user->delete();
                        Log::info("Account merge: Temporary user deleted", ['deleted_user_id' => $tempUserId]);
                        
                        // ✅ CRITIQUE: Connecter le googleIdOwner et sauvegarder la session
                        Auth::login($googleIdOwner);
                        $request->session()->regenerate();
                        $request->session()->save();
                        
                        // Retourner la réponse
                        return response()->json([
                            'message' => 'Profil finalisé avec succès. Compte fusionné avec votre compte existant.',
                            'user' => $googleIdOwner->fresh(),
                        ]);
                    }
                    
                    // Le google_id n'est pas pris, on peut l'assigner au target
                    // On l'ajoutera dans updateData ci-dessous
                }

                // ✅ DERNIÈRE VÉRIFICATION: Vérifier une dernière fois juste avant la mise à jour
                // pour éviter toute condition de course avec le google_id
                if ($googleId && !$target->google_id) {
                    $finalGoogleIdCheck = User::where('google_id', $googleId)
                        ->where('id', '!=', $user->id)
                        ->where('id', '!=', $target->id)
                        ->lockForUpdate()
                        ->first();
                    
                    if ($finalGoogleIdCheck) {
                        // Le google_id est maintenant pris (condition de course détectée)
                        // Fusionner vers le finalGoogleIdCheck
                        Log::info("Account merge: Google ID collision detected in ELSE IF final check", [
                            'current_user_id' => $user->id,
                            'target_user_id' => $target->id,
                            'final_google_id_owner_id' => $finalGoogleIdCheck->id,
                            'google_id' => $googleId
                        ]);
                        
                        // Mettre à jour le finalGoogleIdCheck avec les nouvelles données
                        if (!$finalGoogleIdCheck->phone) {
                            $finalGoogleIdCheck->phone = $request->phone;
                        }
                        if (!$finalGoogleIdCheck->account_type) {
                            $finalGoogleIdCheck->account_type = $request->account_type;
                            $finalGoogleIdCheck->role = $role;
                        }
                        if (!$finalGoogleIdCheck->company_name && $companyName) {
                            $finalGoogleIdCheck->company_name = $companyName;
                        }
                        $finalGoogleIdCheck->is_profile_complete = true;
                        
                        if (!$finalGoogleIdCheck->vcard_phone) {
                            $finalGoogleIdCheck->vcard_phone = $request->phone;
                        }
                        
                        $phoneNumbers = $finalGoogleIdCheck->phone_numbers ?? [];
                        if (!in_array($request->phone, $phoneNumbers)) {
                            $phoneNumbers[] = $request->phone;
                            $finalGoogleIdCheck->phone_numbers = $phoneNumbers;
                        }
                        
                        $finalGoogleIdCheck->save();
                        
                        $tempUserId = $user->id;
                        $user->delete();
                        Log::info("Account merge: Temporary user deleted", ['deleted_user_id' => $tempUserId]);
                        
                        // ✅ CRITIQUE: Connecter et sauvegarder la session
                        Auth::login($finalGoogleIdCheck);
                        $request->session()->regenerate();
                        $request->session()->save();
                        
                        return response()->json([
                            'message' => 'Profil finalisé avec succès. Compte fusionné avec votre compte existant.',
                            'user' => $finalGoogleIdCheck->fresh(),
                        ]);
                    }
                }
                
                // Préparer les données à mettre à jour
                $updateData = [
                    'phone' => $request->phone,
                    'account_type' => $request->account_type,
                    'company_name' => $companyName,
                    'is_profile_complete' => true,
                    'updated_at' => now(),
                ];
                
                // Ajouter le google_id seulement si on a vérifié qu'il n'est pas pris (vérification finale incluse)
                if ($googleId && !$target->google_id) {
                    // Double vérification : s'assurer que le google_id n'est toujours pas pris
                    $stillAvailable = !User::where('google_id', $googleId)
                        ->where('id', '!=', $user->id)
                        ->where('id', '!=', $target->id)
                        ->exists();
                    
                    if ($stillAvailable) {
                        $updateData['google_id'] = $googleId;
                    } else {
                        Log::warning("Google ID became unavailable between checks, skipping assignment", [
                            'google_id' => $googleId,
                            'target_id' => $target->id
                        ]);
                    }
                }
                
                // Synchroniser avec vcard_phone si nécessaire
                if (!$target->vcard_phone) {
                    $updateData['vcard_phone'] = $request->phone;
                }
                
                // Ajouter le téléphone dans phone_numbers si ce n'est pas déjà le cas
                $phoneNumbers = $target->phone_numbers ?? [];
                if (!in_array($request->phone, $phoneNumbers)) {
                    $phoneNumbers[] = $request->phone;
                }
                // Encoder phone_numbers en JSON pour DB::table()
                $updateData['phone_numbers'] = json_encode($phoneNumbers);

                // ✅ CRITIQUE: Utiliser DB::table() directement au lieu de User::where()->update()
                // pour éviter que Laravel n'inclue des champs "dirty" de l'objet Eloquent.
                // Le google_id n'est inclus que si toutes les vérifications ont passé.
                DB::table('users')
                    ->where('id', $target->id)
                    ->update($updateData);
                
                // Rafraîchir le target depuis la base de données
                $target = $target->fresh();

                // Supprimer l'utilisateur temporaire actuel
                $tempUserId = $user->id;
                $user->delete();
                Log::info("Account merge: Temporary user deleted", ['deleted_user_id' => $tempUserId]);

                // ✅ CRITIQUE: Connecter le target et sauvegarder la session
                Auth::login($target);
                $request->session()->regenerate();
                $request->session()->save();

                // Retourner la réponse
                return response()->json([
                    'message' => 'Profil finalisé avec succès. Compte fusionné avec votre compte existant.',
                    'user' => $target->fresh(),
                ]);
            }

            // ✅ BRANCHING LOGIC - ELSE: Pas de collision (STANDARD SCENARIO)
            // ✅ CRITIQUE: Vérifier une dernière fois si le google_id est déjà pris
            // (double vérification pour éviter les conditions de course)
            if ($googleId) {
                $googleIdOwner = User::where('google_id', $googleId)
                    ->where('id', '!=', $user->id)
                    ->lockForUpdate() // Verrouiller la ligne pour éviter les conditions de course
                    ->first();
                
                if ($googleIdOwner) {
                    // Le google_id est déjà pris par un autre utilisateur
                    // On doit fusionner vers le googleIdOwner au lieu de mettre à jour le current user
                    Log::info("Account merge: Google ID collision detected in STANDARD scenario", [
                        'current_user_id' => $user->id,
                        'google_id_owner_id' => $googleIdOwner->id,
                        'google_id' => $googleId
                    ]);
                    
                    // Mettre à jour le googleIdOwner avec les nouvelles données
                    if (!$googleIdOwner->phone) {
                        $googleIdOwner->phone = $request->phone;
                    }
                    if (!$googleIdOwner->account_type) {
                        $googleIdOwner->account_type = $request->account_type;
                        $googleIdOwner->role = $role;
                    }
                    if (!$googleIdOwner->company_name && $companyName) {
                        $googleIdOwner->company_name = $companyName;
                    }
                    $googleIdOwner->is_profile_complete = true;
                    
                    // Synchroniser avec vcard_phone si nécessaire
                    if (!$googleIdOwner->vcard_phone) {
                        $googleIdOwner->vcard_phone = $request->phone;
                    }
                    
                    // Ajouter le téléphone dans phone_numbers si ce n'est pas déjà le cas
                    $phoneNumbers = $googleIdOwner->phone_numbers ?? [];
                    if (!in_array($request->phone, $phoneNumbers)) {
                        $phoneNumbers[] = $request->phone;
                        $googleIdOwner->phone_numbers = $phoneNumbers;
                    }
                    
                    // Sauvegarder le googleIdOwner
                    $googleIdOwner->save();
                    
                    // Supprimer l'utilisateur temporaire actuel
                    $tempUserId = $user->id;
                    $user->delete();
                    Log::info("Account merge: Temporary user deleted", ['deleted_user_id' => $tempUserId]);
                    
                    // ✅ CRITIQUE: Connecter le googleIdOwner et sauvegarder la session
                    Auth::login($googleIdOwner);
                    $request->session()->regenerate();
                    $request->session()->save();
                    
                    // Retourner la réponse
                    return response()->json([
                        'message' => 'Profil finalisé avec succès. Compte fusionné avec votre compte existant.',
                        'user' => $googleIdOwner->fresh(),
                    ]);
                }
            }
            
            // Pas de collision : mettre à jour l'utilisateur actuel normalement
            // ✅ CRITIQUE: Recharger l'utilisateur depuis la base de données AVANT toute opération
            // pour éviter que Laravel n'inclue des champs "dirty" dans la mise à jour
            $user = User::where('id', $user->id)->lockForUpdate()->first();
            
            // ✅ DERNIÈRE VÉRIFICATION: Vérifier une dernière fois juste avant la mise à jour
            // pour éviter toute condition de course
            if ($googleId) {
                $finalCheck = User::where('google_id', $googleId)
                    ->where('id', '!=', $user->id)
                    ->lockForUpdate()
                    ->exists();
                
                if ($finalCheck) {
                    // Le google_id est maintenant pris (condition de course détectée)
                    // Recharger depuis la base de données et refaire la vérification
                    $user = User::where('id', $user->id)->lockForUpdate()->first();
                    $googleId = $user->google_id;
                    
                    $finalOwner = User::where('google_id', $googleId)
                        ->where('id', '!=', $user->id)
                        ->lockForUpdate()
                        ->first();
                    
                    if ($finalOwner) {
                        // Fusionner vers le finalOwner
                        Log::info("Account merge: Google ID collision detected in final check (race condition)", [
                            'current_user_id' => $user->id,
                            'final_owner_id' => $finalOwner->id,
                            'google_id' => $googleId
                        ]);
                        
                        // Mettre à jour le finalOwner avec les nouvelles données
                        if (!$finalOwner->phone) {
                            $finalOwner->phone = $request->phone;
                        }
                        if (!$finalOwner->account_type) {
                            $finalOwner->account_type = $request->account_type;
                            $finalOwner->role = $role;
                        }
                        if (!$finalOwner->company_name && $companyName) {
                            $finalOwner->company_name = $companyName;
                        }
                        $finalOwner->is_profile_complete = true;
                        
                        if (!$finalOwner->vcard_phone) {
                            $finalOwner->vcard_phone = $request->phone;
                        }
                        
                        $phoneNumbers = $finalOwner->phone_numbers ?? [];
                        if (!in_array($request->phone, $phoneNumbers)) {
                            $phoneNumbers[] = $request->phone;
                            $finalOwner->phone_numbers = $phoneNumbers;
                        }
                        
                        $finalOwner->save();
                        
                        $tempUserId = $user->id;
                        $user->delete();
                        Log::info("Account merge: Temporary user deleted", ['deleted_user_id' => $tempUserId]);
                        
                        // ✅ CRITIQUE: Connecter et sauvegarder la session
                        Auth::login($finalOwner);
                        $request->session()->regenerate();
                        $request->session()->save();
                        
                        return response()->json([
                            'message' => 'Profil finalisé avec succès. Compte fusionné avec votre compte existant.',
                            'user' => $finalOwner->fresh(),
                        ]);
                    }
                }
            }
            
            // ✅ CRITIQUE: Utiliser DB::table() directement pour éviter que Laravel n'inclue
            // des champs "dirty" de l'objet Eloquent. Cela garantit qu'on ne met à jour
            // QUE les champs explicitement listés dans $updateData.
            
            // Pour les autres types de comptes (non employés), mettre à jour normalement
            // (Les employés sont déjà gérés plus haut dans la transaction)
            $updateData = [
                'phone' => $request->phone,
                'account_type' => $request->account_type,
                'role' => $role,
                'company_name' => $companyName,
                'is_profile_complete' => true,
                'updated_at' => now(),
            ];
            
            // Synchroniser avec vcard_phone si nécessaire
            $currentVcardPhone = $user->vcard_phone;
            if (!$currentVcardPhone) {
                $updateData['vcard_phone'] = $request->phone;
            }
            
            // Ajouter le téléphone dans phone_numbers si ce n'est pas déjà le cas
            $phoneNumbers = $user->phone_numbers ?? [];
            if (!in_array($request->phone, $phoneNumbers)) {
                $phoneNumbers[] = $request->phone;
                $updateData['phone_numbers'] = json_encode($phoneNumbers); // DB::table() nécessite JSON encodé
            }
            // Si le téléphone existe déjà, on ne modifie pas phone_numbers (pas besoin de l'inclure dans updateData)

            // ✅ CRITIQUE: Utiliser DB::table() directement au lieu de User::where()->update()
            // Cela évite complètement l'objet Eloquent et garantit qu'on ne met à jour
            // QUE les champs explicitement listés. Le google_id ne sera JAMAIS inclus.
            DB::table('users')
                ->where('id', $user->id)
                ->update($updateData);
            
            // Rafraîchir l'utilisateur depuis la base de données
            $user = $user->fresh();

            return response()->json([
                'message' => 'Profil finalisé avec succès.',
                'user' => $user->fresh(),
            ]);
        });
    }

    /**
     * API: Vérifier les types de comptes existants pour l'utilisateur actuel.
     * Utilisé pour restreindre les choix lors de la finalisation du profil.
     */
    public function getExistingAccountTypes(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Utilisateur non authentifié.'], 401);
        }

        // Récupérer tous les comptes avec le même email
        $existingAccounts = User::where('email', $user->email)
            ->where('id', '!=', $user->id) // Exclure le compte actuel (temporaire/incomplet)
            ->where('is_profile_complete', true) // Seulement les comptes complets
            ->get(['id', 'account_type', 'role']);

        $hasIndividual = $existingAccounts->contains(function ($account) {
            return $account->account_type === 'individual' || $account->role === 'individual';
        });

        $hasCompany = $existingAccounts->contains(function ($account) {
            return $account->account_type === 'company' || $account->role === 'business_admin';
        });

        return response()->json([
            'has_individual' => $hasIndividual,
            'has_company' => $hasCompany,
            'available_types' => [
                'individual' => !$hasIndividual, // Disponible si pas déjà créé
                'company' => !$hasCompany, // Disponible si pas déjà créé
            ],
        ]);
    }

    /**
     * ✅ NOUVEAU: Restaure la session de l'utilisateur via un token de paiement
     * Utilisé après une redirection externe (paiement Chap Chap Pay) où la session peut être perdue
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function restoreSession(Request $request)
    {
        try {
            $request->validate([
                'session_token' => 'required|string|size:60',
            ]);

            $token = $request->input('session_token');

            // Chercher la commande via le token
            $order = \App\Models\Order::where('payment_session_token', $token)->first();

            if (!$order) {
                Log::warning('AuthController: Tentative de restauration de session avec token invalide', [
                    'token_length' => strlen($token),
                    'token_prefix' => substr($token, 0, 10) . '...',
                ]);

                return response()->json([
                    'message' => 'Token de session invalide ou expiré.',
                    'success' => false,
                ], 404);
            }

            // Vérifier que la commande a un utilisateur associé
            if (!$order->user) {
                Log::error('AuthController: Commande trouvée mais sans utilisateur associé', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]);

                return response()->json([
                    'message' => 'Erreur lors de la restauration de la session.',
                    'success' => false,
                ], 500);
            }

            $user = $order->user;

            // ✅ CRITIQUE: Connecter l'utilisateur manuellement et sauvegarder la session
            Auth::login($user);

            // Régénérer la session pour la sécurité
            $request->session()->regenerate();
            
            // ✅ CRITIQUE: Sauvegarder la session pour garantir que le cookie est envoyé
            $request->session()->save();

            // Supprimer le token (usage unique) pour la sécurité
            $order->update(['payment_session_token' => null]);

            Log::info('AuthController: Session restaurée avec succès via token de paiement', [
                'user_id' => $user->id,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);

            return response()->json([
                'message' => 'Session restaurée avec succès.',
                'success' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('AuthController: Erreur de validation lors de la restauration de session', [
                'errors' => $e->errors(),
            ]);

            return response()->json([
                'message' => 'Token de session invalide.',
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('AuthController: Erreur lors de la restauration de session', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Erreur lors de la restauration de la session.',
                'success' => false,
            ], 500);
        }
    }

    /**
     * ✅ NOUVEAU: Échange un token de paiement contre une session
     * Le frontend appelle cette route après avoir reçu le token dans l'URL
     * Le cookie de session est créé lors de cet appel API, pas lors de la redirection
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function exchangeToken(Request $request)
    {
        try {
            $request->validate([
                'session_token' => 'required|string|size:60',
            ]);

            $token = $request->input('session_token');

            // Chercher la commande via le token
            $order = \App\Models\Order::where('payment_session_token', $token)->first();

            if (!$order) {
                Log::warning('AuthController: Tentative d\'échange de token invalide', [
                    'token_length' => strlen($token),
                    'token_prefix' => substr($token, 0, 10) . '...',
                ]);

                return response()->json([
                    'message' => 'Token de session invalide ou expiré.',
                    'success' => false,
                ], 404);
            }

            // Vérifier que la commande a un utilisateur associé
            if (!$order->user) {
                Log::error('AuthController: Commande trouvée mais sans utilisateur associé', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]);

                return response()->json([
                    'message' => 'Erreur lors de l\'échange du token.',
                    'success' => false,
                ], 500);
            }

            $user = $order->user;

            // ✅ CRITIQUE: S'assurer qu'une session existe avant de connecter l'utilisateur
            // Le middleware 'web' devrait déjà avoir initialisé la session, mais on s'en assure
            if (!$request->hasSession()) {
                $request->session()->start();
            }

            // ✅ CRITIQUE: Connecter l'utilisateur manuellement
            Auth::login($user, true); // Le deuxième paramètre 'true' force la session à être "remembered"

            // ✅ CRITIQUE: Régénérer la session pour la sécurité
            $request->session()->regenerate();
            
            // ✅ CRITIQUE: FORCER l'écriture de la session en base de données et dans le cookie
            // Nécessaire pour que la session soit persistée
            $request->session()->save();
            
            // ✅ CRITIQUE: S'assurer que le cookie de session est envoyé avec la réponse
            // En forçant l'envoi du cookie, on garantit qu'il sera disponible pour les requêtes suivantes
            $sessionCookie = config('session.cookie');
            $sessionId = $request->session()->getId();
            
            // Le cookie sera automatiquement envoyé par Laravel, mais on s'assure qu'il est bien configuré
            // en vérifiant que la session est bien sauvegardée

            // ✅ Supprimer le token (usage unique) pour la sécurité
            $order->update(['payment_session_token' => null]);

            Log::info('AuthController: Token échangé avec succès contre une session', [
                'user_id' => $user->id,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);

            // ✅ CRITIQUE: Retourner toutes les données utilisateur nécessaires pour le frontend
            // Format identique à celui de la méthode user() pour garantir la compatibilité
            return response()->json([
                'message' => 'Session restaurée',
                'success' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'username' => $user->username,
                    'avatar_url' => $user->avatar_url,
                    'email_verified_at' => $user->email_verified_at,
                    'is_admin' => $user->is_admin ?? false,
                    'is_profile_complete' => $user->is_profile_complete ?? false,
                    'account_type' => $user->account_type,
                    'google_id' => $user->google_id,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('AuthController: Erreur de validation lors de l\'échange de token', [
                'errors' => $e->errors(),
            ]);

            return response()->json([
                'message' => 'Token de session invalide.',
                'success' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('AuthController: Erreur lors de l\'échange de token', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Erreur lors de l\'échange du token.',
                'success' => false,
            ], 500);
        }
    }
}
