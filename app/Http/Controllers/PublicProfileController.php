<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Order;
use App\Models\CompanyPage;
use App\Models\UserPortfolio;
use Illuminate\Http\Request;
use JeroenDesloovere\VCard\VCard;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage; // <-- Importez Storage

class PublicProfileController extends Controller
{
    /**
     * Affiche le profil public d'un utilisateur trouvé par son 'username'.
     * Si un paramètre 'order' est passé, affiche les données de cette commande spécifique.
     */
    public function show(Request $request, User $user)
    {
        $orderId = $request->query('order');

        // IMPORTANT: Le token peut contenir des caractères spéciaux comme & qui sont interprétés comme des séparateurs de paramètres
        // Récupérer le token depuis la chaîne de requête complète pour éviter la troncature
        $accessToken = $request->query('token');
        $queryString = $request->getQueryString();

        // Si un token est présent dans l'URL, essayer de le récupérer depuis la chaîne de requête complète
        // car $request->query('token') peut être tronqué si le token contient des &
        if ($queryString && strpos($queryString, 'token=') !== false) {
            // Si le token est le premier paramètre (l'URL commence par "token=" ou "?token="),
            // alors tout ce qui suit jusqu'à la fin est le token (les & font partie du token)
            if (preg_match('/[?&]token=(.+)$/', $queryString, $matches)) {
                $fullToken = urldecode($matches[1]);

                // Si le token extrait est plus long que celui obtenu via query('token'),
                // c'est probablement le token complet
                if (strlen($fullToken) > strlen($accessToken ?? '')) {
                    $accessToken = $fullToken;

                    Log::info("PublicProfileController: Token complet récupéré depuis queryString", [
                        'token_from_query' => $request->query('token'),
                        'token_complet' => $accessToken,
                        'token_length' => strlen($accessToken),
                        'query_string' => $queryString,
                    ]);
                }
            } else {
                // Si le token n'est pas en fin de chaîne, essayer de le récupérer normalement
                $parsedToken = $request->query('token');
                if ($parsedToken && strlen($parsedToken) > strlen($accessToken ?? '')) {
                    $accessToken = $parsedToken;
                }
            }
        }

        $order = null;
        $orderEmployee = null;

        // Si un token d'accès est fourni, chercher la commande par token
        if ($accessToken) {
            // IMPORTANT: Le token peut contenir des caractères spéciaux encodés dans l'URL
            // Décoder le token pour s'assurer qu'il correspond à celui en base de données
            // Le token peut déjà être décodé si récupéré depuis getQueryString()
            $decodedToken = urldecode($accessToken);

            Log::info("PublicProfileController: Recherche de commande par token", [
                'access_token_raw' => $accessToken,
                'access_token_decoded' => $decodedToken,
                'token_length' => strlen($decodedToken),
                'user_id' => $user->id,
                'user_username' => $user->username,
                'user_role' => $user->role,
                'query_string' => $request->getQueryString(),
            ]);

            // Essayer d'abord avec le token décodé
            $order = Order::where('access_token', $decodedToken)
                ->where('status', 'validated')
                ->first();

            // Si pas trouvé, essayer avec le token brut (au cas où il n'est pas encodé)
            if (!$order) {
                $order = Order::where('access_token', $accessToken)
                    ->where('status', 'validated')
                    ->first();
            }

            // Si toujours pas trouvé, essayer sans le filtre de statut (au cas où la commande n'est pas encore validée)
            if (!$order) {
                $order = Order::where('access_token', $decodedToken)->first();
                if (!$order) {
                    $order = Order::where('access_token', $accessToken)->first();
                }
            }

            if ($order) {
                $orderId = $order->id;

                // IMPORTANT: Toujours utiliser la commande trouvée par token, même si elle n'est pas configurée
                // Ne pas la remplacer par une autre commande, car l'utilisateur a cliqué sur une commande spécifique
                // Forcer le rechargement complet pour avoir toutes les données à jour
                $order->refresh();

                Log::info("PublicProfileController: Commande trouvée par token (utilisée tel quel)", [
                    'order_id' => $orderId,
                    'order_number' => $order->order_number,
                    'order_status' => $order->status,
                    'order_access_token' => $order->access_token,
                    'is_configured' => $order->is_configured,
                    'user_role' => $user->role,
                    'profile_name' => $order->profile_name,
                    'profile_title' => $order->profile_title,
                    'has_avatar' => !empty($order->order_avatar_url),
                ]);
            } else {
                Log::warning("PublicProfileController: Aucune commande trouvée avec ce token", [
                    'access_token_raw' => $accessToken,
                    'access_token_decoded' => $decodedToken,
                    'user_id' => $user->id,
                    'user_username' => $user->username,
                ]);

                // Essayer de trouver la commande via order_employees pour cet utilisateur
                // au cas où le token serait incorrect mais qu'on peut trouver la commande autrement
                if ($user->role === 'employee' || $user->role === 'business_admin') {
                    $orderEmployeeFallback = \App\Models\OrderEmployee::where('employee_id', $user->id)
                        ->where('is_configured', true)
                        ->orderBy('id', 'desc')
                        ->first();

                    if ($orderEmployeeFallback) {
                        $order = Order::find($orderEmployeeFallback->order_id);
                        if ($order) {
                            $orderId = $order->id;
                            $orderEmployee = $orderEmployeeFallback; // IMPORTANT: Assigner orderEmployee ici
                            Log::info("PublicProfileController: Commande trouvée via orderEmployee (fallback)", [
                                'order_id' => $orderId,
                                'order_status' => $order->status,
                                'order_employee_id' => $orderEmployee->id,
                                'profile_name' => $orderEmployee->profile_name,
                                'profile_title' => $orderEmployee->profile_title,
                            ]);
                        }
                    }
                }
            }
        }

        // Si un ID de commande est fourni, charger cette commande
        // IMPORTANT: Ne pas réécraser orderEmployee s'il a déjà été trouvé dans le fallback
        if ($orderId && !$orderEmployee) {
            // Pour les employés ET les business_admin qui se sont inclus, vérifier via order_employees
            if ($user->role === 'employee' || $user->role === 'business_admin') {
                $orderEmployee = \App\Models\OrderEmployee::where('order_id', $orderId)
                    ->where('employee_id', $user->id)
                    ->where('is_configured', true)
                    ->first();

                if ($orderEmployee) {
                    // Si l'order n'est pas déjà chargé, le charger
                    if (!$order) {
                        $order = Order::find($orderId);
                    }

                    // IMPORTANT: Logger les données pour déboguer
                    Log::info("PublicProfileController: orderEmployee trouvé", [
                        'order_id' => $orderId,
                        'employee_id' => $user->id,
                        'user_role' => $user->role,
                        'user_username' => $user->username,
                        'order_employee_id' => $orderEmployee->id,
                        'profile_name' => $orderEmployee->profile_name,
                        'profile_title' => $orderEmployee->profile_title,
                        'employee_name' => $orderEmployee->employee_name,
                        'is_configured' => $orderEmployee->is_configured,
                    ]);

                    // IMPORTANT: S'assurer que les données de profil sont bien présentes
                    // Si profile_name ou profile_title sont vides, logger pour déboguer
                    if (empty($orderEmployee->profile_name) && empty($orderEmployee->profile_title)) {
                        Log::warning("PublicProfileController: orderEmployee trouvé mais profile_name et profile_title sont vides", [
                            'order_id' => $orderId,
                            'employee_id' => $user->id,
                            'user_role' => $user->role,
                            'order_employee_id' => $orderEmployee->id,
                            'all_order_employee_data' => $orderEmployee->toArray(),
                        ]);
                    }
                } else {
                    // Logger si orderEmployee n'est pas trouvé
                    Log::warning("PublicProfileController: orderEmployee non trouvé", [
                        'order_id' => $orderId,
                        'employee_id' => $user->id,
                        'user_role' => $user->role,
                        'user_username' => $user->username,
                        'access_token' => $accessToken,
                    ]);
                }
            }

            // Si pas d'orderEmployee trouvé et que c'est un business_admin ou individual
            // IMPORTANT: Si un orderId ou token est spécifié dans l'URL, on DOIT utiliser cette commande spécifique
            // même si elle n'est pas encore configurée (pour permettre la prévisualisation)
            if (!$orderEmployee && ($user->role === 'business_admin' || $user->role === 'individual')) {
                // Si l'order existe déjà (trouvé par token/orderId), l'utiliser tel quel
                if ($order) {
                    // Vérifier qu'elle appartient bien à l'utilisateur
                    if ($order->user_id !== $user->id) {
                        Log::warning("PublicProfileController: Commande trouvée n'appartient pas à l'utilisateur", [
                            'order_id' => $orderId,
                            'order_user_id' => $order->user_id,
                            'user_id' => $user->id,
                        ]);
                        $order = null;
                    } else {
                        // Forcer le rechargement complet depuis la base de données pour s'assurer que toutes les colonnes sont chargées
                        // IMPORTANT: Même si is_configured = false, on doit quand même charger cette commande spécifique
                        $order->refresh();

                        Log::info("PublicProfileController: Commande spécifique chargée (via token/orderId)", [
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'is_configured' => $order->is_configured,
                            'status' => $order->status,
                            'profile_name' => $order->profile_name,
                            'profile_title' => $order->profile_title,
                            'has_avatar' => !empty($order->order_avatar_url),
                            'orderId_provided' => !is_null($orderId),
                            'token_provided' => !is_null($accessToken),
                        ]);
                    }
                } elseif ($orderId) {
                    // Si l'order n'a pas été trouvé mais qu'un orderId est fourni, le charger explicitement
                    // IMPORTANT: Ne pas filtrer par is_configured ici, car on veut la commande spécifique
                    // IMPORTANT: Ne pas filtrer par user_id non plus si c'est un admin qui accède au profil
                    $order = Order::where('id', $orderId)
                        ->first(); // Ne pas filtrer par user_id pour permettre aux admins de voir tous les profils

                    if ($order) {
                        // Vérifier que l'utilisateur peut accéder à cette commande
                        // (soit c'est sa commande, soit c'est un admin)
                        $canAccess = ($order->user_id === $user->id) || ($user->role === 'super_admin');

                        if (!$canAccess) {
                            Log::warning("PublicProfileController: Accès refusé à la commande", [
                                'order_id' => $orderId,
                                'order_user_id' => $order->user_id,
                                'user_id' => $user->id,
                                'user_role' => $user->role,
                            ]);
                            $order = null;
                        } else {
                            // Forcer le rechargement complet depuis la base de données
                            $order->refresh();

                            Log::info("PublicProfileController: Commande chargée par orderId explicite", [
                                'order_id' => $order->id,
                                'order_number' => $order->order_number,
                                'is_configured' => $order->is_configured,
                                'status' => $order->status,
                                'profile_name' => $order->profile_name,
                                'profile_title' => $order->profile_title,
                                'has_avatar' => !empty($order->order_avatar_url),
                                'order_avatar_url' => $order->order_avatar_url,
                                'phone_numbers' => $order->phone_numbers,
                                'emails' => $order->emails,
                            ]);
                        }
                    } else {
                        Log::warning("PublicProfileController: Commande spécifique non trouvée", [
                            'order_id' => $orderId,
                            'user_id' => $user->id,
                        ]);
                    }
                }
            }
        }

        // IMPORTANT: Si un orderId ou token a été fourni dans l'URL, on NE DOIT PAS chercher une autre commande
        // même si la commande trouvée n'est pas configurée. On utilise celle qui a été spécifiée.
        $orderIdOrTokenProvided = !is_null($orderId) || !is_null($accessToken);

        // Pour les comptes individuels, si aucun order n'a été trouvé avec orderId ou token,
        // ET qu'aucun orderId/token n'a été fourni dans l'URL, chercher la dernière commande configurée
        if (!$order && $user->role === 'individual' && !$orderIdOrTokenProvided) {
            // D'abord, lister toutes les commandes configurées pour diagnostiquer
            $allConfiguredOrders = Order::where('user_id', $user->id)
                ->where('is_configured', true)
                ->orderBy('updated_at', 'desc')
                ->get(['id', 'order_number', 'profile_name', 'profile_title', 'status', 'updated_at', 'is_configured']);

            Log::info("PublicProfileController: Toutes les commandes configurées pour individual", [
                'user_id' => $user->id,
                'user_username' => $user->username,
                'orders_count' => $allConfiguredOrders->count(),
                'orders' => $allConfiguredOrders->map(function($o) {
                    return [
                        'id' => $o->id,
                        'order_number' => $o->order_number,
                        'profile_name' => $o->profile_name,
                        'profile_title' => $o->profile_title,
                        'status' => $o->status,
                        'updated_at' => $o->updated_at,
                    ];
                })->toArray(),
            ]);

            // Priorité 1: Chercher une commande validée avec un titre défini
            $order = Order::where('user_id', $user->id)
                ->where('is_configured', true)
                ->where('status', 'validated')
                ->whereNotNull('profile_title')
                ->where('profile_title', '!=', '')
                ->orderBy('updated_at', 'desc')
                ->first();

            // Priorité 2: Chercher une commande validée (même sans titre)
            if (!$order) {
                $order = Order::where('user_id', $user->id)
                    ->where('is_configured', true)
                    ->where('status', 'validated')
                    ->orderBy('updated_at', 'desc')
                    ->first();
            }

            // Priorité 3: Chercher une commande configurée avec un titre défini (même non validée)
            if (!$order) {
                $order = Order::where('user_id', $user->id)
                    ->where('is_configured', true)
                    ->whereNotNull('profile_title')
                    ->where('profile_title', '!=', '')
                    ->orderBy('updated_at', 'desc')
                    ->first();
            }

            // Priorité 4: Chercher n'importe quelle commande configurée
            if (!$order) {
                $order = Order::where('user_id', $user->id)
                    ->where('is_configured', true)
                    ->orderBy('updated_at', 'desc')
                    ->first();
            }

            if ($order) {
                // Forcer le rechargement complet depuis la base de données AVANT de logger
                $order->refresh();

                Log::info("PublicProfileController: Commande configurée trouvée pour individual (sans orderId/token)", [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'profile_name' => $order->profile_name,
                    'profile_title' => $order->profile_title,
                    'is_configured' => $order->is_configured,
                    'status' => $order->status,
                    'has_avatar' => !empty($order->order_avatar_url),
                ]);
            } else {
                Log::warning("PublicProfileController: Aucune commande configurée trouvée pour individual", [
                    'user_id' => $user->id,
                    'user_username' => $user->username,
                ]);
            }
        }

        // Pour TOUTES les commandes (individual, business_admin, employee), rafraîchir depuis la base de données
        // pour s'assurer que toutes les colonnes sont chargées (notamment les JSON comme phone_numbers, emails)
        // IMPORTANT: Cela garantit que les données récentes sont toujours utilisées
        if ($order && !$orderEmployee) {
            // Forcer le rechargement complet depuis la base de données
            $order->refresh();

            Log::info("PublicProfileController: Données de commande (après refresh final)", [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'user_role' => $user->role,
                'is_configured' => $order->is_configured,
                'status' => $order->status,
                'profile_name' => $order->profile_name,
                'profile_title' => $order->profile_title,
                'profile_border_color' => $order->profile_border_color,
                'save_contact_button_color' => $order->save_contact_button_color,
                'services_button_color' => $order->services_button_color,
                'order_avatar_url' => $order->order_avatar_url,
                'phone_numbers' => $order->phone_numbers,
                'phone_numbers_type' => gettype($order->phone_numbers),
                'phone_numbers_is_array' => is_array($order->phone_numbers),
                'phone_numbers_raw' => $order->getRawOriginal('phone_numbers') ?? null,
                'emails' => $order->emails,
                'emails_type' => gettype($order->emails),
                'emails_is_array' => is_array($order->emails),
                'emails_raw' => $order->getRawOriginal('emails') ?? null,
                'website_url' => $order->website_url,
                'address_neighborhood' => $order->address_neighborhood,
                'address_commune' => $order->address_commune,
                'address_city' => $order->address_city,
                'address_country' => $order->address_country,
                'whatsapp_url' => $order->whatsapp_url,
                'linkedin_url' => $order->linkedin_url,
                'facebook_url' => $order->facebook_url,
                'twitter_url' => $order->twitter_url,
                'youtube_url' => $order->youtube_url,
                'deezer_url' => $order->deezer_url,
                'spotify_url' => $order->spotify_url,
                'orderId_provided' => $orderIdOrTokenProvided,
            ]);
        }

        // Vérifier si le business_admin a une page entreprise publiée
        // IMPORTANT: Utiliser la page entreprise de la commande spécifique si disponible
        $companyPagePublished = false;
        $companyPageUsername = null; // Pour stocker le username du business admin
        $companyWebsiteUrl = null; // URL du site web de l'entreprise
        $websiteFeaturedInServicesButton = false; // Flag pour savoir si le site web doit être mis en avant dans le bouton

        // Vérifier si l'utilisateur individual a un portfolio configuré
        $portfolioConfigured = false;

        if ($user->role === 'business_admin') {
            // Pour un business_admin, vérifier sa propre page
            // Si une commande est fournie, utiliser la page entreprise de cette commande spécifique
            if ($order && $order->id) {
                $companyPage = CompanyPage::where('order_id', $order->id)
                    ->where('user_id', $user->id)
                    ->where('is_published', true)
                    ->first();

                // Si pas de page pour cette commande, fallback sur une page sans order_id
                if (!$companyPage) {
                    $companyPage = CompanyPage::where('user_id', $user->id)
                        ->whereNull('order_id')
                        ->where('is_published', true)
                        ->first();
                }
            } else {
                // Pas de commande spécifique, utiliser la logique par défaut
                $companyPage = CompanyPage::where('user_id', $user->id)
                    ->where('is_published', true)
                    ->first();
            }

            $companyPagePublished = !is_null($companyPage);
            if ($companyPagePublished) {
                $companyPageUsername = $user->username;
                $companyWebsiteUrl = $companyPage->company_website_url;
                $websiteFeaturedInServicesButton = $companyPage->website_featured_in_services_button ?? false;
            }
        } elseif ($user->role === 'employee') {
            // Pour un employé, trouver son business admin via order_employees
            // Utiliser la commande spécifique si disponible (orderEmployee), sinon chercher
            $targetOrderId = null;

            if ($orderEmployee && $orderEmployee->order_id) {
                // Si un orderEmployee est fourni, utiliser sa commande
                $targetOrderId = $orderEmployee->order_id;
            } elseif ($order && $order->id) {
                // Sinon, utiliser la commande fournie directement
                $targetOrderId = $order->id;
            } else {
                // Fallback : chercher la première commande de l'employé
                $employeeOrder = \App\Models\OrderEmployee::where('employee_id', $user->id)
                    ->where('is_configured', true)
                    ->first();
                if ($employeeOrder) {
                    $targetOrderId = $employeeOrder->order_id;
                }
            }

            if ($targetOrderId) {
                $businessAdminOrder = Order::find($targetOrderId);
                if ($businessAdminOrder) {
                    $businessAdmin = User::find($businessAdminOrder->user_id);
                    if ($businessAdmin && $businessAdmin->role === 'business_admin') {
                        // Utiliser la page entreprise de la commande spécifique
                        $companyPage = CompanyPage::where('order_id', $targetOrderId)
                            ->where('user_id', $businessAdmin->id)
                            ->where('is_published', true)
                            ->first();

                        // Si pas de page pour cette commande, fallback sur une page sans order_id
                        if (!$companyPage) {
                            $companyPage = CompanyPage::where('user_id', $businessAdmin->id)
                                ->whereNull('order_id')
                                ->where('is_published', true)
                                ->first();
                        }

                        $companyPagePublished = !is_null($companyPage);
                        if ($companyPagePublished) {
                            $companyPageUsername = $businessAdmin->username;
                            $companyWebsiteUrl = $companyPage->company_website_url;
                            $websiteFeaturedInServicesButton = $companyPage->website_featured_in_services_button ?? false;
                        }
                    }
                }
            }
        } elseif ($user->role === 'individual') {
            // Vérifier si l'utilisateur a un portfolio configuré
            $portfolio = UserPortfolio::where('user_id', $user->id)->first();
            $portfolioConfigured = !is_null($portfolio) && $portfolio->profile_type !== null;
        }

        return view('profile.public', [
            'user' => $user,
            'order' => $order,
            'orderEmployee' => $orderEmployee,
            'companyPagePublished' => $companyPagePublished,
            'companyPageUsername' => $companyPageUsername, // Nouveau : username du business admin
            'companyWebsiteUrl' => $companyWebsiteUrl, // URL du site web de l'entreprise
            'websiteFeaturedInServicesButton' => $websiteFeaturedInServicesButton, // Flag pour mettre en avant le site web
            'portfolioConfigured' => $portfolioConfigured, // Nouveau : portfolio configuré pour individual
        ]);
    }

