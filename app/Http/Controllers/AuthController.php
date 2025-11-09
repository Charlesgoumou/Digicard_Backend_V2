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

        // Connecter l'utilisateur (sans régénération de session pour Sanctum SPA)
        Auth::login($user);

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
                            // Vérification supplémentaire : s'assurer que Auth::guard('web')->user() 
                            // retourne le même utilisateur (double vérification)
                            $sessionUser = \Illuminate\Support\Facades\Auth::guard('web')->user();
                            if ($sessionUser && $sessionUser->id == $freshUser->id) {
                                $user = $freshUser;
                            } else {
                                // Incohérence : l'ID existe mais user() ne retourne pas le bon utilisateur
                                // Invalider la session
                                \Illuminate\Support\Facades\Auth::guard('web')->logout();
                                $request->session()->invalidate();
                                $request->session()->regenerateToken();
                                $user = null;
                            }
                        } else {
                            // L'utilisateur n'existe plus, est suspendu ou n'a pas vérifié son email
                            // Invalider la session
                            \Illuminate\Support\Facades\Auth::guard('web')->logout();
                            $request->session()->invalidate();
                            $request->session()->regenerateToken();
                            $user = null;
                        }
                    } else {
                        // Pas d'ID utilisateur dans la session, mais check() retourne true
                        // Cela ne devrait pas arriver, mais au cas où, invalider la session
                        \Illuminate\Support\Facades\Auth::guard('web')->logout();
                        $request->session()->invalidate();
                        $request->session()->regenerateToken();
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

        // Vérifications finales de sécurité
        $userExists = \App\Models\User::where('id', $user->id)->exists();
        
        if (!$userExists) {
            if ($user->currentAccessToken()) {
                $user->currentAccessToken()->delete();
            }
            if ($request->hasSession()) {
                \Illuminate\Support\Facades\Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            return response()->json(['user' => null], 200);
        }

        if ($user->is_suspended) {
            if ($user->currentAccessToken()) {
                $user->currentAccessToken()->delete();
            }
            if ($request->hasSession()) {
                \Illuminate\Support\Facades\Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
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
}
