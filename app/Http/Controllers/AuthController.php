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
     */
    public function resendVerification(Request $request)
    {
        $request->validate(['email' => 'required|email|exists:users,email']);

        // ✅ Trouver le compte le plus récemment créé et non vérifié avec cet email
        $user = User::where('email', $request->email)
            ->whereNull('email_verified_at')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$user) {
            // Tous les comptes avec cet email sont déjà vérifiés
            return response()->json(['message' => 'Tous les comptes avec cet email sont déjà vérifiés.'], 400);
        }

        $this->sendVerificationCode($user);
        return response()->json(['message' => 'Un nouveau code de vérification a été envoyé.']);
    }

    /**
     * Récupère l'utilisateur authentifié.
     */
    public function user(Request $request)
    {
        return $request->user();
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