    /**
     * Génère et renvoie le fichier vCard de l'utilisateur public.
     */
    public function downloadVcard(Request $request, User $user)
    {
        $vcard = new VCard();

        // Vérifier si un ID de commande ou un token est passé en paramètre pour utiliser les données spécifiques
        $orderId = $request->query('order');
        $accessToken = $request->query('token');
        $orderEmployee = null;
        $order = null;

        // Si un token d'accès est fourni, chercher la commande par token
        // IMPORTANT: Ne pas filtrer par status='validated' pour permettre l'affichage même si non validée
        if ($accessToken) {
            Log::info("PublicProfileController: Recherche de commande par token", [
                'access_token_raw' => $accessToken,
                'access_token_decoded' => $accessToken,
                'user_id' => $user->id,
                'user_username' => $user->username,
                'user_role' => $user->role,
            ]);

            $order = Order::where('access_token', $accessToken)
                ->first(); // Ne pas filtrer par status pour permettre l'affichage de toutes les commandes

            if ($order) {
                $orderId = $order->id;
                Log::info("PublicProfileController: Commande trouvée par token", [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'order_status' => $order->status,
                    'order_access_token' => $order->access_token,
                    'is_configured' => $order->is_configured,
                    'profile_name' => $order->profile_name,
                    'profile_title' => $order->profile_title,
                ]);
            } else {
                Log::warning("PublicProfileController: Aucune commande trouvée avec ce token", [
                    'access_token_raw' => $accessToken,
                    'access_token_decoded' => $accessToken,
                    'user_id' => $user->id,
                    'user_username' => $user->username,
                ]);
            }
        }

        // Pour les employés ET les business_admin qui se sont inclus, charger leurs données depuis order_employees
        if ($orderId && ($user->role === 'employee' || $user->role === 'business_admin')) {
            $orderEmployee = \App\Models\OrderEmployee::where('order_id', $orderId)
                ->where('employee_id', $user->id)
                ->where('is_configured', true)
                ->first();

            if ($orderEmployee) {
                // Si l'order n'est pas déjà chargé, le charger
                if (!$order) {
                    $order = Order::find($orderId);
                }
            }
        }

        // Si pas d'orderEmployee trouvé et qu'un orderId est fourni, charger la commande directe
        if (!$orderEmployee && $orderId) {
            if (!$order) {
                $order = Order::where('id', $orderId)
                    ->where('user_id', $user->id)
                    ->first();
            }
        }

        // Pour les comptes individuels, chercher la commande configurée si aucune commande n'a été trouvée
        if (!$order && !$orderEmployee && $user->role === 'individual') {
            // Chercher d'abord une commande validée
            $order = Order::where('user_id', $user->id)
                ->where('is_configured', true)
                ->where('status', 'validated')
                ->orderBy('updated_at', 'desc')
                ->first();

            // Si aucune commande validée, chercher une commande configurée (même non validée)
            if (!$order) {
                $order = Order::where('user_id', $user->id)
                    ->where('is_configured', true)
                    ->orderBy('updated_at', 'desc')
                    ->first();
            }

            if ($order) {
                $order->refresh(); // Forcer le rechargement complet
                Log::info("PublicProfileController downloadVcard: Commande configurée trouvée pour individual", [
                    'order_id' => $order->id,
                    'profile_name' => $order->profile_name,
                    'profile_title' => $order->profile_title,
                    'order_avatar_url' => $order->order_avatar_url,
                    'phone_numbers' => $order->phone_numbers,
                    'emails' => $order->emails,
                ]);
            }
        }

        // Utiliser les données de orderEmployee si disponible, sinon utiliser les données de order, sinon user
        $profileData = $orderEmployee ?? $order ?? $user;

        // Logger les données utilisées pour le vCard
        Log::info("PublicProfileController downloadVcard: Données utilisées pour le vCard", [
            'user_id' => $user->id,
            'user_role' => $user->role,
            'has_orderEmployee' => !is_null($orderEmployee),
            'has_order' => !is_null($order),
            'profileData_type' => $profileData instanceof \App\Models\OrderEmployee ? 'OrderEmployee' : ($profileData instanceof \App\Models\Order ? 'Order' : 'User'),
            'profile_name' => $profileData->profile_name ?? $profileData->name ?? null,
            'profile_title' => $profileData->profile_title ?? $profileData->title ?? null,
            'phone_numbers' => $profileData->phone_numbers ?? null,
            'emails' => $profileData->emails ?? null,
            'order_avatar_url' => ($order && $order->order_avatar_url) ? $order->order_avatar_url : null,
            'employee_avatar_url' => ($orderEmployee && $orderEmployee->employee_avatar_url) ? $orderEmployee->employee_avatar_url : null,
            'user_avatar_url' => $user->avatar_url ?? null,
        ]);

        // Ajout Nom (avec titre si présent)
        $lastName = '';
        $firstName = ($profileData->profile_name ?? $profileData->name) ?? 'Contact';
        $nameParts = explode(' ', ($profileData->profile_name ?? $profileData->name) ?? '', 2);
        if (count($nameParts) === 2) {
            $firstName = $nameParts[0];
            $lastName = $nameParts[1];
        }
        $vcard->addName($lastName, $firstName);

        // Titre/Poste
        $title = $profileData->profile_title ?? $profileData->title;
        if ($title) {
            $vcard->addJobtitle($title);
        }

        // Entreprise
        $vcard->addCompany($user->vcard_company ?? $user->company_name);

        // === NOUVEAUX CHAMPS : Téléphones multiples ===
        if ($profileData->phone_numbers && is_array($profileData->phone_numbers)) {
            foreach ($profileData->phone_numbers as $index => $phone) {
                if ($phone) {
                    // Le premier téléphone est marqué comme préféré
                    $type = $index === 0 ? 'PREF;CELL' : 'CELL';
                    $vcard->addPhoneNumber($phone, $type);
                }
            }
        } elseif ($user->vcard_phone) {
            // Fallback sur l'ancien champ vcard_phone
            $vcard->addPhoneNumber($user->vcard_phone, 'PREF;CELL');
        }

        // === NOUVEAUX CHAMPS : Emails multiples ===
        if ($profileData->emails && is_array($profileData->emails)) {
            foreach ($profileData->emails as $index => $email) {
                if ($email) {
                    // Le premier email est marqué comme préféré
                    $type = $index === 0 ? 'PREF;INTERNET' : 'INTERNET';
                    $vcard->addEmail($email, $type);
                }
            }
        } else {
            // Fallback sur l'email d'inscription
            $vcard->addEmail($user->vcard_email ?? $user->email);
        }

        // === NOUVEAUX CHAMPS : Date d'anniversaire ===
        if ($profileData->birth_day && $profileData->birth_month) {
            // Format vCard pour anniversaire : --MM-DD (sans année)
            $birthday = sprintf('--%02d-%02d', $profileData->birth_month, $profileData->birth_day);
            $vcard->addBirthday($birthday);
        }

        // === NOUVEAUX CHAMPS : Site Web ===
        if ($profileData->website_url) {
            $vcard->addURL($profileData->website_url);
        }

        // === NOUVEAUX CHAMPS : Adresse complète ===
        $street = implode(', ', array_filter([
            $profileData->address_neighborhood,
            $profileData->address_commune
        ]));
        $city = $profileData->address_city;
        $country = $profileData->address_country;

        if ($street || $city || $country) {
            // addAddress($name, $extended, $street, $city, $region, $zip, $country, $type)
            $vcard->addAddress(
                null,           // name
                null,           // extended
                $street,        // street
                $city,          // city
                null,           // region
                null,           // zip
                $country,       // country
                'HOME'          // type
            );
        } elseif ($user->vcard_address) {
            // Fallback sur l'ancien champ vcard_address
            $vcard->addAddress(null, null, $user->vcard_address, null, null, null, null);
        }

        // Ajoute la photo de profil en utilisant le CHEMIN LOCAL, pas l'URL
        // OPTIMISATION : Réduction de la taille de l'image pour respecter la limite de 30 Ko
        // Priorité : orderEmployee->employee_avatar_url > order->order_avatar_url > user->avatar_url
        $avatarUrl = null;
        if ($orderEmployee && $orderEmployee->employee_avatar_url) {
            $avatarUrl = $orderEmployee->employee_avatar_url;
        } elseif ($order && $order->order_avatar_url) {
            $avatarUrl = $order->order_avatar_url;
        } else {
            $avatarUrl = $user->avatar_url;
        }

        // ✅ CORRECTION : Variable pour stocker le chemin du fichier temporaire à supprimer après
        $tempPhotoPath = null;

        if ($avatarUrl) {
            // ✅ CORRECTION COMPLETE : Normaliser le chemin pour extraire le chemin relatif
            // Gérer tous les formats possibles :
            // - URLs complètes : https://domain.com/storage/... ou https://domain.com/api/storage/...
            // - Chemins relatifs : /storage/..., /api/storage/..., storage/...
            $relativePath = $avatarUrl;

            // 1. D'abord, enlever le domaine si c'est une URL complète
            if (preg_match('#^https?://[^/]+/(?:api/)?storage/(.+)$#', $avatarUrl, $matches)) {
                $relativePath = $matches[1];
            }
            // 2. Ensuite, gérer les chemins relatifs
            elseif (str_starts_with($avatarUrl, '/api/storage/')) {
                $relativePath = str_replace('/api/storage/', '', $avatarUrl);
            } elseif (str_starts_with($avatarUrl, '/storage/')) {
                $relativePath = str_replace('/storage/', '', $avatarUrl);
            } elseif (str_starts_with($avatarUrl, 'storage/')) {
                $relativePath = str_replace('storage/', '', $avatarUrl);
            }

            Log::info("vCard: Tentative d'ajout de photo", [
                'user_id' => $user->id,
                'avatarUrl_original' => $avatarUrl,
                'relativePath_extracted' => $relativePath,
            ]);

            // Vérifier si le fichier existe
            if (Storage::disk('public')->exists($relativePath)) {
                $fullPath = Storage::disk('public')->path($relativePath);
                Log::info("vCard: Fichier photo trouvé", [
                    'fullPath' => $fullPath,
                    'file_exists' => file_exists($fullPath),
                    'file_size' => file_exists($fullPath) ? filesize($fullPath) : 0,
                ]);

                try {
                    // Créer une version réduite de l'image pour la vCard
                    $imageInfo = @\getimagesize($fullPath);
                    if ($imageInfo !== false) {
                        $mimeType = $imageInfo['mime'];
                        Log::info("vCard: Image info", [
                            'mime' => $mimeType,
                            'width' => $imageInfo[0],
                            'height' => $imageInfo[1],
                        ]);

                        // Charger l'image selon son type
                        $sourceImage = null;
                        if ($mimeType === 'image/jpeg' || $mimeType === 'image/jpg') {
                            $sourceImage = @\imagecreatefromjpeg($fullPath);
                        } elseif ($mimeType === 'image/png') {
                            $sourceImage = @\imagecreatefrompng($fullPath);
                        } elseif ($mimeType === 'image/gif') {
                            $sourceImage = @\imagecreatefromgif($fullPath);
                        } elseif ($mimeType === 'image/webp') {
                            // ✅ NOUVEAU: Support WebP
                            $sourceImage = @\imagecreatefromwebp($fullPath);
                        }

                        if ($sourceImage !== null) {
                            // Redimensionner à 200x200 pour une meilleure qualité
                            $resizedImage = \imagescale($sourceImage, 200, 200);

                            // Sauvegarder temporairement avec une compression modérée
                            $tempPhotoPath = storage_path('app/temp_vcard_photo_' . $user->id . '_' . time() . '.jpg');
                            \imagejpeg($resizedImage, $tempPhotoPath, 75); // Qualité 75% pour meilleure qualité

                            Log::info("vCard: Photo temporaire créée", [
                                'tempPath' => $tempPhotoPath,
                                'file_exists' => file_exists($tempPhotoPath),
                                'file_size' => file_exists($tempPhotoPath) ? filesize($tempPhotoPath) : 0,
                            ]);

                            // ✅ CORRECTION: Ajouter la photo AVANT de générer le vCard output
                            // La bibliothèque JeroenDesloovere\VCard encode la photo en base64
                            $vcard->addPhoto($tempPhotoPath);

                            Log::info("vCard: Photo ajoutée à la vCard avec succès");

                            // Nettoyer les ressources GD (mais PAS le fichier temporaire encore)
                            \imagedestroy($sourceImage);
                            \imagedestroy($resizedImage);
                        } else {
                            Log::warning("vCard: Impossible de charger l'image source", [
                                'mimeType' => $mimeType,
                                'fullPath' => $fullPath,
                            ]);
                        }
                    } else {
                        Log::warning("vCard: Impossible d'obtenir les informations de l'image", [
                            'fullPath' => $fullPath,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning("Impossible d'ajouter la photo optimisée à la vCard pour l'utilisateur {$user->id}: {$e->getMessage()}", [
                        'avatarUrl' => $avatarUrl,
                        'relativePath' => $relativePath,
                        'fullPath' => $fullPath ?? null,
                        'file_exists' => isset($fullPath) && file_exists($fullPath),
                        'exception' => $e->getTraceAsString(),
                    ]);
                }
            } else {
                Log::warning("Photo de profil introuvable pour le vCard", [
                    'user_id' => $user->id,
                    'avatarUrl' => $avatarUrl,
                    'relativePath' => $relativePath,
                    'storage_path' => Storage::disk('public')->path($relativePath),
                ]);
            }
        } else {
            Log::info("vCard: Aucune URL d'avatar disponible", [
                'user_id' => $user->id,
                'orderEmployee_avatar' => $orderEmployee ? $orderEmployee->employee_avatar_url : null,
                'order_avatar' => $order ? $order->order_avatar_url : null,
                'user_avatar' => $user->avatar_url,
            ]);
        }

        $filename = Str::slug(($profileData->profile_name ?? $profileData->name) ?: 'contact') . '.vcf';

        // ✅ CORRECTION: Générer le output de la vCard AVANT de supprimer le fichier temporaire
        $vcardOutput = $vcard->getOutput();

        // ✅ Maintenant on peut supprimer le fichier temporaire en toute sécurité
        if ($tempPhotoPath && file_exists($tempPhotoPath)) {
            @unlink($tempPhotoPath);
            Log::info("vCard: Fichier temporaire supprimé", ['tempPath' => $tempPhotoPath]);
        }

        return response($vcardOutput, 200)
                ->header('Content-Type', 'text/vcard; charset=utf-8')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
}

