<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderEmployee;
use App\Models\User;
use App\Models\Setting;
use App\Services\ImageCompressionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class OrderController extends Controller
{
    /**
     * Récupère toutes les commandes de l'utilisateur connecté
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // ✅ OPTIMISATION : Pour les business_admin, charger uniquement les données essentielles
        // et utiliser des select spécifiques pour éviter de charger toutes les colonnes
        $isBusinessAdmin = $user->role === 'business_admin';
        $isEmployee = $user->role === 'employee';
        
        // Si l'utilisateur est un employé OU un business_admin qui s'est inclus dans une commande
        // On doit récupérer les commandes via order_employees
        $orderEmployees = OrderEmployee::where('employee_id', $user->id)
            ->select('id', 'order_id', 'employee_id', 'card_quantity', 'is_configured', 
                     'profile_name', 'profile_title', 'employee_avatar_url', 'profile_border_color',
                     'save_contact_button_color', 'services_button_color', 'phone_numbers', 'emails',
                     'birth_day', 'birth_month', 'website_url', 'address_neighborhood', 'address_commune',
                     'address_city', 'address_country', 'whatsapp_url', 'linkedin_url', 'facebook_url',
                     'twitter_url', 'youtube_url', 'deezer_url', 'spotify_url',
                     'card_design_type', 'card_design_number', 'card_design_custom_url', 'no_design_yet',
                     'created_at', 'updated_at')
            ->with(['order' => function ($query) use ($isBusinessAdmin) {
                // ✅ OPTIMISATION : Pour les business_admin, charger uniquement les colonnes essentielles
                if ($isBusinessAdmin) {
                    $query->select('id', 'user_id', 'order_number', 'order_type', 'card_quantity', 
                                  'total_employees', 'employee_slots', 'unit_price', 'total_price',
                                  'annual_subscription', 'subscription_start_date', 'status', 
                                  'is_configured', 'access_token', 'created_at', 'updated_at');
                }
                // ✅ OPTIMISATION : Charger uniquement les colonnes essentielles des orderEmployees
                // Pour les business_admin, on n'a pas besoin de tous les détails des employés dans la liste
                $query->with(['orderEmployees' => function ($q) use ($isBusinessAdmin) {
                    if ($isBusinessAdmin) {
                        // Pour les business_admin, charger uniquement les données minimales nécessaires
                        // Note: slot_number n'existe pas dans order_employees, il est dans employee_slots (JSON) de orders
                        $q->select('id', 'order_id', 'employee_id', 'employee_name', 'employee_email',
                                  'card_quantity', 'is_configured', 'created_at');
                    }
                    // ✅ OPTIMISATION : Charger uniquement les colonnes essentielles de l'employee
                    $q->with(['employee' => function ($empQuery) use ($isBusinessAdmin) {
                        if ($isBusinessAdmin) {
                            // Pour les business_admin, charger uniquement le username pour les liens
                            $empQuery->select('id', 'username');
                        } else {
                            $empQuery->select('id', 'name', 'email', 'username', 'avatar_url', 'role');
                        }
                    }]);
                }]);
            }])
            ->orderBy('created_at', 'desc')
            ->get()
            // Exclure les commandes annulées du flux employé
            ->filter(function ($oe) {
                return $oe->order && $oe->order->status !== 'cancelled';
            });

        if ($orderEmployees->isNotEmpty()) {
            // ✅ OPTIMISATION : Pour les business_admin, éviter les requêtes supplémentaires dans la boucle
            // Précharger toutes les données nécessaires en une seule requête
            $orderIds = $orderEmployees->pluck('order_id')->unique();
            $businessAdminOrderEmployees = null;
            
            if ($isEmployee) {
                // Pour les employés, précharger les données du business admin pour toutes les commandes
                // Récupérer les user_id des commandes depuis les orders déjà chargés
                $businessAdminIds = collect();
                foreach ($orderEmployees as $oe) {
                    if ($oe->order && $oe->order->user_id) {
                        $businessAdminIds->push($oe->order->user_id);
                    }
                }
                $businessAdminIds = $businessAdminIds->unique()->filter();
                
                if ($businessAdminIds->isNotEmpty()) {
                    $businessAdminOrderEmployees = OrderEmployee::whereIn('order_id', $orderIds)
                        ->whereIn('employee_id', $businessAdminIds)
                        ->select('id', 'order_id', 'employee_id', 'card_design_type', 'card_design_number', 
                                'card_design_custom_url', 'no_design_yet')
                        ->get()
                        ->keyBy('order_id');
                } else {
                    $businessAdminOrderEmployees = collect();
                }
            }
            
            // Mapper les commandes avec les informations spécifiques à l'employé/admin
            $ordersFromEmployees = $orderEmployees->map(function ($orderEmployee) use ($user, $isBusinessAdmin, $isEmployee, $businessAdminOrderEmployees) {
                $order = $orderEmployee->order;

                // Ajouter les informations spécifiques à l'employé/admin
                $order->employee_card_quantity = $orderEmployee->card_quantity;
                $order->employee_is_configured = $orderEmployee->is_configured;
                $order->employee_order_employee_id = $orderEmployee->id;

                // ✅ OPTIMISATION : Pour les business_admin, charger uniquement les données de design
                // Les autres données seront chargées à la demande via show()
                if ($isBusinessAdmin) {
                    $order->employee_profile = [
                        'card_design_type' => $orderEmployee->card_design_type,
                        'card_design_number' => $orderEmployee->card_design_number,
                        'card_design_custom_url' => $orderEmployee->card_design_custom_url,
                        'no_design_yet' => $orderEmployee->no_design_yet,
                    ];
                    
                    // Copier aussi les données de design au niveau racine de l'order
                    $order->card_design_type = $orderEmployee->card_design_type;
                    $order->card_design_number = $orderEmployee->card_design_number;
                    $order->card_design_custom_url = $orderEmployee->card_design_custom_url;
                    $order->no_design_yet = $orderEmployee->no_design_yet;
                } else {
                    // Pour les employés, charger toutes les données de profil
                    $order->profile_name = $orderEmployee->profile_name;
                    $order->profile_title = $orderEmployee->profile_title;
                    $order->profile_border_color = $orderEmployee->profile_border_color ?? '#facc15';
                    $order->order_avatar_url = $orderEmployee->employee_avatar_url;
                    $order->is_configured = $orderEmployee->is_configured;

                    // Ajouter employee_profile avec toutes les données de profil et de design
                    $order->employee_profile = [
                        'profile_name' => $orderEmployee->profile_name,
                        'profile_title' => $orderEmployee->profile_title,
                        'employee_avatar_url' => $orderEmployee->employee_avatar_url,
                        'profile_border_color' => $orderEmployee->profile_border_color,
                        'save_contact_button_color' => $orderEmployee->save_contact_button_color,
                        'services_button_color' => $orderEmployee->services_button_color,
                        'phone_numbers' => $orderEmployee->phone_numbers,
                        'emails' => $orderEmployee->emails,
                        'birth_day' => $orderEmployee->birth_day,
                        'birth_month' => $orderEmployee->birth_month,
                        'website_url' => $orderEmployee->website_url,
                        'address_neighborhood' => $orderEmployee->address_neighborhood,
                        'address_commune' => $orderEmployee->address_commune,
                        'address_city' => $orderEmployee->address_city,
                        'address_country' => $orderEmployee->address_country,
                        'whatsapp_url' => $orderEmployee->whatsapp_url,
                        'linkedin_url' => $orderEmployee->linkedin_url,
                        'facebook_url' => $orderEmployee->facebook_url,
                        'twitter_url' => $orderEmployee->twitter_url,
                        'youtube_url' => $orderEmployee->youtube_url,
                        'deezer_url' => $orderEmployee->deezer_url,
                        'spotify_url' => $orderEmployee->spotify_url,
                        'card_design_type' => $orderEmployee->card_design_type,
                        'card_design_number' => $orderEmployee->card_design_number,
                        'card_design_custom_url' => $orderEmployee->card_design_custom_url,
                        'no_design_yet' => $orderEmployee->no_design_yet,
                    ];
                    
                    // ✅ OPTIMISATION : Utiliser les données préchargées au lieu de faire une requête par commande
                    if ($isEmployee && $order->order_type === 'business' && $order->user_id && $businessAdminOrderEmployees) {
                        $businessAdminOrderEmployee = $businessAdminOrderEmployees->get($order->id);
                        
                        if ($businessAdminOrderEmployee) {
                            $hasAdminDesign = !$businessAdminOrderEmployee->no_design_yet && 
                                             ($businessAdminOrderEmployee->card_design_type === 'template' || 
                                              $businessAdminOrderEmployee->card_design_type === 'custom');
                            
                            $order->employee_profile['is_design_locked_by_admin'] = true;
                            
                            if ($hasAdminDesign) {
                                $order->employee_profile['admin_design'] = [
                                    'card_design_type' => $businessAdminOrderEmployee->card_design_type,
                                    'card_design_number' => $businessAdminOrderEmployee->card_design_number,
                                    'card_design_custom_url' => $businessAdminOrderEmployee->card_design_custom_url,
                                ];
                                
                                $order->employee_profile['card_design_type'] = $businessAdminOrderEmployee->card_design_type;
                                $order->employee_profile['card_design_number'] = $businessAdminOrderEmployee->card_design_number;
                                $order->employee_profile['card_design_custom_url'] = $businessAdminOrderEmployee->card_design_custom_url;
                                $order->employee_profile['no_design_yet'] = false;
                            }
                        }
                    }
                }

                return $order;
            });
        } else {
            $ordersFromEmployees = collect();
        }

        // Si l'utilisateur est business_admin ou individual, récupérer aussi leurs commandes directes (non-business ou business sans inclusion)
        if ($user->role === 'business_admin' || $user->role === 'individual') {
            // ✅ OPTIMISATION : Utiliser select pour charger uniquement les colonnes nécessaires
            $directOrdersQuery = $user->orders()
                ->where('status', '!=', 'cancelled')
                ->select('id', 'user_id', 'order_number', 'order_type', 'card_quantity', 
                        'total_employees', 'employee_slots', 'unit_price', 'total_price',
                        'annual_subscription', 'subscription_start_date', 'status', 
                        'is_configured', 'access_token', 'created_at', 'updated_at');
            
            // ✅ OPTIMISATION : Pour les business_admin, charger uniquement les données minimales des orderEmployees
            if ($isBusinessAdmin) {
                $directOrdersQuery->with(['orderEmployees' => function ($q) {
                    // Note: slot_number n'existe pas dans order_employees, il est dans employee_slots (JSON) de orders
                    $q->select('id', 'order_id', 'employee_id', 'employee_name', 'employee_email',
                              'card_quantity', 'is_configured', 'created_at')
                      ->with(['employee' => function ($empQuery) {
                          $empQuery->select('id', 'username');
                      }]);
                }]);
            } else {
                // Pour les individual, charger les données complètes
                $directOrdersQuery->with(['orderEmployees.employee' => function ($query) {
                    $query->select('id', 'name', 'email', 'username', 'avatar_url', 'role');
                }]);
            }
            
            $directOrders = $directOrdersQuery
                ->orderBy('created_at', 'desc')
                ->get()
                ->filter(function ($order) use ($ordersFromEmployees) {
                    // Exclure les commandes qui sont déjà dans ordersFromEmployees
                    return !$ordersFromEmployees->contains('id', $order->id);
                })
                ->map(function ($order) use ($user, $isBusinessAdmin) {
                    // ✅ OPTIMISATION : Pour les business_admin, enrichir les employee_slots uniquement si nécessaire
                    if ($isBusinessAdmin && $order->employee_slots && is_array($order->employee_slots)) {
                        $orderEmployees = $order->orderEmployees->keyBy('employee_id');

                        $enrichedSlots = array_map(function($slot) use ($orderEmployees) {
                            if (isset($slot['employee_id']) && isset($orderEmployees[$slot['employee_id']])) {
                                $orderEmployee = $orderEmployees[$slot['employee_id']];
                                $slot['is_configured'] = $orderEmployee->is_configured;

                                // Ajouter le username si manquant (pour les anciens slots)
                                if (!isset($slot['employee_username']) && $orderEmployee->employee) {
                                    $slot['employee_username'] = $orderEmployee->employee->username;
                                }
                            }
                            return $slot;
                        }, $order->employee_slots);

                        $order->employee_slots = $enrichedSlots;
                        
                        // ✅ OPTIMISATION : Pour les business_admin, charger employee_profile uniquement si l'admin est inclus
                        // et seulement avec les données essentielles
                        $orderEmployee = $order->orderEmployees->firstWhere('employee_id', $user->id);
                        if ($orderEmployee && $order->order_type === 'business') {
                            // ✅ OPTIMISATION : Charger employee_profile uniquement avec les données de design
                            // Les autres données seront chargées à la demande via show()
                            $order->employee_profile = [
                                'card_design_type' => $orderEmployee->card_design_type,
                                'card_design_number' => $orderEmployee->card_design_number,
                                'card_design_custom_url' => $orderEmployee->card_design_custom_url,
                                'no_design_yet' => $orderEmployee->no_design_yet,
                            ];
                            
                            if ($user->username) {
                                $order->employee_profile['username'] = $user->username;
                            }
                            if ($order->access_token) {
                                $order->employee_profile['access_token'] = $order->access_token;
                            }
                            
                            // Copier aussi les données de design au niveau racine de l'order
                            $order->card_design_type = $orderEmployee->card_design_type;
                            $order->card_design_number = $orderEmployee->card_design_number;
                            $order->card_design_custom_url = $orderEmployee->card_design_custom_url;
                            $order->no_design_yet = $orderEmployee->no_design_yet;
                        }
                    } else {
                        // Pour les individual, comportement original (mais optimisé)
                        if ($order->employee_slots && is_array($order->employee_slots)) {
                            $orderEmployees = $order->orderEmployees->keyBy('employee_id');

                            $enrichedSlots = array_map(function($slot) use ($orderEmployees) {
                                if (isset($slot['employee_id']) && isset($orderEmployees[$slot['employee_id']])) {
                                    $orderEmployee = $orderEmployees[$slot['employee_id']];
                                    $slot['is_configured'] = $orderEmployee->is_configured;

                                    if (!isset($slot['employee_username']) && $orderEmployee->employee) {
                                        $slot['employee_username'] = $orderEmployee->employee->username;
                                    }
                                }
                                return $slot;
                            }, $order->employee_slots);

                            $order->employee_slots = $enrichedSlots;
                        }
                    }
                    
                    // IMPORTANT: Pour toutes les commandes, s'assurer que le username est accessible
                    if ($user->username) {
                        $order->profile_username = $user->username;
                    }
                    
                    return $order;
                });

            // Fusionner les deux collections
            $orders = $ordersFromEmployees->merge($directOrders)->sortByDesc('created_at')->values();
        } else {
            // Pour les employés, retourner uniquement les commandes via order_employees
            $orders = $ordersFromEmployees;
        }

        // S'assurer que $orders est toujours un tableau, même si vide
        $ordersArray = $orders->toArray();
        
        return response()->json($ordersArray);
    }

    /**
     * Crée une nouvelle commande
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validatedData = $request->validate([
            'order_type' => 'required|in:personal,business',
            'card_quantity' => 'required|integer|min:1',
            // Nouveaux champs pour le système de slots
            'total_employees' => 'nullable|integer|min:1|max:100',
            'employee_slots' => 'nullable|array',
            'employee_slots.*.slot_number' => 'required_with:employee_slots|integer|min:1',
            'employee_slots.*.cards_quantity' => 'required_with:employee_slots|integer|min:1',
            'cards_per_employee' => 'nullable|integer|min:1',
            'include_admin_in_order' => 'nullable|boolean', // Indique si le business admin s'est inclus
        ]);

        // ✅ Récupérer les prix depuis les paramètres de l'application
        $basePrice = (int) Setting::get('card_price', 180000); // Prix de la première carte (défaut : 180 000 GNF)
        $additionalPrice = (int) Setting::get('additional_card_price', 45000); // Prix des cartes additionnelles (défaut : 45 000 GNF)
        $subscriptionFee = (int) Setting::get('subscription_price', 40000); // Abonnement annuel (défaut : 40 000 GNF)

        // ✅ Calculer le prix total selon la nouvelle logique
        $cardQuantity = $validatedData['card_quantity'];
        $totalPrice = 0;

        if ($cardQuantity == 1) {
            // Si une seule carte, appliquer le prix de base
            $totalPrice = $basePrice;
        } elseif ($cardQuantity > 1) {
            // Si plusieurs cartes : prix de base + (quantité restante * prix additionnel)
            $totalPrice = $basePrice + (($cardQuantity - 1) * $additionalPrice);
        }

        // Générer un numéro de commande unique
        $orderNumber = Order::generateOrderNumber();

        $orderData = [
            'user_id' => $user->id,
            'order_number' => $orderNumber,
            'order_type' => $validatedData['order_type'],
            'card_quantity' => $validatedData['card_quantity'],
            'unit_price' => $basePrice, // ✅ Prix de base (première carte)
            'total_price' => $totalPrice, // ✅ Prix total calculé selon la nouvelle logique
            'annual_subscription' => $subscriptionFee, // ✅ Abonnement annuel (séparé du total)
            'subscription_start_date' => null, // Sera définie lors de la validation
            'status' => 'pending', // Statut initial en attente de validation
        ];

        // Si c'est une commande business avec le nouveau système de slots
        if ($validatedData['order_type'] === 'business' && isset($validatedData['employee_slots'])) {
            $orderData['total_employees'] = $validatedData['total_employees'] ?? count($validatedData['employee_slots']);
            $orderData['employee_slots'] = $validatedData['employee_slots'];
            $orderData['cards_per_employee'] = $validatedData['cards_per_employee'] ?? 1; // Valeur par défaut : 1 carte par employé
        }

        $order = Order::create($orderData);

        // Si le business admin s'est inclus dans la commande, créer une entrée OrderEmployee pour lui
        if ($validatedData['order_type'] === 'business'
            && isset($validatedData['include_admin_in_order'])
            && $validatedData['include_admin_in_order'] === true
            && isset($validatedData['employee_slots'])
            && count($validatedData['employee_slots']) > 0) {

            // Le premier slot est pour le business admin
            $adminSlot = $validatedData['employee_slots'][0];

            OrderEmployee::create([
                'order_id' => $order->id,
                'employee_id' => $user->id, // L'ID du business admin
                'employee_name' => $user->name, // Nom du business admin
                'employee_email' => $user->email, // Email du business admin
                'slot_number' => $adminSlot['slot_number'],
                'card_quantity' => $adminSlot['cards_quantity'],
                'is_assigned' => true, // Déjà assigné à lui-même
                'is_configured' => false, // Pas encore configuré
            ]);

            // Mettre à jour le premier slot dans employee_slots pour ajouter l'employee_id
            $slots = $order->employee_slots;
            if ($slots && is_array($slots) && count($slots) > 0) {
                $slots[0]['employee_id'] = $user->id;
                $slots[0]['employee_name'] = $user->name;
                $slots[0]['employee_email'] = $user->email;
                $slots[0]['employee_username'] = $user->username; // Ajouter le username
                $slots[0]['is_assigned'] = true;
                $order->employee_slots = $slots;
                $order->save();
            }
        }

        // Charger les relations
        $order->load('orderEmployees');

        return response()->json([
            'message' => 'Votre commande a été créée avec succès ! N\'oubliez pas de la valider pour recevoir votre confirmation par email.',
            'order' => $order,
        ], 201);
    }

    /**
     * Récupère une commande spécifique
     */
    public function show(Request $request, Order $order)
    {
        $user = $request->user();
        $orderEmployee = null;

        // Ne pas exposer une commande annulée dans "Mes commandes"
        if ($order->status === 'cancelled') {
            return response()->json(['message' => 'Commande non trouvée.'], 404);
        }

        // Charger les order_employees avec la relation employee pour avoir toutes les données nécessaires
        $order->load(['orderEmployees.employee']);

        // Vérifier que la commande appartient à l'utilisateur ou qu'il est assigné via order_employees
        if ($user->role === 'employee') {
            // Vérifier que l'employé est assigné à cette commande
            $orderEmployee = $order->orderEmployees->firstWhere('employee_id', $user->id);

            if (!$orderEmployee) {
                return response()->json(['message' => 'Commande non trouvée.'], 404);
            }
        } else {
            // Pour les autres utilisateurs (business_admin, individual), vérifier que la commande leur appartient
            if ($order->user_id !== $user->id) {
                return response()->json(['message' => 'Commande non trouvée.'], 404);
            }

            // Si c'est un business_admin, vérifier s'il a une entrée order_employee (s'il s'est inclus)
            if ($user->role === 'business_admin') {
                $orderEmployee = $order->orderEmployees->firstWhere('employee_id', $user->id);
            }
        }

        // Enrichir les employee_slots avec les données à jour de order_employees
        if ($order->employee_slots && is_array($order->employee_slots)) {
            $orderEmployees = OrderEmployee::where('order_id', $order->id)
                ->with('employee') // Charger la relation employee pour accéder au username
                ->get()
                ->keyBy('employee_id');

            $enrichedSlots = array_map(function($slot) use ($orderEmployees) {
                if (isset($slot['employee_id']) && isset($orderEmployees[$slot['employee_id']])) {
                    $orderEmployee = $orderEmployees[$slot['employee_id']];
                    $slot['is_configured'] = $orderEmployee->is_configured;

                    // Ajouter le username si manquant (pour les anciens slots)
                    if (!isset($slot['employee_username']) && $orderEmployee->employee) {
                        $slot['employee_username'] = $orderEmployee->employee->username;
                    }
                }
                return $slot;
            }, $order->employee_slots);

            $order->employee_slots = $enrichedSlots;
        }

        // Si c'est un employé ou un business admin inclus, ajouter ses données de profil spécifiques
        if ($orderEmployee) {
            // Initialiser employee_profile comme un tableau
            $employeeProfile = [
                'profile_name' => $orderEmployee->profile_name,
                'profile_title' => $orderEmployee->profile_title,
                'employee_avatar_url' => $orderEmployee->employee_avatar_url,
                'profile_border_color' => $orderEmployee->profile_border_color,
                'save_contact_button_color' => $orderEmployee->save_contact_button_color,
                'services_button_color' => $orderEmployee->services_button_color,
                'phone_numbers' => $orderEmployee->phone_numbers,
                'emails' => $orderEmployee->emails,
                'birth_day' => $orderEmployee->birth_day,
                'birth_month' => $orderEmployee->birth_month,
                'website_url' => $orderEmployee->website_url,
                'address_neighborhood' => $orderEmployee->address_neighborhood,
                'address_commune' => $orderEmployee->address_commune,
                'address_city' => $orderEmployee->address_city,
                'address_country' => $orderEmployee->address_country,
                'whatsapp_url' => $orderEmployee->whatsapp_url,
                'linkedin_url' => $orderEmployee->linkedin_url,
                'facebook_url' => $orderEmployee->facebook_url,
                'twitter_url' => $orderEmployee->twitter_url,
                'youtube_url' => $orderEmployee->youtube_url,
                'deezer_url' => $orderEmployee->deezer_url,
                'spotify_url' => $orderEmployee->spotify_url,
                // Inclure les données de design
                'card_design_type' => $orderEmployee->card_design_type,
                'card_design_number' => $orderEmployee->card_design_number,
                'card_design_custom_url' => $orderEmployee->card_design_custom_url,
                'no_design_yet' => $orderEmployee->no_design_yet,
            ];
            
            // IMPORTANT: Inclure le username et access_token pour les employés
            // Le username vient de la relation employee
            if ($orderEmployee->employee && $orderEmployee->employee->username) {
                $employeeProfile['username'] = $orderEmployee->employee->username;
            }
            // L'access_token vient de la commande (même token pour tous les employés d'une commande)
            if ($order->access_token) {
                $employeeProfile['access_token'] = $order->access_token;
            }
            
            // Si c'est un business admin inclus, copier aussi les données de design au niveau racine de l'order
            // pour que getDesignData dans OrdersView.vue puisse les trouver
            if ($user->role === 'business_admin' && $orderEmployee) {
                $order->card_design_type = $orderEmployee->card_design_type;
                $order->card_design_number = $orderEmployee->card_design_number;
                $order->card_design_custom_url = $orderEmployee->card_design_custom_url;
                $order->no_design_yet = $orderEmployee->no_design_yet;
            }
            
            // Si c'est un employé dans une commande entreprise, vérifier si le business admin a un design défini
            // et l'ajouter dans employee_profile pour verrouiller la section de design
            if ($user->role === 'employee' && $order->order_type === 'business' && $order->user_id) {
                try {
                    \Log::info("OrderController::show - Vérification du design du business admin pour l'employé", [
                        'order_id' => $order->id,
                        'order_user_id' => $order->user_id,
                        'employee_user_id' => $user->id,
                        'order_type' => $order->order_type,
                    ]);
                    
                    // Trouver le business admin de cette commande (user_id de l'order)
                    $businessAdminOrderEmployee = OrderEmployee::where('order_id', $order->id)
                        ->where('employee_id', $order->user_id)
                        ->first();
                    
                    \Log::info("OrderController::show - Business admin OrderEmployee trouvé", [
                        'found' => !!$businessAdminOrderEmployee,
                        'business_admin_order_employee_id' => $businessAdminOrderEmployee?->id,
                        'card_design_type' => $businessAdminOrderEmployee?->card_design_type,
                        'card_design_number' => $businessAdminOrderEmployee?->card_design_number,
                        'no_design_yet' => $businessAdminOrderEmployee?->no_design_yet,
                    ]);
                    
                    if ($businessAdminOrderEmployee) {
                        // Vérifier si le business admin a un design défini (pas no_design_yet et a un card_design_type)
                        $hasAdminDesign = !$businessAdminOrderEmployee->no_design_yet && 
                                         ($businessAdminOrderEmployee->card_design_type === 'template' || 
                                          $businessAdminOrderEmployee->card_design_type === 'custom');
                        
                        \Log::info("OrderController::show - Vérification hasAdminDesign", [
                            'hasAdminDesign' => $hasAdminDesign,
                            'no_design_yet' => $businessAdminOrderEmployee->no_design_yet,
                            'card_design_type' => $businessAdminOrderEmployee->card_design_type,
                        ]);
                        
                        // TOUJOURS verrouiller la section pour l'employé, même si le business admin n'a pas encore de design
                        $employeeProfile['is_design_locked_by_admin'] = true;
                        
                        if ($hasAdminDesign) {
                            // Ajouter les informations du design du business admin dans employee_profile
                            $employeeProfile['admin_design'] = [
                                'card_design_type' => $businessAdminOrderEmployee->card_design_type,
                                'card_design_number' => $businessAdminOrderEmployee->card_design_number,
                                'card_design_custom_url' => $businessAdminOrderEmployee->card_design_custom_url,
                            ];
                            
                            // Appliquer automatiquement le design du business admin à l'employé
                            // (le backend l'a déjà fait dans updateProfile, mais on s'assure que c'est visible)
                            $employeeProfile['card_design_type'] = $businessAdminOrderEmployee->card_design_type;
                            $employeeProfile['card_design_number'] = $businessAdminOrderEmployee->card_design_number;
                            $employeeProfile['card_design_custom_url'] = $businessAdminOrderEmployee->card_design_custom_url;
                            $employeeProfile['no_design_yet'] = false;
                            
                            \Log::info("OrderController::show - Design du business admin appliqué à l'employé", [
                                'is_design_locked_by_admin' => $employeeProfile['is_design_locked_by_admin'],
                                'card_design_type' => $employeeProfile['card_design_type'],
                                'card_design_number' => $employeeProfile['card_design_number'],
                            ]);
                        } else {
                            \Log::info("OrderController::show - Section verrouillée même sans design du business admin", [
                                'is_design_locked_by_admin' => $employeeProfile['is_design_locked_by_admin'],
                            ]);
                        }
                    } else {
                        \Log::warning("OrderController::show - Business admin OrderEmployee non trouvé pour l'employé", [
                            'order_id' => $order->id,
                            'order_user_id' => $order->user_id,
                        ]);
                    }
                } catch (\Exception $e) {
                    // En cas d'erreur, logger l'erreur mais continuer sans bloquer
                    \Log::error("Erreur lors de la vérification du design du business admin pour l'employé", [
                        'order_id' => $order->id,
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            } else {
                \Log::info("OrderController::show - Conditions non remplies pour verrouiller le design", [
                    'user_role' => $user->role,
                    'order_type' => $order->order_type ?? null,
                    'order_user_id' => $order->user_id ?? null,
                ]);
            }
            
            // Assigner employee_profile à l'order après toutes les modifications
            $order->employee_profile = $employeeProfile;
            
            \Log::info("OrderController::show - employee_profile final assigné", [
                'is_design_locked_by_admin' => $order->employee_profile['is_design_locked_by_admin'] ?? null,
                'has_admin_design' => isset($order->employee_profile['admin_design']),
            ]);
        }
        
        // IMPORTANT: Pour toutes les commandes (particuliers, business_admin), s'assurer que le username et access_token sont accessibles
        // au niveau racine pour faciliter l'accès depuis le frontend
        if ($order->user && $order->user->username) {
            $order->profile_username = $order->user->username;
        }
        
        // IMPORTANT: S'assurer que order_employees est bien inclus dans la réponse JSON
        // Laravel peut sérialiser la relation avec le nom de la méthode (orderEmployees) plutôt que order_employees
        // On s'assure que les deux formats sont disponibles pour compatibilité
        if ($order->relationLoaded('orderEmployees')) {
            $order->order_employees = $order->orderEmployees->map(function ($oe) {
                return [
                    'id' => $oe->id,
                    'order_id' => $oe->order_id,
                    'employee_id' => $oe->employee_id,
                    'employee_name' => $oe->employee_name,
                    'employee_email' => $oe->employee_email,
                    'card_quantity' => $oe->card_quantity,
                    'is_configured' => $oe->is_configured,
                    'profile_name' => $oe->profile_name,
                    'profile_title' => $oe->profile_title,
                    'employee' => $oe->relationLoaded('employee') && $oe->employee ? [
                        'id' => $oe->employee->id,
                        'name' => $oe->employee->name,
                        'email' => $oe->employee->email,
                        'username' => $oe->employee->username,
                        'role' => $oe->employee->role,
                    ] : null,
                ];
            })->toArray();
        }

        return response()->json($order);
    }

    /**
     * Marque une commande comme configurée
     */
    public function markAsConfigured(Request $request, Order $order)
    {
        $user = $request->user();

        // Vérifier les permissions d'accès
        if (!$this->canAccessOrder($user, $order)) {
            return response()->json(['message' => 'Commande non trouvée.'], 404);
        }

        // Interdire toute action si la commande est annulée
        if ($order->status === 'cancelled') {
            return response()->json(['message' => "Cette commande a été annulée et ne peut plus être modifiée."], 400);
        }

        // Vérifier d'abord s'il existe une entrée order_employee pour cet utilisateur
        // (peut être un employee ou un business_admin qui s'est inclus)
        $orderEmployee = OrderEmployee::where('order_id', $order->id)
            ->where('employee_id', $user->id)
            ->first();

        if ($orderEmployee) {
            // Mettre à jour OrderEmployee et employee_slots
            $orderEmployee->update(['is_configured' => true]);

            // Mettre à jour aussi le champ JSON employee_slots dans la table orders
            if ($order->employee_slots && is_array($order->employee_slots)) {
                $slots = $order->employee_slots;
                foreach ($slots as $index => $slot) {
                    if (isset($slot['employee_id']) && $slot['employee_id'] == $user->id) {
                        $slots[$index]['is_configured'] = true;
                        break;
                    }
                }
                $order->employee_slots = $slots;
                $order->save();
            }

            // Notification super admin : carte paramétrée (employé ou business_admin inclus)
            try {
                $profileUrl = route('profile.public.show', ['user' => $user->username]) . '?order=' . $order->id;
                \App\Models\AdminNotification::create([
                    'type' => 'order_configured',
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'employee_id' => $user->role === 'employee' ? $user->id : null,
                    'message' => 'Carte paramétrée par ' . $user->name . ' (#' . $order->order_number . ')',
                    'url' => $profileUrl,
                    'meta' => [
                        'order_number' => $order->order_number,
                    ],
                ]);
            } catch (\Throwable $t) {}

            // Pour un business_admin qui s'est inclus, ne pas marquer toute la commande comme configurée
            // Seule son entrée OrderEmployee est configurée
            if ($user->role === 'business_admin') {
                return response()->json([
                    'message' => 'Votre carte a été paramétrée avec succès.',
                    'order' => $order,
                ]);
            }
        }

        // Pour les autres cas (particulier, ou commande sans order_employee), marquer toute la commande
        $order->update([
            'is_configured' => true,
            'status' => 'configured',
        ]);

        // Notification super admin : commande paramétrée (inclure URL publique)
        try {
            $profileUser = $user; // l'utilisateur courant qui paramètre
            $profileUrl = route('profile.public.show', ['user' => $profileUser->username]) . '?order=' . $order->id;
            \App\Models\AdminNotification::create([
                'type' => 'order_configured',
                'user_id' => $profileUser->id,
                'order_id' => $order->id,
                'message' => 'Commande paramétrée par ' . $profileUser->name . ' (#' . $order->order_number . ')',
                'url' => $profileUrl,
                'meta' => [
                    'order_number' => $order->order_number,
                ],
            ]);
        } catch (\Throwable $t) {}

        return response()->json([
            'message' => 'Commande paramétrée avec succès.',
            'order' => $order,
        ]);
    }

    /**
     * Upload l'avatar pour une commande spécifique
     */
    public function uploadOrderAvatar(Request $request, Order $order)
    {
        $user = $request->user();

        // Vérifier les permissions d'accès
        if (!$this->canAccessOrder($user, $order)) {
            return response()->json(['message' => 'Commande non trouvée.'], 404);
        }

        // Interdire toute action si la commande est annulée
        if ($order->status === 'cancelled') {
            return response()->json(['message' => "Cette commande a été annulée et ne peut plus être modifiée."], 400);
        }

        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        try {
            // Vérifier d'abord s'il existe une entrée order_employee pour cet utilisateur
            // (peut être un employee ou un business_admin qui s'est inclus)
            $orderEmployee = OrderEmployee::where('order_id', $order->id)
                ->where('employee_id', $user->id)
                ->first();

            if ($orderEmployee) {
                // Mettre à jour dans order_employees
                // Supprimer l'ancienne photo de l'employé si elle existe
                if ($orderEmployee->employee_avatar_url && \Storage::disk('public')->exists(str_replace('/storage/', '', $orderEmployee->employee_avatar_url))) {
                    \Storage::disk('public')->delete(str_replace('/storage/', '', $orderEmployee->employee_avatar_url));
                }

                // Compresser et stocker la nouvelle photo
                $compressionService = new ImageCompressionService();
                $result = $compressionService->compressImage($request->file('avatar'), 'employee_avatars');
                $url = '/storage/' . $result['path'];

                // Mettre à jour l'URL de l'avatar de l'employé
                $orderEmployee->update(['employee_avatar_url' => $url]);

                return response()->json([
                    'message' => 'Photo de commande mise à jour avec succès.',
                    'avatar_url' => $url,
                ]);
            }

            // Sinon, mettre à jour dans orders (pour les commandes sans order_employee)
            // Supprimer l'ancienne photo de commande si elle existe
            if ($order->order_avatar_url && \Storage::disk('public')->exists(str_replace('/storage/', '', $order->order_avatar_url))) {
                \Storage::disk('public')->delete(str_replace('/storage/', '', $order->order_avatar_url));
            }

            // Compresser et stocker la nouvelle photo
            $compressionService = new ImageCompressionService();
            $result = $compressionService->compressImage($request->file('avatar'), 'order_avatars');
            $url = '/storage/' . $result['path'];

            // Mettre à jour l'URL de l'avatar de la commande
            $order->update(['order_avatar_url' => $url]);

            return response()->json([
                'message' => 'Photo de commande mise à jour avec succès.',
                'avatar_url' => $url,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erreur lors du téléchargement de la photo.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Utiliser la photo de profil pour une commande
     */
    public function useProfileAvatar(Request $request, Order $order)
    {
        $user = $request->user();

        // Vérifier les permissions d'accès
        if (!$this->canAccessOrder($user, $order)) {
            return response()->json(['message' => 'Commande non trouvée.'], 404);
        }

        // Interdire toute action si la commande est annulée
        if ($order->status === 'cancelled') {
            return response()->json(['message' => "Cette commande a été annulée et ne peut plus être modifiée."], 400);
        }

        // Vérifier d'abord s'il existe une entrée order_employee pour cet utilisateur
        // (peut être un employee ou un business_admin qui s'est inclus)
        $orderEmployee = OrderEmployee::where('order_id', $order->id)
            ->where('employee_id', $user->id)
            ->first();

        if ($orderEmployee) {
            // Mettre à jour dans order_employees
            // Copier l'avatar du profil vers order_employees
            $orderEmployee->update(['employee_avatar_url' => $user->avatar_url]);

            return response()->json([
                'message' => 'Photo de profil utilisée pour cette commande.',
                'avatar_url' => $orderEmployee->employee_avatar_url,
            ]);
        }

        // Sinon, copier l'avatar du profil vers la commande (pour les commandes sans order_employee)
        $order->update(['order_avatar_url' => $user->avatar_url]);

        return response()->json([
            'message' => 'Photo de profil utilisée pour cette commande.',
            'avatar_url' => $order->order_avatar_url,
        ]);
    }

    /**
     * Mettre à jour les données de profil d'une commande
     */
    public function updateProfile(Request $request, Order $order)
    {
        $user = $request->user();

        // Vérifier les permissions d'accès
        if (!$this->canAccessOrder($user, $order)) {
            return response()->json(['message' => 'Commande non trouvée.'], 404);
        }

        // Interdire toute action si la commande est annulée
        if ($order->status === 'cancelled') {
            return response()->json(['message' => "Cette commande a été annulée et ne peut plus être modifiée."], 400);
        }

        $validatedData = $request->validate([
            'profile_name' => 'nullable|string|max:255',
            'profile_title' => 'nullable|string|max:255',
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
            'whatsapp_url' => 'nullable|url:http,https',
            'linkedin_url' => 'nullable|url:http,https',
            'facebook_url' => 'nullable|url:http,https',
            'twitter_url' => 'nullable|url:http,https',
            'youtube_url' => 'nullable|url:http,https',
            'deezer_url' => 'nullable|url:http,https',
            'spotify_url' => 'nullable|url:http,https',
            'card_design_type' => 'nullable|in:template,custom',
            'card_design_number' => 'nullable|integer|min:1|max:30',
            'card_design_custom_url' => 'nullable|string|max:500',
            'no_design_yet' => 'nullable|boolean',
        ]);

        // Vérifier d'abord s'il existe une entrée order_employee pour cet utilisateur
        // (peut être un employee ou un business_admin qui s'est inclus)
        $orderEmployee = OrderEmployee::where('order_id', $order->id)
            ->where('employee_id', $user->id)
            ->first();

        if ($orderEmployee) {
            // Mettre à jour dans order_employees
            // Mettre à jour les données de profil de l'employé/admin
            $orderEmployee->update($validatedData);

            // Si c'est un business admin qui sauvegarde son design dans une commande entreprise où il est inclus,
            // appliquer ce design aux employés de cette commande
            if ($user->role === 'business_admin' && 
                ($order->order_type === 'business' || $order->order_type === 'entreprise') &&
                isset($validatedData['card_design_type']) && 
                !($validatedData['no_design_yet'] ?? false)) {
                
                // Appliquer le design du business admin à tous les employés de cette commande
                $designData = [
                    'card_design_type' => $validatedData['card_design_type'],
                    'card_design_number' => $validatedData['card_design_number'] ?? null,
                    'card_design_custom_url' => $validatedData['card_design_custom_url'] ?? null,
                    'no_design_yet' => false,
                ];

                // Récupérer tous les employés de cette commande (exclure le business admin lui-même)
                $employees = OrderEmployee::where('order_id', $order->id)
                    ->where('employee_id', '!=', $user->id)
                    ->with('employee')
                    ->get();

                foreach ($employees as $employeeOrder) {
                    // Vérifier que l'employé n'est pas un business admin
                    if ($employeeOrder->employee && $employeeOrder->employee->role !== 'business_admin') {
                        $employeeOrder->update($designData);
                    }
                }
            }

            return response()->json([
                'message' => 'Données de profil mises à jour avec succès.',
                'order_employee' => $orderEmployee,
            ]);
        }

        // Sinon, mettre à jour dans orders (pour les commandes sans order_employee)
        $order->update($validatedData);

        return response()->json([
            'message' => 'Données de profil mises à jour avec succès.',
            'order' => $order,
        ]);
    }

    /**
     * Uploader un design personnalisé pour une commande
     */
    public function uploadCustomDesign(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'custom_design' => 'required|file|mimes:jpg,jpeg,png,pdf,svg|max:10240', // 10MB max
            'order_id' => 'nullable|exists:orders,id',
        ]);

        try {
            $file = $request->file('custom_design');
            $orderId = $request->input('order_id');

            // Si un order_id est fourni, vérifier les permissions
            if ($orderId) {
                $order = Order::findOrFail($orderId);
                if (!$this->canAccessOrder($user, $order)) {
                    return response()->json(['message' => 'Commande non trouvée.'], 404);
                }
                if ($order->status === 'cancelled') {
                    return response()->json(['message' => "Cette commande a été annulée et ne peut plus être modifiée."], 400);
                }
            }

            // Compresser et stocker le fichier (seulement si c'est une image)
            if (in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'])) {
                $compressionService = new ImageCompressionService();
                $result = $compressionService->compressImage($file, 'custom_designs');
                $url = '/storage/' . $result['path'];
            } else {
                // Pour les fichiers non-image (PDF, SVG, etc.), stocker tel quel
                $path = $file->store('custom_designs', 'public');
                $url = '/storage/' . $path;
            }

            // Si un order_id est fourni, mettre à jour la commande
            if ($orderId && isset($order)) {
                // Supprimer l'ancien design personnalisé si il existe
                if ($order->card_design_custom_url && \Storage::disk('public')->exists(str_replace('/storage/', '', $order->card_design_custom_url))) {
                    \Storage::disk('public')->delete(str_replace('/storage/', '', $order->card_design_custom_url));
                }
                $order->update([
                    'card_design_type' => 'custom',
                    'card_design_custom_url' => $url,
                ]);
            }

            return response()->json([
                'message' => 'Design personnalisé téléchargé avec succès.',
                'design_url' => $url,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erreur lors du téléchargement du design.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Valider une commande et envoyer l'email de confirmation avec les CGU
     */
    public function validate(Request $request, Order $order)
    {
        $user = $request->user();

        // Vérifier que la commande appartient à l'utilisateur
        if ($order->user_id !== $user->id) {
            return response()->json(['message' => 'Commande non trouvée.'], 404);
        }

        // Vérifier si la commande n'est pas déjà validée
        if ($order->status === 'validated') {
            return response()->json(['message' => 'Cette commande est déjà validée.'], 400);
        }

        // Interdire la validation si la commande est annulée
        if ($order->status === 'cancelled') {
            return response()->json(['message' => "Cette commande a été annulée et ne peut pas être validée."], 400);
        }

        // Mettre à jour le statut de la commande
        $order->update([
            'status' => 'validated',
            'subscription_start_date' => now()->format('Y-m-d'),
        ]);

        // Notification super admin : commande validée (optimisé pour éviter les lenteurs)
        try {
            // Construire l'URL de manière optimisée sans appeler route() si possible
            $profileUrl = url('/') . '/' . $user->username;
            if ($order->is_configured) {
                $profileUrl .= '?order=' . $order->id;
            }
            \App\Models\AdminNotification::create([
                'type' => 'order_validated',
                'user_id' => $user->id,
                'order_id' => $order->id,
                'message' => 'Commande validée par ' . $user->name . ' (#' . $order->order_number . ')',
                'url' => $profileUrl,
                'meta' => [
                    'order_number' => $order->order_number,
                    'total_price' => $order->total_price,
                ],
            ]);
        } catch (\Throwable $t) {
            \Log::error('Erreur lors de la création de la notification admin: ' . $t->getMessage());
        }

        // Envoyer l'email de confirmation avec les CGU (en queue pour éviter les lenteurs)
        try {
            // Utiliser queue() pour mettre l'email en queue
            // La classe OrderValidated implémente ShouldQueue, donc l'email sera traité en arrière-plan
            // Cela ne bloque pas la réponse HTTP et améliore grandement les performances
            \Mail::to($user->email)->queue(new \App\Mail\OrderValidated($order, $user));
        } catch (\Exception $e) {
            \Log::error('Erreur lors de l\'envoi de l\'email de validation: ' . $e->getMessage());
            // Continuer même si l'email échoue
        }

        // Retourner uniquement les données essentielles pour éviter de charger toutes les relations
        return response()->json([
            'message' => 'Félicitations, votre commande a été validée ! Vous recevrez un email résumant votre commande et les conditions générales d\'utilisation.',
            'order' => [
                'id' => $order->id,
                'status' => $order->status,
                'order_number' => $order->order_number,
                'subscription_start_date' => $order->subscription_start_date,
            ],
        ]);
    }

    /**
     * Ajouter des cartes supplémentaires à une commande validée
     */
    public function addCards(Request $request, Order $order)
    {
        $user = $request->user();

        // Vérifier que la commande appartient à l'utilisateur
        if ($order->user_id !== $user->id) {
            return response()->json(['message' => 'Commande non trouvée.'], 404);
        }

        // Vérifier que la commande est validée
        if ($order->status !== 'validated') {
            return response()->json(['message' => 'Vous ne pouvez ajouter des cartes supplémentaires qu\'à une commande validée.'], 400);
        }

        // Valider la quantité et la distribution (pour les commandes business)
        $validatedData = $request->validate([
            'quantity' => 'required|integer|min:1|max:100',
            'distribution' => 'nullable|array', // Pour les commandes business: {admin: X, employees: {employee_id: Y, ...}}
        ]);

        $quantity = $validatedData['quantity'];
        $distribution = $validatedData['distribution'] ?? null;

        // Récupérer le prix d'une carte supplémentaire depuis les settings (même méthode que getPublicPricing)
        $additionalCardPrice = \App\Models\Setting::get('additional_card_price', 45000);
        $additionalCardPrice = (int) $additionalCardPrice; // S'assurer que c'est un entier

        // Calculer le prix total des cartes supplémentaires
        $additionalCardsTotalPrice = $quantity * $additionalCardPrice;

        // Pour les commandes business, distribuer les cartes entre le business admin et ses employés
        if (($order->order_type === 'business' || $order->order_type === 'entreprise') && $distribution) {
            // Log initial de la distribution reçue
            \Log::info('Ajout de cartes - Distribution reçue du frontend', [
                'order_id' => $order->id,
                'quantity' => $quantity,
                'distribution_received' => $distribution,
                'distribution_admin' => $distribution['admin'] ?? 'non défini',
                'distribution_employees' => $distribution['employees'] ?? 'non défini',
            ]);

            // Vérifier que le business admin est inclus dans la commande (si des cartes lui sont attribuées)
            $adminQuantity = isset($distribution['admin']) ? (int) $distribution['admin'] : 0;
            $employeesDistribution = $distribution['employees'] ?? [];

            // Nettoyer et convertir les valeurs des employés en entiers
            // IMPORTANT : Inclure toutes les valeurs (même 0) pour savoir exactement combien attribuer à chaque employé
            $cleanedEmployeesDistribution = [];
            foreach ($employeesDistribution as $employeeId => $employeeQuantity) {
                // Convertir en entier (0 si vide/null/undefined)
                $cleanedQuantity = max(0, (int) ($employeeQuantity ?? 0));
                // Inclure même si c'est 0, car cela signifie explicitement que cet employé ne doit pas recevoir de cartes
                $cleanedEmployeesDistribution[$employeeId] = $cleanedQuantity;
                
                \Log::info('Ajout de cartes - Nettoyage valeur employé', [
                    'order_id' => $order->id,
                    'employee_id' => $employeeId,
                    'raw_value' => $employeeQuantity,
                    'cleaned_value' => $cleanedQuantity,
                ]);
            }

            // Calculer la somme totale distribuée avec les valeurs nettoyées
            $adminQuantityClean = max(0, $adminQuantity); // S'assurer que c'est >= 0
            $employeesTotal = array_sum($cleanedEmployeesDistribution);
            $totalDistributed = $adminQuantityClean + $employeesTotal;

            \Log::info('Ajout de cartes - Validation de la distribution', [
                'order_id' => $order->id,
                'quantity' => $quantity,
                'adminQuantity' => $adminQuantity,
                'adminQuantityClean' => $adminQuantityClean,
                'employeesDistribution_raw' => $employeesDistribution,
                'employeesDistribution_cleaned' => $cleanedEmployeesDistribution,
                'employeesTotal' => $employeesTotal,
                'totalDistributed' => $totalDistributed,
            ]);

            // Vérifier qu'au moins une carte est distribuée
            if ($totalDistributed === 0) {
                \Log::warning('Ajout de cartes - Distribution vide', [
                    'order_id' => $order->id,
                    'quantity' => $quantity,
                ]);
                return response()->json([
                    'message' => 'Vous devez répartir les cartes entre vous-même et vos employés. Aucune carte n\'a été attribuée.',
                ], 400);
            }

            // Note: La vérification que totalDistributed === quantity sera faite plus tard,
            // après avoir chargé les order_employees et vérifié que tous les employés sont dans la distribution

            // Charger les order_employees avec la relation employee pour identifier les rôles
            $order->load(['orderEmployees.employee']);

            // Identifier les employés réguliers (non business_admin)
            $adminEmployeeId = $user->id;
            $regularEmployeeIds = [];
            
            foreach ($order->orderEmployees as $oe) {
                // Exclure le business admin lui-même
                if ($oe->employee_id == $adminEmployeeId) {
                    continue;
                }
                
                // Si la relation employee est chargée, vérifier le rôle
                if ($oe->employee) {
                    // Inclure seulement les employés qui ne sont pas business_admin
                    if ($oe->employee->role !== 'business_admin') {
                        $regularEmployeeIds[] = $oe->employee_id;
                    }
                } else {
                    // Si la relation employee n'est pas chargée, inclure par défaut
                    // (mieux vaut être trop strict que trop permissif)
                    $regularEmployeeIds[] = $oe->employee_id;
                }
            }
            
            $employeeIdsInDistribution = array_keys($cleanedEmployeesDistribution);
            
            // Vérifier si tous les employés (non-admin) reçoivent des cartes
            $employeesWithoutCards = array_diff($regularEmployeeIds, $employeeIdsInDistribution);
            
            \Log::info('Ajout de cartes - Vérification des employés', [
                'order_id' => $order->id,
                'admin_employee_id' => $adminEmployeeId,
                'regular_employee_ids' => $regularEmployeeIds,
                'employee_ids_in_distribution' => $employeeIdsInDistribution,
                'employees_without_cards' => $employeesWithoutCards,
                'admin_quantity' => $adminQuantityClean,
                'total_quantity' => $quantity,
            ]);

            // Validation : Vérifier que tous les employés réguliers sont dans la distribution
            // (même s'ils ont 0 cartes, ils doivent être explicitement dans la distribution)
            if (count($regularEmployeeIds) > 0) {
                // Vérifier que tous les employés réguliers sont présents dans la distribution
                $missingEmployees = array_diff($regularEmployeeIds, array_keys($cleanedEmployeesDistribution));
                
                if (count($missingEmployees) > 0) {
                    \Log::warning('Ajout de cartes - Employés manquants dans la distribution', [
                        'order_id' => $order->id,
                        'regular_employee_ids' => $regularEmployeeIds,
                        'employee_ids_in_distribution' => array_keys($cleanedEmployeesDistribution),
                        'missing_employee_ids' => $missingEmployees,
                    ]);
                    return response()->json([
                        'message' => 'Tous les employés doivent être inclus dans la distribution, même avec 0 cartes. Veuillez remplir tous les champs de répartition.',
                    ], 400);
                }

                // Vérifier que la somme des quantités correspond au total
                $totalInDistribution = $adminQuantityClean + array_sum($cleanedEmployeesDistribution);
                if ($totalInDistribution !== $quantity) {
                    \Log::warning('Ajout de cartes - La somme de la distribution ne correspond pas', [
                        'order_id' => $order->id,
                        'quantity' => $quantity,
                        'admin_quantity' => $adminQuantityClean,
                        'employees_distribution' => $cleanedEmployeesDistribution,
                        'total_in_distribution' => $totalInDistribution,
                    ]);
                    return response()->json([
                        'message' => "La répartition des cartes (total: {$totalInDistribution}) ne correspond pas au nombre total de cartes à ajouter ({$quantity}).",
                    ], 400);
                }
            }

            // Log des quantités AVANT les modifications
            \Log::info('Ajout de cartes - État AVANT modifications', [
                'order_id' => $order->id,
                'order_employees_before' => $order->orderEmployees->map(function ($oe) {
                    return [
                        'employee_id' => $oe->employee_id,
                        'employee_name' => $oe->employee_name,
                        'card_quantity' => $oe->card_quantity,
                        'role' => $oe->employee ? $oe->employee->role : 'unknown',
                    ];
                })->toArray(),
            ]);

            // Ajouter des cartes pour le business admin s'il est inclus dans la commande
            if ($adminQuantityClean > 0) {
                $adminOrderEmployee = $order->orderEmployees->where('employee_id', $user->id)->first();
                if ($adminOrderEmployee) {
                    $oldAdminCardQuantity = $adminOrderEmployee->card_quantity;
                    \Log::info('Ajout de cartes pour le business admin - AVANT increment', [
                        'order_id' => $order->id,
                        'employee_id' => $user->id,
                        'quantity_to_add' => $adminQuantityClean,
                        'old_card_quantity' => $oldAdminCardQuantity,
                    ]);
                    $adminOrderEmployee->increment('card_quantity', $adminQuantityClean);
                    $adminOrderEmployee->refresh();
                    \Log::info('Ajout de cartes pour le business admin - APRÈS increment', [
                        'order_id' => $order->id,
                        'employee_id' => $user->id,
                        'new_card_quantity' => $adminOrderEmployee->card_quantity,
                        'expected' => $oldAdminCardQuantity + $adminQuantityClean,
                    ]);
                } else {
                    return response()->json([
                        'message' => 'Vous n\'êtes pas inclus dans cette commande.',
                    ], 400);
                }
            }

            // Ajouter des cartes pour les employés (utiliser la distribution nettoyée)
            // IMPORTANT: Ne traiter QUE les employés avec une quantité > 0
            $employeesWithCards = 0; // Compteur pour vérifier qu'on n'ajoute des cartes qu'aux employés avec quantité > 0
            foreach ($cleanedEmployeesDistribution as $employeeId => $employeeQuantity) {
                // Convertir explicitement en entier pour éviter les problèmes de type
                $employeeQuantityInt = (int) $employeeQuantity;
                
                // Si la quantité est 0 ou négative, ne rien faire (l'employé ne doit PAS recevoir de cartes)
                if ($employeeQuantityInt <= 0) {
                    \Log::info('Ajout de cartes - Employé avec quantité 0, aucune carte ajoutée (SÉCURISÉ)', [
                        'order_id' => $order->id,
                        'employee_id' => $employeeId,
                        'quantity_raw' => $employeeQuantity,
                        'quantity_int' => $employeeQuantityInt,
                        'action' => 'SKIP - aucune carte ajoutée',
                    ]);
                    continue; // Passer à l'employé suivant SANS ajouter de cartes
                }

                // La quantité est > 0, ajouter les cartes
                $employeesWithCards++;
                $employeeOrderEmployee = $order->orderEmployees->where('employee_id', $employeeId)->first();
                if ($employeeOrderEmployee) {
                    $oldEmployeeCardQuantity = $employeeOrderEmployee->card_quantity;
                    \Log::info('Ajout de cartes pour un employé - AVANT increment', [
                        'order_id' => $order->id,
                        'employee_id' => $employeeId,
                        'quantity_to_add' => $employeeQuantityInt,
                        'old_card_quantity' => $oldEmployeeCardQuantity,
                    ]);
                    
                    // Utiliser increment avec la quantité convertie en entier pour éviter les problèmes
                    $employeeOrderEmployee->increment('card_quantity', $employeeQuantityInt);
                    $employeeOrderEmployee->refresh();
                    
                    \Log::info('Ajout de cartes pour un employé - APRÈS increment', [
                        'order_id' => $order->id,
                        'employee_id' => $employeeId,
                        'new_card_quantity' => $employeeOrderEmployee->card_quantity,
                        'expected' => $oldEmployeeCardQuantity + $employeeQuantityInt,
                        'matches_expected' => $employeeOrderEmployee->card_quantity === ($oldEmployeeCardQuantity + $employeeQuantityInt),
                    ]);
                } else {
                    \Log::warning('Ajout de cartes - Employé non trouvé', [
                        'order_id' => $order->id,
                        'employee_id' => $employeeId,
                    ]);
                    return response()->json([
                        'message' => "L'employé avec l'ID {$employeeId} n'est pas associé à cette commande.",
                    ], 400);
                }
            }
            
            // Log récapitulatif : combien d'employés ont reçu des cartes
            \Log::info('Ajout de cartes - Récapitulatif distribution employés', [
                'order_id' => $order->id,
                'total_employees_in_distribution' => count($cleanedEmployeesDistribution),
                'employees_with_quantity_gt_0' => $employeesWithCards,
                'employees_with_quantity_0' => count($cleanedEmployeesDistribution) - $employeesWithCards,
                'distribution_details' => array_map(function($empId, $qty) {
                    return ['employee_id' => $empId, 'quantity' => $qty, 'received_cards' => $qty > 0];
                }, array_keys($cleanedEmployeesDistribution), array_values($cleanedEmployeesDistribution)),
            ]);

            // Log des quantités APRÈS les modifications mais AVANT le refresh final
            \Log::info('Ajout de cartes - État APRÈS modifications (avant refresh)', [
                'order_id' => $order->id,
                'order_employees_after' => $order->orderEmployees->map(function ($oe) {
                    return [
                        'employee_id' => $oe->employee_id,
                        'employee_name' => $oe->employee_name,
                        'card_quantity' => $oe->card_quantity,
                        'role' => $oe->employee ? $oe->employee->role : 'unknown',
                    ];
                })->toArray(),
            ]);

            // Mettre à jour le nombre total de cartes de la commande
            $order->increment('card_quantity', $quantity);
        } else {
            // Pour les commandes particulières, comportement original
            if ($order->order_type !== 'personal' && $order->order_type !== 'individual') {
                return response()->json(['message' => 'Pour les commandes business, vous devez spécifier la distribution des cartes.'], 400);
            }

            // Mettre à jour la commande
            $order->increment('card_quantity', $quantity);
        }

        // Mettre à jour les compteurs de cartes supplémentaires
        $order->increment('additional_cards_count', $quantity);
        $order->increment('additional_cards_total_price', $additionalCardsTotalPrice);
        $order->increment('total_price', $additionalCardsTotalPrice);

        // Recharger la commande pour avoir les valeurs à jour
        // IMPORTANT: Recharger directement depuis la base de données pour éviter les problèmes de cache
        $order->refresh();
        // Forcer le rechargement complet des orderEmployees depuis la base de données
        $order->unsetRelation('orderEmployees');
        $order->load(['orderEmployees.employee', 'user']);

        // Log des quantités APRÈS le refresh final (dernière vérification)
        // Vérifier directement dans la base de données pour être sûr
        $freshOrderEmployees = \App\Models\OrderEmployee::where('order_id', $order->id)
            ->with('employee:id,name,email,username,role')
            ->get();
        
        \Log::info('Ajout de cartes - État APRÈS refresh final (depuis DB)', [
            'order_id' => $order->id,
            'order_employees_from_db' => $freshOrderEmployees->map(function ($oe) {
                return [
                    'id' => $oe->id,
                    'employee_id' => $oe->employee_id,
                    'employee_name' => $oe->employee_name,
                    'card_quantity' => $oe->card_quantity,
                    'role' => $oe->employee ? $oe->employee->role : 'unknown',
                ];
            })->toArray(),
            'order_employees_from_relation' => $order->orderEmployees->map(function ($oe) {
                return [
                    'employee_id' => $oe->employee_id,
                    'employee_name' => $oe->employee_name,
                    'card_quantity' => $oe->card_quantity,
                    'role' => $oe->employee ? $oe->employee->role : 'unknown',
                ];
            })->toArray(),
        ]);
        
        // Utiliser les données fraîches de la DB pour la réponse
        $order->setRelation('orderEmployees', $freshOrderEmployees);

        // Construire la réponse avec toutes les données nécessaires
        // Utiliser directement les données fraîches de la relation (qui viennent maintenant de la DB)
        $orderEmployeesData = $freshOrderEmployees->map(function ($oe) {
            return [
                'id' => $oe->id,
                'order_id' => $oe->order_id,
                'employee_id' => $oe->employee_id,
                'employee_name' => $oe->employee_name,
                'employee_email' => $oe->employee_email,
                'card_quantity' => $oe->card_quantity, // Utiliser directement la valeur fraîche de la DB
                'is_configured' => $oe->is_configured,
                'employee' => $oe->employee ? [
                    'id' => $oe->employee->id,
                    'name' => $oe->employee->name,
                    'email' => $oe->employee->email,
                    'username' => $oe->employee->username,
                    'role' => $oe->employee->role,
                ] : null,
            ];
        })->toArray();

        // Log détaillé des quantités de cartes pour chaque employé dans la réponse
        \Log::info('Ajout de cartes - Réponse finale avec order_employees', [
            'order_id' => $order->id,
            'order_card_quantity' => $order->card_quantity,
            'order_employees' => array_map(function ($oe) {
                return [
                    'employee_id' => $oe['employee_id'],
                    'employee_name' => $oe['employee_name'],
                    'card_quantity' => $oe['card_quantity'],
                    'role' => $oe['employee']['role'] ?? 'unknown',
                ];
            }, $orderEmployeesData),
        ]);

        $orderData = [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'card_quantity' => $order->card_quantity,
            'additional_cards_count' => $order->additional_cards_count,
            'additional_cards_total_price' => $order->additional_cards_total_price,
            'total_price' => $order->total_price,
            'status' => $order->status,
            'order_type' => $order->order_type,
            'created_at' => $order->created_at,
            'updated_at' => $order->updated_at,
            'order_employees' => $orderEmployeesData,
        ];

        // Ajouter les informations de l'utilisateur si disponible
        if ($order->user) {
            $orderData['user'] = [
                'id' => $order->user->id,
                'name' => $order->user->name,
                'email' => $order->user->email,
            ];
        }

        return response()->json([
            'message' => "Vous avez ajouté {$quantity} carte(s) supplémentaire(s) à votre commande.",
            'order' => $orderData,
        ]);
    }

    /**
     * Annuler une commande
     */
    public function cancel(Request $request, Order $order)
    {
        $user = $request->user();

        // Vérifier que la commande appartient à l'utilisateur
        if ($order->user_id !== $user->id) {
            return response()->json(['message' => 'Commande non trouvée.'], 404);
        }

        // Vérifier si la commande peut être annulée
        if ($order->status === 'cancelled') {
            return response()->json(['message' => 'Cette commande est déjà annulée.'], 400);
        }

        // ✅ NOUVELLE LOGIQUE : Supprimer les employés et leurs comptes si nécessaire
        $deletedEmployeesCount = 0;
        if ($order->order_type === 'business') {
            // Récupérer tous les employés assignés à cette commande
            $orderEmployees = $order->orderEmployees()->with('employee')->get();

            foreach ($orderEmployees as $orderEmployee) {
                $employee = $orderEmployee->employee;

                // Si l'employé existe (pas déjà supprimé)
                if ($employee) {
                    // ✅ IMPORTANT : Ne JAMAIS supprimer le business admin, même s'il s'est inclus dans la commande
                    if ($employee->role === 'business_admin') {
                        // Supprimer uniquement son assignation à cette commande
                        $orderEmployee->delete();

                        \Log::info("Business admin retiré de la commande (compte préservé)", [
                            'admin_id' => $employee->id,
                            'admin_email' => $employee->email,
                            'order_id' => $order->id,
                        ]);

                        continue; // Passer à l'employé suivant
                    }

                    // Vérifier si cet employé est assigné à d'autres commandes
                    $otherOrdersCount = OrderEmployee::where('employee_id', $employee->id)
                        ->where('order_id', '!=', $order->id)
                        ->count();

                    // Si l'employé n'est assigné qu'à cette commande, supprimer son compte
                    if ($otherOrdersCount === 0) {
                        // Sauvegarder les informations avant suppression pour l'email
                        $employeeEmail = $employee->email;
                        $employeeName = $employee->name;
                        $companyName = $employee->company_name;

                        // Envoyer un email de notification à l'employé
                        try {
                            \Mail::to($employeeEmail)->send(new \App\Mail\EmployeeDeletionNotification(
                                $employeeName,
                                $companyName
                            ));
                            \Log::info('Email de suppression (annulation commande) envoyé à ' . $employeeEmail);
                        } catch (\Throwable $t) {
                            \Log::error("Échec de l'envoi de l'email de suppression à " . $employeeEmail . ": " . $t->getMessage());
                            // On continue même si l'email échoue
                        }

                        // Révoquer tous les tokens d'accès (déconnexion forcée)
                        $employee->tokens()->delete();

                        // Supprimer l'avatar de l'employé s'il existe
                        if ($employee->avatar_url && \Storage::disk('public')->exists(str_replace('/storage/', '', $employee->avatar_url))) {
                            \Storage::disk('public')->delete(str_replace('/storage/', '', $employee->avatar_url));
                        }

                        // Supprimer aussi l'avatar de la commande depuis order_employees
                        if ($orderEmployee->employee_avatar_url && \Storage::disk('public')->exists(str_replace('/storage/', '', $orderEmployee->employee_avatar_url))) {
                            \Storage::disk('public')->delete(str_replace('/storage/', '', $orderEmployee->employee_avatar_url));
                        }

                        \Log::info("Employé supprimé suite à l'annulation de la commande", [
                            'employee_id' => $employee->id,
                            'employee_email' => $employee->email,
                            'order_id' => $order->id,
                            'business_admin_id' => $user->id,
                        ]);

                        // Supprimer le compte utilisateur
                        // (cela supprimera automatiquement toutes les relations en cascade)
                        $employee->delete();
                        $deletedEmployeesCount++;
                    } else {
                        // Si l'employé a d'autres commandes, supprimer uniquement son assignation à cette commande
                        $orderEmployee->delete();
                    }
                }
            }

            // Supprimer toutes les entrées order_employees restantes pour cette commande
            $order->orderEmployees()->delete();
        }

        // Supprimer la commande
        $order->delete();

        // Message adapté selon le nombre d'employés supprimés
        $message = 'Commande annulée avec succès.';
        if ($deletedEmployeesCount > 0) {
            $message .= ' ' . $deletedEmployeesCount . ' compte(s) de personnel supprimé(s) et notifié(s) par email.';
        }

        return response()->json([
            'message' => $message,
        ]);
    }

    /**
     * Vérifie si un utilisateur peut accéder à une commande
     *
     * @param User $user
     * @param Order $order
     * @return bool
     */
    private function canAccessOrder(User $user, Order $order): bool
    {
        // Si l'utilisateur est un employé, vérifier qu'il est assigné à cette commande
        if ($user->role === 'employee') {
            return OrderEmployee::where('order_id', $order->id)
                ->where('employee_id', $user->id)
                ->exists();
        }

        // Pour les autres utilisateurs, vérifier que la commande leur appartient
        return $order->user_id === $user->id;
    }
}
