<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Illuminate\Support\Facades\Log;

class SocialController extends Controller
{
    /**
     * Construit une URL frontend en gérant les trailing slashes.
     * 
     * @param string $path Le chemin à ajouter (ex: '/finaliser-inscription')
     * @return string L'URL complète
     */
    private function buildFrontendUrl(string $path = ''): string
    {
        $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173'));
        
        // ✅ CORRECTION: Nettoyer l'URL pour ne prendre que la première URL valide
        // Si plusieurs URLs sont séparées par une virgule, prendre seulement la première
        if (strpos($frontendUrl, ',') !== false) {
            $urls = explode(',', $frontendUrl);
            $frontendUrl = trim($urls[0]); // Prendre la première URL
        }
        $frontendUrl = trim($frontendUrl);
        
        // Supprimer le trailing slash de l'URL de base si présent
        $frontendUrl = rtrim($frontendUrl, '/');
        
        // S'assurer que le path commence par un slash
        if ($path && !str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        
        return $frontendUrl . $path;
    }

    /**
     * Détermine l'URL de redirection appropriée pour un utilisateur.
     * 
     * @param User $user
     * @return string
     */
    private function getRedirectUrlForUser(User $user): string
    {
        if ($user->is_profile_complete) {
            // Vérifier si plusieurs comptes existent
            $usersWithEmail = User::where('email', $user->email)->get();
            $completeUsers = $usersWithEmail->where('is_profile_complete', true)->values();
            
            if ($completeUsers->count() >= 2) {
                return $this->buildFrontendUrl('/selection-compte');
            }
            return $this->buildFrontendUrl('/dashboard');
        } else {
            return $this->buildFrontendUrl('/finaliser-inscription');
        }
    }

    /**
     * Redirige l'utilisateur vers Google OAuth.
     */
    public function redirect(Request $request)
    {
        // Stocker l'action (register ou login) dans la session
        // Google OAuth ne préserve pas les paramètres de requête dans le callback
        $action = $request->get('action', 'login');
        session(['google_oauth_action' => $action]);
        
        // ✅ CRITIQUE: Forcer la sauvegarde de la session avant la redirection
        // Cela garantit que le state OAuth est correctement stocké
        $request->session()->save();
        
        // ✅ CORRECTION PRODUCTION: Construire l'URI de redirection correcte
        // Utiliser l'URL de l'application actuelle (production ou développement)
        $redirectUri = config('services.google.redirect');
        
        // Si l'URI de redirection contient localhost en production, la corriger
        if (config('app.env') === 'production' && str_contains($redirectUri, 'localhost')) {
            $appUrl = config('app.url', env('APP_URL', 'http://localhost'));
            // S'assurer que l'URL ne contient pas localhost en production
            if (str_contains($appUrl, 'localhost')) {
                // Utiliser l'URL de la requête actuelle pour déterminer le domaine
                $currentUrl = $request->getSchemeAndHttpHost();
                $redirectUri = rtrim($currentUrl, '/') . '/auth/google/callback';
            } else {
                $redirectUri = rtrim($appUrl, '/') . '/auth/google/callback';
            }
        }
        
        Log::info("Google OAuth: Redirecting to Google", [
            'action' => $action,
            'session_id' => $request->session()->getId(),
            'session_driver' => config('session.driver'),
            'redirect_uri' => $redirectUri,
            'app_url' => config('app.url'),
            'app_env' => config('app.env'),
        ]);
        
        // ✅ MODIFICATION: Forcer Google à toujours afficher l'écran "Choisir un compte"
        // même si l'utilisateur a une session active, pour permettre le changement d'adresse email
        // ✅ CORRECTION PRODUCTION: Forcer l'URI de redirection si nécessaire
        $socialite = Socialite::driver('google')
            ->with(['prompt' => 'select_account']);
        
        // Si l'URI de redirection doit être forcée, l'appliquer
        if ($redirectUri !== config('services.google.redirect')) {
            $socialite->redirectUri($redirectUri);
        }
        
        return $socialite->redirect();
    }

    /**
     * Gère le callback Google OAuth.
     * 
     * Règles métier:
     * - Les utilisateurs Google sont considérés comme fiables
     * - On définit session(['2fa_verified' => true]) pour bypasser le 2FA
     * - Si nouveau user ou profil incomplet -> redirection vers /finaliser-inscription
     * - Si profil complet -> redirection vers /dashboard
     */
    public function callback(Request $request)
    {
        try {
            // Vérifier la configuration Google OAuth
            $clientId = config('services.google.client_id');
            $clientSecret = config('services.google.client_secret');
            $redirectUri = config('services.google.redirect');
            
            Log::info("Google OAuth callback: Configuration check", [
                'has_client_id' => !empty($clientId),
                'has_client_secret' => !empty($clientSecret),
                'redirect_uri' => $redirectUri,
                'request_url' => $request->fullUrl(),
                'request_query' => $request->query(),
            ]);
            
            if (empty($clientId) || empty($clientSecret)) {
                Log::error("Google OAuth: Configuration manquante", [
                    'has_client_id' => !empty($clientId),
                    'has_client_secret' => !empty($clientSecret),
                ]);
                return redirect($this->buildFrontendUrl())
                    ->with('error', 'Configuration Google OAuth manquante. Veuillez contacter l\'administrateur.');
            }
            
            // Vérifier les paramètres de la requête avant d'appeler Socialite
            $state = $request->get('state');
            $code = $request->get('code');
            $error = $request->get('error');
            
            Log::info("Google OAuth callback: Paramètres reçus", [
                'has_state' => !empty($state),
                'has_code' => !empty($code),
                'has_error' => !empty($error),
                'error' => $error,
                'session_id' => $request->session()->getId(),
                'session_state' => session('_token'),
            ]);
            
            if ($error) {
                Log::error("Google OAuth: Erreur retournée par Google", [
                    'error' => $error,
                    'error_description' => $request->get('error_description'),
                ]);
                return redirect($this->buildFrontendUrl())
                    ->with('error', 'Erreur lors de l\'autorisation Google: ' . $error);
            }
            
            if (empty($code)) {
                Log::error("Google OAuth: Code d'autorisation manquant");
                return redirect($this->buildFrontendUrl())
                    ->with('error', 'Code d\'autorisation manquant. Veuillez réessayer.');
            }
            
            // ✅ CRITIQUE: Utiliser un verrou pour éviter les appels concurrents avec le même code
            $codeCacheKey = 'google_oauth_code_' . md5($code);
            $lockKey = 'google_oauth_lock_' . md5($code);
            
            // Essayer d'acquérir un verrou (expire après 30 secondes)
            $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 30);
            $lockAcquired = $lock->get();
            
            if (!$lockAcquired) {
                // Un autre processus est en train de traiter ce code
                Log::warning("Google OAuth: Code en cours de traitement par un autre processus", [
                    'code_hash' => md5($code),
                ]);
                
                // Vérifier l'action pour déterminer le comportement
                $action = session('google_oauth_action', 'login');
                $isRegisterAction = ($action === 'register');
                
                // Si l'utilisateur est déjà connecté, rediriger immédiatement (sauf pour register)
                if (Auth::check() && !$isRegisterAction) {
                    $user = Auth::user();
                    $redirectUrl = $this->getRedirectUrlForUser($user);
                    Log::info("Google OAuth: Redirection immédiate (verrou actif)", [
                        'user_id' => $user->id,
                        'redirect_url' => $redirectUrl,
                    ]);
                    return redirect($redirectUrl);
                }
                
                // Sinon, rediriger vers la page d'accueil avec un message
                return redirect($this->buildFrontendUrl())
                    ->with('error', 'Une autre connexion est en cours. Veuillez patienter.');
            }
            
            try {
                // Vérifier si ce code a déjà été utilisé
                if (\Illuminate\Support\Facades\Cache::has($codeCacheKey)) {
                    // Récupérer l'action depuis la session
                    $action = session('google_oauth_action', 'login');
                    $isRegisterAction = ($action === 'register');
                    
                    Log::warning("Google OAuth: Code déjà utilisé, vérification si l'utilisateur est connecté", [
                        'code_hash' => md5($code),
                        'is_authenticated' => Auth::check(),
                        'user_id' => Auth::id(),
                        'action' => $action,
                        'is_register' => $isRegisterAction,
                    ]);
                    
                    // Si l'utilisateur est déjà connecté, rediriger vers la page appropriée (sauf pour register)
                    if (Auth::check() && !$isRegisterAction) {
                        $user = Auth::user();
                        $redirectUrl = $this->getRedirectUrlForUser($user);
                        Log::info("Google OAuth: Redirection (code déjà utilisé)", [
                            'user_id' => $user->id,
                            'redirect_url' => $redirectUrl,
                        ]);
                        return redirect($redirectUrl);
                    }
                    
                    // Si l'utilisateur n'est pas connecté mais le code a été utilisé, rediriger vers la page d'accueil
                    // Pour "register", permettre de continuer même si le code a été utilisé (déjà déconnecté plus haut)
                    if (!$isRegisterAction) {
                        return redirect($this->buildFrontendUrl())
                            ->with('error', 'Ce code d\'autorisation a déjà été utilisé. Veuillez réessayer la connexion.');
                    }
                }
                
                // ✅ CRITIQUE: Vérifier si l'utilisateur est déjà connecté AVANT d'échanger le code
                // Cela évite d'échanger le code plusieurs fois si le frontend fait des requêtes répétées
                // ✅ MODIFICATION: Pour l'action "register", permettre la création d'un nouveau compte même si l'utilisateur est déjà connecté
                // Cela permet à un utilisateur de créer un compte avec une deuxième adresse email
                $action = session('google_oauth_action', 'login');
                $isRegisterAction = ($action === 'register');
                
                // ✅ MODIFICATION: Même si l'utilisateur est déjà connecté, on doit échanger le code Google
                // pour récupérer l'email et vérifier les comptes existants, puis stocker les comptes en attente
                // dans la session avant de rediriger vers la sélection si nécessaire
                if (Auth::check() && !$isRegisterAction) {
                    // Pour "login", on échange quand même le code pour récupérer les comptes existants
                    // Récupérer les données de l'utilisateur Google
                    $googleUser = Socialite::driver('google')->user();
                    
                    // Marquer le code comme utilisé IMMÉDIATEMENT pour éviter les appels répétés
                    \Illuminate\Support\Facades\Cache::put($codeCacheKey, true, now()->addMinutes(5));
                    
                    $currentUser = Auth::user();
                    $googleEmail = $googleUser->getEmail();
                    
                    Log::info("Google OAuth: Utilisateur déjà connecté, récupération des comptes existants", [
                        'current_user_id' => $currentUser->id,
                        'current_email' => $currentUser->email,
                        'google_email' => $googleEmail,
                        'is_profile_complete' => $currentUser->is_profile_complete,
                    ]);
                    
                    // ✅ CRITIQUE: Vérifier tous les comptes existants pour cet email Google
                    $usersWithEmail = User::where('email', $googleEmail)
                        ->select('id', 'email', 'role', 'is_profile_complete', 'name', 'company_name')
                        ->get();
                    
                    $completeUsers = $usersWithEmail->where('is_profile_complete', true)->values();
                    
                    // Si plusieurs comptes complets existent, stocker dans la session et rediriger vers la sélection
                    if ($completeUsers->count() >= 2) {
                        $availableAccounts = $completeUsers->map(function ($u) {
                            return [
                                'id' => $u->id,
                                'type' => $u->role === 'business_admin' ? 'business' : 'individual',
                                'role' => $u->role,
                                'name' => $u->name,
                                'company_name' => $u->company_name,
                            ];
                        })->toArray();
                        
                        // Stocker les comptes disponibles dans la session
                        session([
                            'google_oauth_pending_accounts' => $availableAccounts,
                            'google_oauth_email' => $googleEmail,
                            'google_oauth_id' => $googleUser->getId(),
                        ]);
                        
                        Log::info("Google OAuth: Multiple complete accounts found (user already logged in), redirecting to selection", [
                            'email' => $googleEmail,
                            'accounts_count' => count($availableAccounts),
                            'account_ids' => $completeUsers->pluck('id')->toArray()
                        ]);
                        
                        return redirect($this->buildFrontendUrl('/selection-compte'));
                    }
                    
                    // Si un seul compte ou aucun compte complet, utiliser la logique normale
                    $redirectUrl = $this->getRedirectUrlForUser($currentUser);
                    Log::info("Google OAuth: Redirection (utilisateur déjà connecté, pas de sélection nécessaire)", [
                        'user_id' => $currentUser->id,
                        'redirect_url' => $redirectUrl,
                    ]);
                    return redirect($redirectUrl);
                } elseif (Auth::check() && $isRegisterAction) {
                    // Pour "register", déconnecter l'utilisateur actuel pour permettre la création d'un nouveau compte
                    $currentUser = Auth::user();
                    Log::info("Google OAuth: Register action - déconnexion de l'utilisateur actuel pour permettre la création d'un nouveau compte", [
                        'current_user_id' => $currentUser->id,
                        'current_email' => $currentUser->email,
                        'action' => $action,
                    ]);
                    
                    Auth::logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }
                
                // Récupérer les données de l'utilisateur Google (si pas déjà fait)
                if (!isset($googleUser)) {
                    $googleUser = Socialite::driver('google')->user();
                }
                
                // ✅ CRITIQUE: Marquer ce code comme utilisé immédiatement après l'échange réussi
                \Illuminate\Support\Facades\Cache::put($codeCacheKey, true, now()->addMinutes(5));
            } catch (\Laravel\Socialite\Two\InvalidStateException $stateError) {
                // Erreur de state invalide - la session a probablement expiré
                Log::error("Google OAuth: State invalide (session expirée)", [
                    'error' => $stateError->getMessage(),
                    'request_state' => $state,
                    'session_id' => $request->session()->getId(),
                ]);
                // Libérer le verrou avant de rediriger
                $lock->release();
                return redirect($this->buildFrontendUrl())
                    ->with('error', 'La session a expiré. Veuillez réessayer la connexion avec Google.');
            } catch (\GuzzleHttp\Exception\ClientException $guzzleError) {
                // Erreur HTTP lors de l'appel à Google
                $response = $guzzleError->getResponse();
                $responseBody = $response ? $response->getBody()->getContents() : 'N/A';
                Log::error("Google OAuth: Erreur HTTP lors de l'appel à Google", [
                    'status' => $response ? $response->getStatusCode() : 'N/A',
                    'response' => $responseBody,
                    'error' => $guzzleError->getMessage(),
                ]);
                // Libérer le verrou avant de rediriger
                $lock->release();
                return redirect($this->buildFrontendUrl())
                    ->with('error', 'Erreur lors de la communication avec Google. Veuillez réessayer.');
            } catch (\Exception $socialiteError) {
                Log::error("Google OAuth: Erreur Socialite", [
                    'error' => $socialiteError->getMessage(),
                    'code' => $socialiteError->getCode(),
                    'class' => get_class($socialiteError),
                    'file' => $socialiteError->getFile(),
                    'line' => $socialiteError->getLine(),
                    'trace' => $socialiteError->getTraceAsString(),
                ]);
                // Libérer le verrou avant de relancer l'exception
                $lock->release();
                throw $socialiteError;
            } finally {
                // ✅ CRITIQUE: Libérer le verrou dans tous les cas (même en cas de redirection)
                // Note: Les redirections avec return sortent de la fonction, donc le finally s'exécute
                // mais le verrou sera libéré automatiquement à l'expiration si on ne le libère pas explicitement
                if ($lockAcquired) {
                    $lock->release();
                }
            }

            // Récupérer l'action (register ou login) depuis la session (stockée dans redirect())
            $action = session('google_oauth_action', 'login'); // Par défaut: login
            $isRegisterAction = ($action === 'register');
            
            // Nettoyer la session après utilisation
            session()->forget('google_oauth_action');
            
            // ✅ OPTIMISATION: Réduire les logs en production (garder seulement les logs essentiels)
            if (config('app.debug')) {
                Log::info("Google OAuth callback started", [
                    'email' => $googleUser->getEmail(),
                    'google_id' => $googleUser->getId(),
                    'action' => $action,
                    'is_register' => $isRegisterAction
                ]);
            }

            // Flag pour déterminer si l'utilisateur est nouveau
            $isNewUser = false;

            // ✅ OPTIMISATION: Charger tous les utilisateurs pertinents en une seule requête optimisée
            // Utiliser select() pour ne charger que les colonnes nécessaires
            $googleEmail = $googleUser->getEmail();
            $googleId = $googleUser->getId();
            
            // ✅ OPTIMISATION: Une seule requête pour récupérer tous les utilisateurs pertinents
            $usersWithEmail = User::where('email', $googleEmail)
                ->select('id', 'email', 'google_id', 'role', 'is_profile_complete', 'name', 'company_name', 'account_type')
                ->get();
            
            // ✅ OPTIMISATION: Chercher dans la collection déjà chargée au lieu de faire une nouvelle requête
            $user = null;
            $existingUserWithGoogleId = $usersWithEmail->where('google_id', $googleId)->first();
            
            if ($isRegisterAction) {
                // Pour "register", on ignore les comptes existants avec ce google_id
                // On veut créer un nouveau compte même si un compte existe avec ce google_id
                // ✅ OPTIMISATION: Logs réduits en production
                if (config('app.debug')) {
                    Log::info("Google OAuth: Register action - ignoring existing account with google_id", [
                        'email' => $googleUser->getEmail(),
                        'google_id' => $googleUser->getId(),
                        'existing_user_id' => $existingUserWithGoogleId ? $existingUserWithGoogleId->id : null
                    ]);
                }
                $user = null; // Forcer la création d'un nouveau compte
            } else {
                // Pour "login", chercher un compte existant avec ce google_id
                $user = $existingUserWithGoogleId;
            }

            // ✅ MODIFICATION: Si pas trouvé par google_id (ou action=register), TOUJOURS créer un nouveau compte incomplet
            // On ne vérifie plus les comptes existants AVANT la création, on le fera APRÈS la connexion
            // ✅ OPTIMISATION: Logs réduits en production
            if (config('app.debug')) {
                if ($user) {
                    Log::info("Google OAuth: Existing account found with google_id", [
                        'action' => $action,
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'is_profile_complete' => $user->is_profile_complete,
                        'google_id' => $googleUser->getId()
                    ]);
                } else {
                    Log::info("Google OAuth: No account with this google_id found (or action=register), will create new incomplete account", [
                        'action' => $action,
                        'email' => $googleUser->getEmail(),
                        'google_id' => $googleUser->getId()
                    ]);
                }
            }

            // Si l'utilisateur n'existe toujours pas, créer un nouveau compte
            if (!$user) {
                // ✅ OPTIMISATION: Logs réduits en production
                if (config('app.debug')) {
                    Log::info("Google OAuth: Creating new account", [
                        'action' => $action,
                        'email' => $googleUser->getEmail(),
                        'google_id' => $googleUser->getId()
                    ]);
                }
                
                // ✅ MODIFICATION: Pour la sélection intelligente du type de compte, on vérifiera APRÈS la connexion
                // MAIS nous devons déterminer le rôle à utiliser lors de la création pour éviter les violations de contrainte unique
                // Le frontend utilisera l'API /api/existing-account-types pour verrouiller le type de compte
                
                // ✅ CRITIQUE: Vérifier les comptes existants pour déterminer le rôle à utiliser
                // Si un compte avec role='individual' existe déjà, créer un compte avec role='business_admin' (et vice versa)
                // Cela évite la violation de contrainte unique (email, role)
                $existingIndividualAccount = $usersWithEmail->where('role', 'individual')->first();
                $existingBusinessAccount = $usersWithEmail->where('role', 'business_admin')->first();
                $existingIncompleteAccount = $usersWithEmail->where('is_profile_complete', false)->first();
                
                // Si un compte incomplet existe, l'utiliser au lieu d'en créer un nouveau
                if ($existingIncompleteAccount) {
                    $user = $existingIncompleteAccount;
                    $isNewUser = false;
                    Log::info("Google OAuth: Found incomplete account, will use it instead of creating new one", [
                        'email' => $googleUser->getEmail(),
                        'incomplete_user_id' => $existingIncompleteAccount->id,
                        'account_type' => $existingIncompleteAccount->account_type ?? null
                    ]);
                    // Sortir de cette condition et continuer avec l'utilisateur trouvé
                } else {
                        // Déterminer le rôle à utiliser selon les comptes existants
                        $roleToCreate = 'individual'; // Par défaut
                        $accountTypeToCreate = null; // Sera déterminé lors de la finalisation
                        
                        if ($existingIndividualAccount && !$existingBusinessAccount) {
                            // L'utilisateur a déjà un compte Particulier, créer un compte Entreprise
                            $roleToCreate = 'business_admin';
                            Log::info("Google OAuth: User has individual account, will create business account", [
                                'email' => $googleUser->getEmail()
                            ]);
                        } elseif ($existingBusinessAccount && !$existingIndividualAccount) {
                            // L'utilisateur a déjà un compte Entreprise, créer un compte Particulier
                            $roleToCreate = 'individual';
                            Log::info("Google OAuth: User has business account, will create individual account", [
                                'email' => $googleUser->getEmail()
                            ]);
                        } elseif ($existingIndividualAccount && $existingBusinessAccount) {
                            // ✅ MODIFICATION: Si les deux types de comptes existent déjà, on ne peut pas créer un nouveau compte
                            // car cela violerait la contrainte unique (email, role)
                            // Rediriger vers la sélection de compte pour permettre à l'utilisateur de choisir
                            Log::info("Google OAuth: User already has both account types, redirecting to selection", [
                                'email' => $googleUser->getEmail(),
                                'individual_id' => $existingIndividualAccount->id,
                                'business_id' => $existingBusinessAccount->id,
                                'action' => $action,
                            ]);
                            
                            $availableAccounts = [
                                [
                                    'id' => $existingIndividualAccount->id,
                                    'type' => 'individual',
                                    'role' => 'individual',
                                    'name' => $existingIndividualAccount->name,
                                    'company_name' => null,
                                ],
                                [
                                    'id' => $existingBusinessAccount->id,
                                    'type' => 'business',
                                    'role' => 'business_admin',
                                    'name' => $existingBusinessAccount->name,
                                    'company_name' => $existingBusinessAccount->company_name,
                                ],
                            ];
                            
                            session([
                                'google_oauth_pending_accounts' => $availableAccounts,
                                'google_oauth_email' => $googleUser->getEmail(),
                                'google_oauth_id' => $googleUser->getId(),
                            ]);
                            
                            return redirect($this->buildFrontendUrl('/selection-compte'));
                        }
                        
                        // ✅ MODIFICATION: Créer un nouveau compte seulement si les deux types n'existent pas déjà
                        // (la vérification a déjà été faite plus haut et redirige vers la sélection si nécessaire)
                        if (!($existingIndividualAccount && $existingBusinessAccount)) {
                            // Aucun compte existant, ou action "register" : créer un compte Particulier par défaut
                            Log::info("Google OAuth: Will create account", [
                                'email' => $googleUser->getEmail(),
                                'is_register_action' => $isRegisterAction,
                                'has_both_accounts' => ($existingIndividualAccount && $existingBusinessAccount)
                            ]);
                            
                            // ✅ OPTIMISATION: Générer un username unique de manière plus efficace
                            $baseUsername = Str::slug($googleUser->getName(), '.');
                            $username = $baseUsername;
                            $counter = 1;
                            
                            // ✅ OPTIMISATION: Utiliser exists() avec select() pour réduire la charge
                            // Limiter à 100 tentatives pour éviter une boucle infinie
                            while ($counter < 100 && User::where('username', $username)->exists()) {
                                $username = $baseUsername . '.' . $counter;
                                $counter++;
                            }
                            
                            // Si on atteint 100 tentatives, ajouter un timestamp pour garantir l'unicité
                            if ($counter >= 100) {
                                $username = $baseUsername . '.' . time();
                            }

                            // ✅ CRITIQUE: Si c'est une action "register" et qu'un compte existe déjà avec ce google_id,
                            // ne pas assigner le google_id au nouveau compte pour éviter la violation de contrainte unique
                            // Le google_id peut rester null pour ce deuxième compte
                            $googleIdToAssign = null;
                            if (!$existingUserWithGoogleId) {
                                // Aucun compte n'a ce google_id, on peut l'assigner
                                $googleIdToAssign = $googleUser->getId();
                            } else {
                                // Un compte existe déjà avec ce google_id, ne pas l'assigner au nouveau compte
                                Log::info("Google OAuth: Existing account has this google_id, will create new account WITHOUT google_id", [
                                    'email' => $googleUser->getEmail(),
                                    'existing_user_id' => $existingUserWithGoogleId->id,
                                    'google_id' => $googleUser->getId()
                                ]);
                            }

                            // Créer le nouvel utilisateur (profil incomplet par défaut)
                            // Le type de compte sera déterminé lors de la finalisation avec sélection intelligente
                            $user = User::create([
                                'name' => $googleUser->getName(),
                                'email' => $googleUser->getEmail(),
                                'google_id' => $googleIdToAssign, // null si un compte existe déjà avec ce google_id
                                'username' => $username,
                                'password' => null, // Pas de mot de passe pour les utilisateurs Google
                                'email_verified_at' => now(), // Google a déjà vérifié l'email
                                'is_profile_complete' => false, // Profil incomplet (manque téléphone et type de compte)
                                'initial_password_set' => false,
                                'role' => $roleToCreate, // Déterminé selon les comptes existants
                                'account_type' => $accountTypeToCreate, // Sera déterminé lors de la finalisation avec sélection intelligente
                            ]);
                            
                            $isNewUser = true;
                            
                            Log::info("Google OAuth: New account created successfully", [
                                'user_id' => $user->id,
                                'email' => $user->email,
                                'role' => $user->role,
                                'account_type' => $user->account_type,
                                'has_google_id' => $user->google_id !== null
                            ]);
                        } // Fin du if ($isRegisterAction || !($existingIndividualAccount && $existingBusinessAccount))
                    } // Fin du else "Aucun compte incomplet trouvé"
                } // Fin du if (!$user)
                
                // Notification super admin : nouvel utilisateur créé via Google
                // Seulement si c'est un nouveau compte créé dans cette transaction
                // (pas si on utilise un compte incomplet existant)
                if ($isNewUser) {
                    try {
                        \App\Models\AdminNotification::create([
                            'type' => 'user_registered',
                            'user_id' => $user->id,
                            'message' => 'Nouvelle inscription via Google: ' . $user->name,
                            'url' => route('profile.public.show', ['user' => $user->username]),
                            'meta' => [
                                'role' => $user->role ?? null,
                                'email' => $user->email,
                                'provider' => 'google',
                            ],
                        ]);
                    } catch (\Throwable $t) {
                        // No-op: ne pas bloquer l'inscription si la notification échoue
                        Log::warning('Failed to create admin notification for Google user: ' . $t->getMessage());
                    }
                }

            // Vérifier si l'utilisateur est suspendu
            if ($user->is_suspended) {
                return redirect($this->buildFrontendUrl())
                    ->with('error', 'Votre compte a été suspendu. Veuillez contacter l\'administrateur.');
            }

            // Connecter l'utilisateur
            Auth::login($user);
            
            // ✅ CRITIQUE: Régénérer la session pour garantir que les cookies sont bien envoyés
            // Nécessaire après une redirection Google OAuth pour que le frontend puisse accéder à la session
            $request->session()->regenerate();
            $request->session()->regenerateToken();

            // ✅ CRITIQUE: Définir la session 2FA à true pour bypasser la vérification par email
            // Les utilisateurs Google sont considérés comme fiables
            session(['2fa_verified' => true]);
            
            // ✅ CRITIQUE: Forcer la sauvegarde de la session avant la redirection
            // Cela garantit que les cookies de session sont bien envoyés au frontend
            $request->session()->save();
            
            Log::info("Google OAuth: User logged in and session saved", [
                'user_id' => $user->id,
                'email' => $user->email,
                'session_id' => $request->session()->getId()
            ]);

            // ✅ OPTIMISATION: Réutiliser la collection déjà chargée au lieu de refaire une requête
            // Si la collection n'existe pas (cas rare), la charger
            if (!isset($usersWithEmail)) {
                $usersWithEmail = User::where('email', $googleUser->getEmail())
                    ->select('id', 'email', 'role', 'is_profile_complete', 'name', 'company_name')
                    ->get();
            }
            $completeUsers = $usersWithEmail->where('is_profile_complete', true)->values();
            
            // Si plusieurs comptes complets existent (2 comptes: Particulier + Entreprise)
            if ($completeUsers->count() >= 2) {
                // Plusieurs comptes complets : rediriger vers la sélection
                $availableAccounts = $completeUsers->map(function ($u) {
                    return [
                        'id' => $u->id,
                        'type' => $u->role === 'business_admin' ? 'business' : 'individual',
                        'role' => $u->role,
                        'name' => $u->name,
                        'company_name' => $u->company_name,
                    ];
                })->toArray();
                
                // Stocker les comptes disponibles dans la session
                session([
                    'google_oauth_pending_accounts' => $availableAccounts,
                    'google_oauth_email' => $googleUser->getEmail(),
                    'google_oauth_id' => $googleUser->getId(),
                ]);
                
                Log::info("Google OAuth: Multiple complete accounts found AFTER login, redirecting to selection", [
                    'email' => $googleUser->getEmail(),
                    'accounts_count' => count($availableAccounts),
                    'account_ids' => $completeUsers->pluck('id')->toArray()
                ]);
                
                return redirect($this->buildFrontendUrl('/selection-compte'));
            }

            // ✅ LOGIQUE: Vérifier si le profil est complet
            // PRIORITÉ 1: Vérifier le flag is_profile_complete (source de vérité)
            if ($user->is_profile_complete) {
                Log::info("Google OAuth: User profile is already complete, redirecting to dashboard", [
                    'user_id' => $user->id,
                    'email' => $user->email
                ]);
                return redirect($this->buildFrontendUrl('/dashboard'));
            }

            // PRIORITÉ 2: Si le flag est false mais que l'utilisateur a déjà phone ET account_type
            // (cas des "legacy users" créés avant l'intégration Google)
            // Mettre à jour automatiquement le flag et rediriger vers dashboard
            if ($user->phone !== null && $user->account_type !== null) {
                // Vérifier aussi que si c'est une entreprise, le nom est présent
                $hasCompanyName = ($user->account_type !== 'company') || ($user->company_name !== null);
                
                if ($hasCompanyName) {
                    // L'utilisateur a tous les champs requis, mettre à jour le flag
                    $user->is_profile_complete = true;
                    $user->save();
                    Log::info("Google OAuth: Legacy user profile completed automatically", [
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'account_type' => $user->account_type
                    ]);
                    
                    // Rediriger directement vers le dashboard
                    return redirect($this->buildFrontendUrl('/dashboard'));
                }
            }

            // Si on arrive ici, le profil n'est pas complet (nouveau compte ou données manquantes)
            // Utiliser une approche avec token temporaire pour garantir que la session est accessible
            // Créer un token temporaire qui sera utilisé par le frontend pour récupérer la session
            $tempToken = Str::random(64);
            
            // ✅ OPTIMISATION: Réutiliser la collection déjà chargée au lieu de refaire une requête
            // Filtrer la collection en mémoire plutôt que de faire une nouvelle requête SQL
            $usersWithEmailForSelection = $usersWithEmail->where('id', '!=', $user->id);
            $hasIndividual = $usersWithEmailForSelection->where('role', 'individual')->where('is_profile_complete', true)->isNotEmpty();
            $hasBusiness = $usersWithEmailForSelection->where('role', 'business_admin')->where('is_profile_complete', true)->isNotEmpty();
            
            // Déterminer le type de compte suggéré (verrouillé) selon les comptes existants
            $suggestedAccountType = null;
            if ($hasIndividual && !$hasBusiness) {
                // L'utilisateur a déjà un compte Particulier, verrouiller sur "Entreprise"
                $suggestedAccountType = 'company';
            } elseif ($hasBusiness && !$hasIndividual) {
                // L'utilisateur a déjà un compte Entreprise, verrouiller sur "Particulier"
                $suggestedAccountType = 'individual';
            }
            // Si aucun compte ou les deux existent, $suggestedAccountType reste null (libre choix)
            
            // Stocker le token dans le cache avec l'ID utilisateur et les informations de sélection intelligente
            \Illuminate\Support\Facades\Cache::put(
                'google_oauth_token_' . $tempToken,
                [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'is_new_user' => $isNewUser,
                    'account_type' => $user->account_type, // Peut être null, sera déterminé lors de la finalisation
                    'role' => $user->role,
                    'suggested_account_type' => $suggestedAccountType, // Type de compte suggéré (verrouillé) pour sélection intelligente
                    'has_individual' => $hasIndividual,
                    'has_business' => $hasBusiness,
                ],
                now()->addMinutes(5)
            );
            
            Log::info("Google OAuth: User profile incomplete, redirecting to finalization with token", [
                'user_id' => $user->id,
                'email' => $user->email,
                'is_profile_complete' => $user->is_profile_complete,
                'has_phone' => $user->phone !== null,
                'has_account_type' => $user->account_type !== null,
                'is_new_user' => $isNewUser,
                'temp_token' => substr($tempToken, 0, 10) . '...',
                'suggested_account_type' => $suggestedAccountType,
                'has_individual' => $hasIndividual,
                'has_business' => $hasBusiness,
            ]);
            
            // Rediriger vers une page intermédiaire qui récupère la session via le token
            $finalizeUrl = $this->buildFrontendUrl('/finaliser-inscription');
            $finalizeUrl .= '?google_oauth=1&token=' . $tempToken;
            if ($isNewUser) {
                $finalizeUrl .= '&new_user=1';
            }
            
            return redirect($finalizeUrl);
        } catch (\Exception $e) {
            Log::error('Google OAuth callback error', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_url' => $request->fullUrl(),
                'request_query' => $request->query(),
            ]);
            
            // Message d'erreur plus détaillé pour le debug
            $errorMessage = 'Une erreur est survenue lors de la connexion avec Google.';
            if (str_contains($e->getMessage(), 'Invalid state')) {
                $errorMessage = 'La session a expiré. Veuillez réessayer la connexion avec Google.';
            } elseif (str_contains($e->getMessage(), 'Invalid credentials')) {
                $errorMessage = 'Les identifiants Google OAuth sont invalides. Veuillez contacter l\'administrateur.';
            }
            
            return redirect($this->buildFrontendUrl())
                ->with('error', $errorMessage);
        }
    }

    /**
     * API: Valider un token temporaire Google OAuth et connecter l'utilisateur.
     * Cette méthode est appelée par le frontend après une redirection Google OAuth
     * pour garantir que la session est bien accessible.
     */
    public function validateToken(Request $request)
    {
        $request->validate([
            'token' => 'required|string|size:64',
        ]);

        $token = $request->input('token');
        $cacheKey = 'google_oauth_token_' . $token;
        
        // Récupérer les données du token depuis le cache
        $tokenData = \Illuminate\Support\Facades\Cache::get($cacheKey);
        
        if (!$tokenData) {
            Log::warning("Google OAuth validateToken: Token invalide ou expiré", [
                'token_prefix' => substr($token, 0, 10) . '...'
            ]);
            return response()->json([
                'message' => 'Token invalide ou expiré.',
            ], 400);
        }

        // Récupérer l'utilisateur depuis la base de données
        $user = User::find($tokenData['user_id']);
        
        if (!$user) {
            Log::error("Google OAuth validateToken: Utilisateur introuvable", [
                'user_id' => $tokenData['user_id']
            ]);
            \Illuminate\Support\Facades\Cache::forget($cacheKey);
            return response()->json([
                'message' => 'Utilisateur introuvable.',
            ], 404);
        }

        // Vérifier si l'utilisateur est suspendu
        if ($user->is_suspended) {
            \Illuminate\Support\Facades\Cache::forget($cacheKey);
            return response()->json([
                'message' => 'Votre compte a été suspendu. Veuillez contacter l\'administrateur.',
            ], 403);
        }

        // Connecter l'utilisateur
        Auth::login($user);
        
        // Régénérer la session pour garantir que les cookies sont bien envoyés
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        // Définir la session 2FA à true
        session(['2fa_verified' => true]);
        
        // Forcer la sauvegarde de la session
        $request->session()->save();
        
        // Supprimer le token du cache (usage unique)
        \Illuminate\Support\Facades\Cache::forget($cacheKey);

        // Vérifier que l'utilisateur est bien authentifié après la connexion
        $isAuthenticated = Auth::check();
        $authenticatedUserId = Auth::id();

        Log::info("Google OAuth validateToken: Utilisateur connecté via token", [
            'user_id' => $user->id,
            'email' => $user->email,
            'is_new_user' => $tokenData['is_new_user'] ?? false,
            'account_type' => $tokenData['account_type'] ?? null,
            'session_id' => $request->session()->getId(),
            'is_authenticated' => $isAuthenticated,
            'authenticated_user_id' => $authenticatedUserId,
            'session_name' => config('session.cookie'),
            'session_domain' => config('session.domain'),
            'session_secure' => config('session.secure'),
            'session_same_site' => config('session.same_site'),
        ]);

        return response()->json([
            'message' => 'Session validée avec succès.',
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'is_profile_complete' => $user->is_profile_complete,
            ],
            'account_type' => $tokenData['account_type'] ?? $user->account_type, // Type de compte à pré-remplir
            // ✅ MODIFICATION: Retourner les informations de sélection intelligente
            'suggested_account_type' => $tokenData['suggested_account_type'] ?? null, // Type de compte suggéré (verrouillé)
            'has_individual' => $tokenData['has_individual'] ?? false,
            'has_business' => $tokenData['has_business'] ?? false,
        ]);
    }

    /**
     * API: Sélectionner un compte après Google OAuth (quand plusieurs comptes existent).
     * 
     * Cette méthode est appelée depuis le frontend après que l'utilisateur ait choisi
     * parmi plusieurs comptes disponibles.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function selectAccount(Request $request)
    {
        try {
            Log::info("Google OAuth selectAccount: Début", [
                'request_data' => $request->all(),
                'session_id' => session()->getId()
            ]);

            $request->validate([
                'account_id' => 'required|integer',
            ]);

            // Récupérer les comptes disponibles depuis la session
            $pendingAccounts = session('google_oauth_pending_accounts', []);
            $googleEmail = session('google_oauth_email');
            $googleId = session('google_oauth_id');

            Log::info("Google OAuth selectAccount: Session data", [
                'pending_accounts_count' => count($pendingAccounts),
                'google_email' => $googleEmail,
                'google_id' => $googleId ? 'present' : 'missing'
            ]);

            if (empty($pendingAccounts) || !$googleEmail || !$googleId) {
                Log::warning("Google OAuth selectAccount: Session expirée ou incomplète");
                return response()->json([
                    'message' => 'Session expirée. Veuillez vous reconnecter avec Google.',
                ], 400);
            }

            // Trouver le compte sélectionné
            $selectedAccount = collect($pendingAccounts)->firstWhere('id', $request->account_id);

            if (!$selectedAccount) {
                Log::warning("Google OAuth selectAccount: Compte sélectionné introuvable", [
                    'requested_account_id' => $request->account_id,
                    'available_account_ids' => collect($pendingAccounts)->pluck('id')->toArray()
                ]);
                return response()->json([
                    'message' => 'Compte sélectionné introuvable.',
                ], 404);
            }

            Log::info("Google OAuth selectAccount: Compte trouvé", [
                'selected_account' => $selectedAccount
            ]);

            // Récupérer l'utilisateur depuis la base de données
            $user = User::where('id', $selectedAccount['id'])
                ->where('email', $googleEmail)
                ->first();

            if (!$user) {
                Log::error("Google OAuth selectAccount: Utilisateur introuvable en DB", [
                    'account_id' => $selectedAccount['id'],
                    'email' => $googleEmail
                ]);
                return response()->json([
                    'message' => 'Compte introuvable.',
                ], 404);
            }

            // Vérifier si l'utilisateur est suspendu
            if ($user->is_suspended) {
                Log::warning("Google OAuth selectAccount: Compte suspendu", [
                    'user_id' => $user->id
                ]);
                return response()->json([
                    'message' => 'Votre compte a été suspendu. Veuillez contacter l\'administrateur.',
                ], 403);
            }

            // Mettre à jour le google_id si nécessaire
            // CRITIQUE: Vérifier d'abord si ce google_id est déjà utilisé par un autre utilisateur
            if (!$user->google_id) {
                $existingUserWithGoogleId = User::where('google_id', $googleId)
                    ->where('id', '!=', $user->id)
                    ->first();
                
                if ($existingUserWithGoogleId) {
                    // Le google_id est déjà utilisé par un autre compte
                    // On ne met pas à jour le google_id pour éviter la violation de contrainte
                    // L'utilisateur pourra toujours se connecter avec ce compte via email
                    Log::warning("Google OAuth: google_id already used by another user, skipping update", [
                        'current_user_id' => $user->id,
                        'existing_user_id' => $existingUserWithGoogleId->id,
                        'google_id' => $googleId
                    ]);
                } else {
                    // Le google_id n'est pas utilisé, on peut le mettre à jour
                    $user->google_id = $googleId;
                    $user->save();
                    Log::info("Google OAuth: Updated selected account with google_id", [
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'role' => $user->role
                    ]);
                }
            } else if ($user->google_id !== $googleId) {
                // L'utilisateur a déjà un google_id différent
                // Vérifier si le nouveau google_id est disponible
                $existingUserWithGoogleId = User::where('google_id', $googleId)
                    ->where('id', '!=', $user->id)
                    ->first();
                
                if (!$existingUserWithGoogleId) {
                    // Le nouveau google_id est disponible, on peut le mettre à jour
                    $user->google_id = $googleId;
                    $user->save();
                    Log::info("Google OAuth: Updated google_id for selected account", [
                        'user_id' => $user->id,
                        'old_google_id' => $user->getOriginal('google_id'),
                        'new_google_id' => $googleId
                    ]);
                } else {
                    Log::warning("Google OAuth: Cannot update google_id, already used by another user", [
                        'user_id' => $user->id,
                        'existing_user_id' => $existingUserWithGoogleId->id
                    ]);
                }
            }

            // Connecter l'utilisateur
            Auth::login($user);
            Log::info("Google OAuth selectAccount: Utilisateur connecté", [
                'user_id' => $user->id
            ]);

            // Définir la session 2FA à true pour bypasser la vérification par email
            session(['2fa_verified' => true]);

            // Nettoyer la session OAuth
            session()->forget(['google_oauth_pending_accounts', 'google_oauth_email', 'google_oauth_id']);

            // Vérifier si le profil est complet
            $isProfileComplete = $user->is_profile_complete
                && $user->phone
                && $user->account_type
                && ($user->account_type !== 'company' || $user->company_name);

            Log::info("Google OAuth selectAccount: Profil vérifié", [
                'is_profile_complete' => $isProfileComplete,
                'user_is_profile_complete' => $user->is_profile_complete,
                'user_phone' => $user->phone ? 'present' : 'missing',
                'user_account_type' => $user->account_type,
                'user_company_name' => $user->company_name ? 'present' : 'missing'
            ]);

            // Recharger l'utilisateur depuis la base de données pour avoir les données à jour
            $user = $user->fresh();
            
            if (!$user) {
                Log::error("Google OAuth selectAccount: Utilisateur supprimé après connexion", [
                    'original_user_id' => $selectedAccount['id']
                ]);
                return response()->json([
                    'message' => 'Erreur: Utilisateur introuvable après connexion.',
                ], 500);
            }

            // Préparer les données utilisateur pour la réponse (éviter les problèmes de sérialisation)
            $userData = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'username' => $user->username ?? null,
                'is_profile_complete' => $user->is_profile_complete ?? false,
                'phone' => $user->phone ?? null,
                'account_type' => $user->account_type ?? null,
                'company_name' => $user->company_name ?? null,
            ];

            return response()->json([
                'message' => 'Connexion réussie.',
                'user' => $userData,
                'redirect_to' => $isProfileComplete ? '/dashboard' : '/finaliser-inscription',
            ]);
        } catch (\Exception $e) {
            Log::error('Google OAuth select account error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'message' => 'Une erreur est survenue lors de la sélection du compte: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API: Récupérer les comptes disponibles depuis la session.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPendingAccounts(Request $request)
    {
        try {
            Log::info('Get pending accounts: Session check', [
                'session_id' => session()->getId(),
                'has_pending_accounts' => session()->has('google_oauth_pending_accounts'),
                'has_google_email' => session()->has('google_oauth_email'),
            ]);

            $pendingAccounts = session('google_oauth_pending_accounts', []);
            $googleEmail = session('google_oauth_email');

            if (empty($pendingAccounts) || !$googleEmail) {
                Log::info('Get pending accounts: No pending accounts found', [
                    'pending_accounts_count' => count($pendingAccounts),
                    'has_google_email' => !empty($googleEmail),
                ]);
                
                // Retourner un 200 avec un tableau vide plutôt qu'un 404
                return response()->json([
                    'email' => null,
                    'accounts' => [],
                    'message' => 'Aucun compte en attente de sélection.',
                ], 200);
            }

            Log::info('Get pending accounts: Success', [
                'email' => $googleEmail,
                'accounts_count' => count($pendingAccounts),
            ]);

            return response()->json([
                'email' => $googleEmail,
                'accounts' => $pendingAccounts,
            ]);
        } catch (\Exception $e) {
            Log::error('Get pending accounts error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => 'Une erreur est survenue.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}

