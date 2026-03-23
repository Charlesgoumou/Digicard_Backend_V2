<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderEmployee;
use App\Models\User;
use App\Models\Setting;
use App\Services\ImageCompressionService;
use App\Services\ChapChapPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
                     'device_uuid', 'device_model',
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
                                  'is_configured', 'access_token', 'short_code',
                                  'security_groups', 'group_security_configs',
                                  // ✅ NOUVEAU : Colonnes pour les cartes supplémentaires
                                  'additional_cards_count', 'additional_cards_total_price',
                                  'created_at', 'updated_at');
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
                // Mais aussi charger les données de profil minimales nécessaires pour ProfileSelectionView
                if ($isBusinessAdmin) {
                    // Charger les données de profil minimales pour l'affichage dans ProfileSelectionView
                    $order->profile_name = $orderEmployee->profile_name;
                    $order->profile_title = $orderEmployee->profile_title;
                    $order->profile_border_color = $orderEmployee->profile_border_color ?? '#facc15';
                    $order->order_avatar_url = $orderEmployee->employee_avatar_url;

                    $order->employee_profile = [
                        'card_design_type' => $orderEmployee->card_design_type,
                        'card_design_number' => $orderEmployee->card_design_number,
                        'card_design_custom_url' => $orderEmployee->card_design_custom_url,
                        'no_design_yet' => $orderEmployee->no_design_yet,
                        // Ajouter le username pour les liens de profil
                        'username' => $user->username ?? null,
                    ];

                    // Ajouter le username au niveau racine pour faciliter l'accès
                    if ($user->username) {
                        $order->profile_username = $user->username;
                    }

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

                    // ✅ CORRECTION : Construire le tableau employee_profile complet avant de l'assigner
                    // pour éviter l'erreur "Indirect modification of overloaded property"
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
                        'card_design_type' => $orderEmployee->card_design_type,
                        'card_design_number' => $orderEmployee->card_design_number,
                        'card_design_custom_url' => $orderEmployee->card_design_custom_url,
                        'no_design_yet' => $orderEmployee->no_design_yet,
                        'device_uuid' => $orderEmployee->device_uuid,
                        'device_model' => $orderEmployee->device_model,
                    ];

                    // ✅ OPTIMISATION : Utiliser les données préchargées au lieu de faire une requête par commande
                    if ($isEmployee && $order->order_type === 'business' && $order->user_id && $businessAdminOrderEmployees) {
                        $businessAdminOrderEmployee = $businessAdminOrderEmployees->get($order->id);

                        if ($businessAdminOrderEmployee) {
                            $hasAdminDesign = !$businessAdminOrderEmployee->no_design_yet &&
                                             ($businessAdminOrderEmployee->card_design_type === 'template' ||
                                              $businessAdminOrderEmployee->card_design_type === 'custom');

                            $employeeProfile['is_design_locked_by_admin'] = true;

                            if ($hasAdminDesign) {
                                $employeeProfile['admin_design'] = [
                                    'card_design_type' => $businessAdminOrderEmployee->card_design_type,
                                    'card_design_number' => $businessAdminOrderEmployee->card_design_number,
                                    'card_design_custom_url' => $businessAdminOrderEmployee->card_design_custom_url,
                                ];

                                $employeeProfile['card_design_type'] = $businessAdminOrderEmployee->card_design_type;
                                $employeeProfile['card_design_number'] = $businessAdminOrderEmployee->card_design_number;
                                $employeeProfile['card_design_custom_url'] = $businessAdminOrderEmployee->card_design_custom_url;
                                $employeeProfile['no_design_yet'] = false;
                            }
                        }
                    }

                    // Assigner le tableau complet à employee_profile
                    $order->employee_profile = $employeeProfile;
                }

                return $order;
            });
        } else {
            $ordersFromEmployees = collect();
        }

        // Si l'utilisateur est business_admin ou individual, récupérer aussi leurs commandes directes (non-business ou business sans inclusion)
        if ($user->role === 'business_admin' || $user->role === 'individual') {
            // ✅ OPTIMISATION : Utiliser select pour charger uniquement les colonnes nécessaires
            // ✅ CORRECTION : Inclure les colonnes de profil pour les commandes individuelles
            $directOrdersQuery = $user->orders()
                ->where('status', '!=', 'cancelled')
                ->select('id', 'user_id', 'order_number', 'order_type', 'card_quantity',
                        'total_employees', 'employee_slots', 'unit_price', 'total_price',
                        'annual_subscription', 'subscription_start_date', 'status',
                        'is_configured', 'access_token', 'short_code',
                        'security_groups', 'group_security_configs',
                        // ✅ CORRECTION : Colonnes de profil pour ProfileSelectionView
                        'profile_name', 'profile_title', 'order_avatar_url', 'profile_border_color',
                        'save_contact_button_color', 'services_button_color',
                        'card_design_type', 'card_design_number', 'card_design_custom_url', 'no_design_yet',
                        // ✅ NOUVEAU : Colonnes pour les cartes supplémentaires
                        'additional_cards_count', 'additional_cards_total_price',
                        'created_at', 'updated_at');

            // ✅ OPTIMISATION : Pour les business_admin, charger les données nécessaires des orderEmployees
            // mais inclure les colonnes de profil pour détecter si l'admin est inclus
            if ($isBusinessAdmin) {
                $directOrdersQuery->with(['orderEmployees' => function ($q) {
                    // Note: slot_number n'existe pas dans order_employees, il est dans employee_slots (JSON) de orders
                    // ✅ CORRECTION : Charger aussi les colonnes de profil pour détecter si l'admin est inclus
                    $q->select('id', 'order_id', 'employee_id', 'employee_name', 'employee_email',
                              'card_quantity', 'is_configured',
                              'profile_name', 'profile_title', 'employee_avatar_url', 'profile_border_color',
                              'card_design_type', 'card_design_number', 'card_design_custom_url', 'no_design_yet',
                              'created_at')
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
                            // ✅ CORRECTION : Charger aussi les données de profil minimales pour ProfileSelectionView
                            $order->employee_card_quantity = $orderEmployee->card_quantity;
                            $order->employee_is_configured = $orderEmployee->is_configured;
                            $order->profile_name = $orderEmployee->profile_name;
                            $order->profile_title = $orderEmployee->profile_title;
                            $order->profile_border_color = $orderEmployee->profile_border_color ?? '#facc15';
                            $order->order_avatar_url = $orderEmployee->employee_avatar_url;

                            // ✅ CORRECTION : Construire employee_profile complet avant de l'assigner
                            $employeeProfile = [
                                'card_design_type' => $orderEmployee->card_design_type,
                                'card_design_number' => $orderEmployee->card_design_number,
                                'card_design_custom_url' => $orderEmployee->card_design_custom_url,
                                'no_design_yet' => $orderEmployee->no_design_yet,
                            ];

                            if ($user->username) {
                                $employeeProfile['username'] = $user->username;
                                $order->profile_username = $user->username;
                            }
                            if ($order->access_token) {
                                $employeeProfile['access_token'] = $order->access_token;
                            }

                            // Assigner le tableau complet à employee_profile
                            $order->employee_profile = $employeeProfile;

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

        // ✅ NOUVEAU : Charger les paiements supplémentaires payés pour chaque commande
        $orderIds = $orders->pluck('id')->unique();
        if ($orderIds->isNotEmpty()) {
            $paidAdditionalPayments = \App\Models\AdditionalCardPayment::whereIn('order_id', $orderIds)
                ->where('payment_status', 'paid')
                ->orderBy('paid_at', 'desc')
                ->get()
                ->groupBy('order_id');

            // Ajouter les paiements supplémentaires à chaque commande
            $orders = $orders->map(function ($order) use ($paidAdditionalPayments) {
                $order->paid_additional_payments = $paidAdditionalPayments->get($order->id, collect())->map(function ($payment) {
                    return [
                        'id' => $payment->id,
                        'quantity' => $payment->quantity,
                        'unit_price' => $payment->unit_price,
                        'total_price' => $payment->total_price,
                        'paid_at' => $payment->paid_at ? $payment->paid_at->format('Y-m-d H:i:s') : null,
                        'distribution' => $payment->distribution,
                    ];
                })->values();
                return $order;
            });
        }

        // S'assurer que $orders est toujours un tableau, même si vide
        $ordersArray = $orders->toArray();

        // ✅ DEBUG : Logger les données pour vérifier que les colonnes de profil sont présentes
        if (!empty($ordersArray)) {
            \Log::info('OrderController::index - Données retournées', [
                'total_orders' => count($ordersArray),
                'first_order' => [
                    'id' => $ordersArray[0]['id'] ?? null,
                    'order_number' => $ordersArray[0]['order_number'] ?? null,
                    'order_type' => $ordersArray[0]['order_type'] ?? null,
                    'profile_name' => $ordersArray[0]['profile_name'] ?? null,
                    'profile_title' => $ordersArray[0]['profile_title'] ?? null,
                    'order_avatar_url' => $ordersArray[0]['order_avatar_url'] ?? null,
                    'profile_border_color' => $ordersArray[0]['profile_border_color'] ?? null,
                    'is_configured' => $ordersArray[0]['is_configured'] ?? null,
                    'additional_cards_count' => $ordersArray[0]['additional_cards_count'] ?? null,
                    'paid_additional_payments_count' => isset($ordersArray[0]['paid_additional_payments']) ? count($ordersArray[0]['paid_additional_payments']) : 0,
                ],
            ]);
        }

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
                    $resolvedDeviceUuid = $orderEmployee->device_uuid ?: ($orderEmployee->employee->device_uuid ?? null);
                    $resolvedDeviceModel = $orderEmployee->device_model ?: ($orderEmployee->employee->device_label ?? null);
                    $slot['is_configured'] = $orderEmployee->is_configured;
                    // Pointage / modale admin : identifiant appareil lié à cette commande
                    $slot['device_uuid'] = $resolvedDeviceUuid;
                    $slot['device_model'] = $resolvedDeviceModel;

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

            // Si c'est un business admin inclus, copier aussi les données de design et de profil au niveau racine de l'order
            // pour que getDesignData dans OrdersView.vue puisse les trouver
            if ($user->role === 'business_admin' && $orderEmployee) {
                $order->card_design_type = $orderEmployee->card_design_type;
                $order->card_design_number = $orderEmployee->card_design_number;
                $order->card_design_custom_url = $orderEmployee->card_design_custom_url;
                $order->no_design_yet = $orderEmployee->no_design_yet;
                // ✅ CORRECTION : Copier aussi les données de profil pour les business admin inclus
                $order->employee_card_quantity = $orderEmployee->card_quantity;
                $order->employee_is_configured = $orderEmployee->is_configured;
                $order->profile_name = $orderEmployee->profile_name;
                $order->profile_title = $orderEmployee->profile_title;
                $order->profile_border_color = $orderEmployee->profile_border_color ?? '#facc15';
                $order->order_avatar_url = $orderEmployee->employee_avatar_url; // ✅ Utiliser employee_avatar_url pour les business admin inclus
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

            $employeeProfile['device_uuid'] = $orderEmployee->device_uuid ?: ($orderEmployee->employee->device_uuid ?? null);
            $employeeProfile['device_model'] = $orderEmployee->device_model ?: ($orderEmployee->employee->device_label ?? null);

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
                $resolvedDeviceUuid = $oe->device_uuid ?: ($oe->employee->device_uuid ?? null);
                $resolvedDeviceModel = $oe->device_model ?: ($oe->employee->device_label ?? null);
                return [
                    'id' => $oe->id,
                    'order_id' => $oe->order_id,
                    'employee_id' => $oe->employee_id,
                    'employee_name' => $oe->employee_name,
                    'employee_email' => $oe->employee_email,
                    'card_quantity' => $oe->card_quantity,
                    'is_configured' => $oe->is_configured,
                    'device_uuid' => $resolvedDeviceUuid,
                    'device_model' => $resolvedDeviceModel,
                    'employee_group' => $oe->employee_group,
                    'employee_matricule' => $oe->employee_matricule,
                    'employee_department' => $oe->employee_department,
                    'profile_name' => $oe->profile_name,
                    'profile_title' => $oe->profile_title,
                    'employee_avatar_url' => $oe->employee_avatar_url, // ✅ CORRECTION : Inclure employee_avatar_url dans order_employees
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
                $username = $user->username ?? '';
                $profileUrl = $username !== ''
                    ? route('profile.public.show', ['user' => $username]) . '?order=' . $order->id
                    : url('/');
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
            } catch (\Throwable $t) {
                Log::warning('OrderController::markAsConfigured notification failed', ['order_id' => $order->id, 'error' => $t->getMessage()]);
            }

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
        // ✅ PROTECTION: Ne jamais modifier le statut si la commande est déjà validée (payée)
        // Une commande payée doit rester payée, quoi qu'il arrive dans les paramètres
        $updateData = ['is_configured' => true];
        if ($order->status !== 'validated') {
            $updateData['status'] = 'configured';
        }
        $order->update($updateData);

        // Notification super admin : commande paramétrée (inclure URL publique)
        try {
            $profileUser = $user;
            $username = $profileUser->username ?? '';
            $profileUrl = $username !== ''
                ? route('profile.public.show', ['user' => $username]) . '?order=' . $order->id
                : url('/');
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
        } catch (\Throwable $t) {
            Log::warning('OrderController::markAsConfigured notification failed', ['order_id' => $order->id, 'error' => $t->getMessage()]);
        }

        return response()->json([
            'message' => 'Commande paramétrée avec succès.',
            'order' => $order,
        ]);
    }

    /**
     * Cookies partagés (sous-domaines) pour le profil public sur une autre origine que le SPA :
     * - jeton d’enrôlement (arcc_emp_o_*)
     * - UUID + modèle enregistrés en base (arcc_dev_o_*) : sans cela, le profil recalcule une empreinte
     *   différente du device_uuid stocké → verify-identity renvoie device_mismatch.
     */
    private function withPointageProfileCookies(
        \Illuminate\Http\JsonResponse $response,
        int $orderId,
        ?string $token,
        ?string $deviceUuid = null,
        ?string $deviceModel = null
    ): \Illuminate\Http\JsonResponse {
        $domain = config('digicard.emp_auth_cookie_domain');
        if (! is_string($domain) || trim($domain) === '') {
            return $response;
        }

        $dom = trim($domain);
        if ($dom !== '' && $dom !== 'null' && ! str_starts_with($dom, '.')) {
            $dom = '.'.$dom;
        }

        $secureCfg = config('digicard.emp_auth_cookie_secure');
        $secure = $secureCfg === null || $secureCfg === ''
            ? request()->secure()
            : filter_var($secureCfg, FILTER_VALIDATE_BOOLEAN);

        $minutes = 60 * 24 * 400;

        if ($token !== null && $token !== '') {
            $response = $response->withCookie(cookie(
                'arcc_emp_o_'.$orderId,
                $token,
                $minutes,
                '/',
                $dom,
                $secure,
                false,
                false,
                'lax'
            ));
        }

        $uuid = is_string($deviceUuid) ? trim($deviceUuid) : '';
        if ($uuid !== '') {
            $model = is_string($deviceModel) ? $deviceModel : '';
            $payload = json_encode(
                ['u' => $uuid, 'm' => $model],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if ($payload !== false) {
                $response = $response->withCookie(cookie(
                    'arcc_dev_o_'.$orderId,
                    $payload,
                    $minutes,
                    '/',
                    $dom,
                    $secure,
                    false,
                    false,
                    'lax'
                ));
            }
        }

        return $response;
    }

    /**
     * Jeton longue durée pour reconnaissance silencieuse du profil public (stocké en localStorage employé).
     */
    private function issueEmpAuthTokenIfMissing(OrderEmployee $orderEmployee): void
    {
        if (! empty($orderEmployee->emp_auth_token)) {
            return;
        }
        $orderEmployee->emp_auth_token = Str::random(64);
        $orderEmployee->save();
    }

    /**
     * Retourne (et crée si besoin) le jeton d’enrôlement pour le pointage sur profil public.
     */
    public function getEmpAuthToken(Request $request, Order $order)
    {
        $user = $request->user();

        if ($user->role !== 'employee') {
            return response()->json(['message' => 'Action non autorisée.'], 403);
        }

        if (! $this->canAccessOrder($user, $order)) {
            return response()->json(['message' => 'Commande non trouvée.'], 404);
        }

        $ot = (string) ($order->order_type ?? '');
        if ($ot !== 'business' && $ot !== 'entreprise') {
            return response()->json(['message' => 'Non applicable.'], 422);
        }

        if ($order->status === 'cancelled') {
            return response()->json(['message' => 'Cette commande a été annulée.'], 400);
        }

        $orderEmployee = OrderEmployee::where('order_id', $order->id)
            ->where('employee_id', $user->id)
            ->first();

        if (! $orderEmployee || ! $orderEmployee->is_configured) {
            return response()->json(['message' => 'Carte non configurée pour cette commande.'], 422);
        }

        if (! $orderEmployee->device_uuid) {
            return response()->json(['message' => 'Liez d’abord votre appareil depuis l’espace personnel.'], 422);
        }

        $this->issueEmpAuthTokenIfMissing($orderEmployee);
        $orderEmployee->refresh();

        return $this->withPointageProfileCookies(
            response()->json([
                'emp_auth_token' => $orderEmployee->emp_auth_token,
            ]),
            $order->id,
            $orderEmployee->emp_auth_token,
            $orderEmployee->device_uuid,
            $orderEmployee->device_model
        );
    }

    /**
     * Enregistre l'empreinte d'appareil pour l'employé sur cette commande (appareil unique par commande).
     */
    public function sealDevice(Request $request, Order $order)
    {
        $user = $request->user();

        if (! $this->canAccessOrder($user, $order)) {
            return response()->json(['message' => 'Commande non trouvée.'], 404);
        }

        if ($order->status === 'cancelled') {
            return response()->json(['message' => 'Cette commande a été annulée.'], 400);
        }

        $ot = (string) ($order->order_type ?? '');
        if ($ot !== 'business' && $ot !== 'entreprise') {
            return response()->json(['message' => 'Liaison d\'appareil réservée aux commandes entreprise.'], 422);
        }

        $validated = $request->validate([
            'device_uuid' => 'required|string|max:128',
            'device_model' => 'required|string|max:255',
        ]);

        $orderEmployee = OrderEmployee::where('order_id', $order->id)
            ->where('employee_id', $user->id)
            ->first();

        if (! $orderEmployee) {
            return response()->json(['message' => 'Aucune assignation pour cette commande.'], 404);
        }

        if (! $orderEmployee->is_configured) {
            return response()->json(['message' => 'Configurez d\'abord votre carte avant de lier un appareil.'], 422);
        }

        if ($orderEmployee->device_uuid) {
            if ($orderEmployee->device_uuid !== $validated['device_uuid']) {
                return response()->json([
                    'message' => 'Un autre appareil est déjà lié à cette commande.',
                    'code' => 'device_mismatch',
                ], 409);
            }
            $orderEmployee->device_model = $validated['device_model'];
            $orderEmployee->save();
            $this->issueEmpAuthTokenIfMissing($orderEmployee);
            $orderEmployee->refresh();

            return $this->withPointageProfileCookies(
                response()->json([
                    'message' => 'Appareil déjà enregistré.',
                    'sealed' => true,
                    'emp_auth_token' => $orderEmployee->emp_auth_token,
                ]),
                $order->id,
                $orderEmployee->emp_auth_token,
                $orderEmployee->device_uuid,
                $orderEmployee->device_model
            );
        }

        $storedModel = $orderEmployee->device_model;
        if (is_string($storedModel) && trim($storedModel) !== '') {
            if (! $this->deviceModelsCompatible($storedModel, $validated['device_model'])) {
                return response()->json([
                    'message' => 'Veuillez contacter votre administrateur pour changer d\'appareil de pointage.',
                    'code' => 'device_model_mismatch',
                ], 422);
            }
        }

        $orderEmployee->device_uuid = $validated['device_uuid'];
        $orderEmployee->device_model = $validated['device_model'];
        $orderEmployee->save();
        $this->issueEmpAuthTokenIfMissing($orderEmployee);
        $orderEmployee->refresh();
        $this->sendDevicePointageRestrictionEmail($order, $orderEmployee);

        return $this->withPointageProfileCookies(
            response()->json([
                'message' => 'Appareil lié avec succès.',
                'sealed' => true,
                'emp_auth_token' => $orderEmployee->emp_auth_token,
            ]),
            $order->id,
            $orderEmployee->emp_auth_token,
            $orderEmployee->device_uuid,
            $orderEmployee->device_model
        );
    }

    /**
     * Migration : enregistre l’UUID uniquement si la colonne device_uuid est encore vide.
     * Refuse si un modèle était déjà stocké et ne correspond pas au terminal actuel.
     */
    public function updateDeviceIdentity(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'order_id' => 'required|integer|exists:orders,id',
            'device_uuid' => 'required|string|max:128',
            'device_model' => 'required|string|max:255',
        ]);

        $order = Order::findOrFail($validated['order_id']);

        if (! $this->canAccessOrder($user, $order)) {
            return response()->json(['message' => 'Commande non trouvée.'], 404);
        }

        if ($order->status === 'cancelled') {
            return response()->json(['message' => 'Cette commande a été annulée.'], 400);
        }

        $ot = (string) ($order->order_type ?? '');
        if ($ot !== 'business' && $ot !== 'entreprise') {
            return response()->json(['message' => 'Non applicable.'], 422);
        }

        $orderEmployee = OrderEmployee::where('order_id', $order->id)
            ->where('employee_id', $user->id)
            ->first();

        if (! $orderEmployee) {
            return response()->json(['message' => 'Aucune assignation pour cette commande.'], 404);
        }

        if (! $orderEmployee->is_configured) {
            return response()->json(['message' => 'Configurez d\'abord votre carte avant de lier un appareil.'], 422);
        }

        if ($orderEmployee->device_uuid) {
            return response()->json([
                'message' => 'Un appareil est déjà enregistré pour cette commande.',
                'code' => 'device_already_bound',
            ], 422);
        }

        $storedModel = $orderEmployee->device_model;
        if (is_string($storedModel) && trim($storedModel) !== '') {
            if (! $this->deviceModelsCompatible($storedModel, $validated['device_model'])) {
                return response()->json([
                    'message' => 'Veuillez contacter votre administrateur pour changer d\'appareil de pointage.',
                    'code' => 'device_model_mismatch',
                ], 422);
            }
        }

        $orderEmployee->device_uuid = $validated['device_uuid'];
        $orderEmployee->device_model = $validated['device_model'];
        $orderEmployee->save();
        $this->issueEmpAuthTokenIfMissing($orderEmployee);
        $orderEmployee->refresh();
        $this->sendDevicePointageRestrictionEmail($order, $orderEmployee);

        return $this->withPointageProfileCookies(
            response()->json([
                'message' => 'Identité appareil enregistrée.',
                'sealed' => true,
                'emp_auth_token' => $orderEmployee->emp_auth_token,
            ]),
            $order->id,
            $orderEmployee->emp_auth_token,
            $orderEmployee->device_uuid,
            $orderEmployee->device_model
        );
    }

    /**
     * Indique si le libellé « modèle » courant correspond au modèle historique (sans UUID).
     */
    private function deviceModelsCompatible(?string $stored, string $incoming): bool
    {
        $stored = $stored !== null ? trim($stored) : '';
        $incoming = trim($incoming);

        if ($stored === '') {
            return true;
        }

        $a = mb_strtolower(preg_replace('/\s+/u', ' ', $stored));
        $b = mb_strtolower(preg_replace('/\s+/u', ' ', $incoming));

        if ($a === $b) {
            return true;
        }

        if (str_contains($b, $a) || str_contains($a, $b)) {
            return true;
        }

        $iosA = (bool) preg_match('/\b(iphone|ipad|ios|ipados)\b/u', $a);
        $iosB = (bool) preg_match('/\b(iphone|ipad|ios|ipados)\b/u', $b);
        $andA = (bool) preg_match('/\bandroid\b/u', $a);
        $andB = (bool) preg_match('/\bandroid\b/u', $b);

        if (($iosA && $andB) || ($iosB && $andA)) {
            return false;
        }

        preg_match_all('/[a-z0-9][a-z0-9\.\-]{2,}/iu', $a, $ma);
        $skip = ['android', 'linux', 'arm64', 'arm', 'aarch64', 'web', 'mobile', 'like', 'gecko'];

        foreach (array_unique($ma[0] ?? []) as $t) {
            $tl = mb_strtolower($t);
            if (mb_strlen($tl) >= 4 && ! in_array($tl, $skip, true) && str_contains($b, $tl)) {
                return true;
            }
        }

        preg_match_all('/[a-z0-9][a-z0-9\.\-]{2,}/iu', $b, $mb);

        foreach (array_unique($mb[0] ?? []) as $t) {
            $tl = mb_strtolower($t);
            if (mb_strlen($tl) >= 4 && ! in_array($tl, $skip, true) && str_contains($a, $tl)) {
                return true;
            }
        }

        return false;
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
                if ($orderEmployee->employee_avatar_url) {
                    // ✅ CORRECTION : Gérer les deux formats (/storage/ et /api/storage/)
                    $oldPath = preg_replace('#^/api/storage/#', '', $orderEmployee->employee_avatar_url);
                    $oldPath = preg_replace('#^/storage/#', '', $oldPath);
                    $oldPath = preg_replace('#^https?://[^/]+/(api/)?storage/#', '', $oldPath);
                    if (Storage::disk('public')->exists($oldPath)) {
                        Storage::disk('public')->delete($oldPath);
                    }
                }

                // Compresser et stocker la nouvelle photo
                $compressionService = new ImageCompressionService();
                $result = $compressionService->compressImage($request->file('avatar'), 'employee_avatars');

                // ✅ CORRECTION : Vérifier que le fichier a bien été créé
                if (!isset($result['path']) || !Storage::disk('public')->exists($result['path'])) {
                    Log::error('OrderController::uploadOrderAvatar - Fichier non créé après compression', [
                        'result' => $result,
                        'order_id' => $order->id,
                        'employee_id' => $user->id,
                    ]);
                    return response()->json([
                        'message' => 'Erreur lors du stockage de la photo.',
                    ], 500);
                }

                // ✅ CORRECTION : Utiliser Storage::url() pour générer l'URL correcte
                // Laravel génère automatiquement l'URL basée sur la configuration (config/filesystems.php)
                // En production avec Nginx, cela génère /storage/order_avatars/image.jpg
                $url = Storage::disk('public')->url($result['path']);

                // Mettre à jour l'URL de l'avatar de l'employé
                $orderEmployee->update(['employee_avatar_url' => $url]);

                Log::info('OrderController::uploadOrderAvatar - Photo uploadée avec succès', [
                    'order_id' => $order->id,
                    'employee_id' => $user->id,
                    'avatar_url' => $url,
                    'file_exists' => Storage::disk('public')->exists($result['path']),
                ]);

                return response()->json([
                    'message' => 'Photo de commande mise à jour avec succès.',
                    'avatar_url' => $url,
                ]);
            }

            // Sinon, mettre à jour dans orders (pour les commandes sans order_employee)
            // Supprimer l'ancienne photo de commande si elle existe
            if ($order->order_avatar_url) {
                // ✅ CORRECTION : Gérer les deux formats (/storage/ et /api/storage/)
                $oldPath = preg_replace('#^/api/storage/#', '', $order->order_avatar_url);
                $oldPath = preg_replace('#^/storage/#', '', $oldPath);
                $oldPath = preg_replace('#^https?://[^/]+/(api/)?storage/#', '', $oldPath);
                if (Storage::disk('public')->exists($oldPath)) {
                    Storage::disk('public')->delete($oldPath);
                }
            }

            // Compresser et stocker la nouvelle photo
            $compressionService = new ImageCompressionService();
            $result = $compressionService->compressImage($request->file('avatar'), 'order_avatars');

            // ✅ CORRECTION : Vérifier que le fichier a bien été créé
            if (!isset($result['path']) || !Storage::disk('public')->exists($result['path'])) {
                Log::error('OrderController::uploadOrderAvatar - Fichier non créé après compression', [
                    'result' => $result,
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                ]);
                return response()->json([
                    'message' => 'Erreur lors du stockage de la photo.',
                ], 500);
            }

            // ✅ CORRECTION : Utiliser Storage::url() pour générer l'URL correcte
            // Laravel génère automatiquement l'URL basée sur la configuration (config/filesystems.php)
            // En production avec Nginx, cela génère /storage/order_avatars/image.jpg
            $url = Storage::disk('public')->url($result['path']);

            // Mettre à jour l'URL de l'avatar de la commande
            // ✅ PROTECTION: Ne jamais modifier le statut lors de l'upload d'un avatar
            // ✅ IMPORTANT: Ne PAS synchroniser avec users.avatar_url - l'avatar du Dashboard doit rester celui de l'utilisateur
            $order->update(['order_avatar_url' => $url]);

            Log::info('OrderController::uploadOrderAvatar - Photo uploadée avec succès', [
                'order_id' => $order->id,
                'user_id' => $user->id,
                'avatar_url' => $url,
                'file_exists' => Storage::disk('public')->exists($result['path']),
            ]);

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

        // ✅ PROTECTION: Ne jamais modifier le statut ou is_configured si la commande est déjà validée
        // Une commande payée doit rester payée, quoi qu'il arrive dans les paramètres
        $isOrderValidated = $order->status === 'validated';

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
            'tiktok_url' => 'nullable|url:http,https',
            'threads_url' => 'nullable|url:http,https',
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
            // ✅ PROTECTION: Si la commande est validée, ne pas modifier is_configured via updateProfile
            // (seulement via markAsConfigured qui a sa propre logique de protection)
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
        // ✅ PROTECTION: Si la commande est validée, ne jamais modifier le statut ou is_configured
        // Une commande payée doit rester payée, quoi qu'il arrive dans les paramètres
        if ($isOrderValidated) {
            // Exclure les champs sensibles qui ne doivent pas être modifiés sur une commande validée
            unset($validatedData['status']);
            // Note: is_configured n'est pas dans validatedData car il n'est pas validé dans la requête
            // Il est géré séparément par markAsConfigured
        }
        $order->update($validatedData);

        return response()->json([
            'message' => 'Données de profil mises à jour avec succès.',
            'order' => $order,
        ]);
    }

    /**
     * Mettre à jour les groupes de sécurité d'une commande business (pour /personnel -> onglet Paramètres).
     */
    public function updateSecurityGroups(Request $request, Order $order)
    {
        $user = $request->user();

        // Autoriser uniquement le propriétaire de la commande (business_admin)
        if ($user->role !== 'business_admin' || $order->user_id !== $user->id) {
            return response()->json(['message' => 'Commande non trouvée.'], 404);
        }

        if ($order->status === 'cancelled') {
            return response()->json(['message' => "Cette commande a été annulée et ne peut plus être modifiée."], 400);
        }

        if ($order->order_type !== 'business' && $order->order_type !== 'entreprise') {
            return response()->json(['message' => "Action non autorisée pour ce type de commande."], 403);
        }

        $validated = $request->validate([
            'security_groups' => 'nullable|array',
            'security_groups.*' => 'required|string|max:80',
            'group_security_configs' => 'nullable|array',
            'group_security_configs.*' => 'nullable|array',
        ]);

        $groups = $validated['security_groups'] ?? [];
        $incomingConfigs = $validated['group_security_configs'] ?? [];

        $normalized = [];
        $alignedConfigs = [];
        foreach ($groups as $i => $g) {
            $v = trim((string) $g);
            if ($v === '') {
                continue;
            }
            if (in_array($v, $normalized, true)) {
                continue;
            }
            $normalized[] = $v;
            $cfg = $incomingConfigs[$i] ?? null;
            $alignedConfigs[] = is_array($cfg) && $cfg !== [] ? $cfg : null;
        }

        $order->security_groups = $normalized;
        $order->group_security_configs = $alignedConfigs;
        $order->save();

        return response()->json([
            'message' => 'Groupes mis à jour avec succès.',
            'security_groups' => $order->security_groups ?? [],
            'group_security_configs' => $order->group_security_configs ?? [],
            'order' => $order,
        ], 200);
    }

    /**
     * Affecter un employé existant à un groupe de sécurité d'une commande business.
     */
    public function updateEmployeeGroup(Request $request, Order $order)
    {
        $user = $request->user();

        // Autoriser uniquement le propriétaire de la commande (business_admin)
        if ($user->role !== 'business_admin' || $order->user_id !== $user->id) {
            return response()->json(['message' => 'Commande non trouvée.'], 404);
        }

        if ($order->status === 'cancelled') {
            return response()->json(['message' => "Cette commande a été annulée et ne peut plus être modifiée."], 400);
        }

        if ($order->order_type !== 'business' && $order->order_type !== 'entreprise') {
            return response()->json(['message' => "Action non autorisée pour ce type de commande."], 403);
        }

        $validated = $request->validate([
            'order_employee_id' => 'required|integer|exists:order_employees,id',
            'employee_group' => 'required|string|max:80',
        ]);

        $orderEmployee = OrderEmployee::where('id', $validated['order_employee_id'])
            ->where('order_id', $order->id)
            ->first();

        if (! $orderEmployee) {
            return response()->json(['message' => 'Employé introuvable pour cette commande.'], 404);
        }

        $targetGroup = trim((string) $validated['employee_group']);
        if ($targetGroup === '') {
            return response()->json(['message' => 'Le groupe est requis.'], 422);
        }

        $securityGroups = is_array($order->security_groups) ? $order->security_groups : [];
        $groupConfigs = is_array($order->group_security_configs) ? $order->group_security_configs : [];
        $targetGroupConfig = [];

        $allowedGroups = [];
        foreach ($securityGroups as $index => $rawName) {
            $name = trim((string) $rawName);
            if ($name === '') {
                continue;
            }
            $cfg = $groupConfigs[$index] ?? null;
            if (! is_array($cfg) || $cfg === []) {
                continue;
            }
            $allowedGroups[] = $name;
            if ($name === $targetGroup) {
                $targetGroupConfig = $cfg;
            }
        }

        if (! in_array($targetGroup, $allowedGroups, true)) {
            return response()->json([
                'message' => 'Ce groupe n’est pas disponible pour cette commande.',
                'allowed_groups' => $allowedGroups,
            ], 422);
        }

        $orderEmployee->employee_group = $targetGroup;
        $orderEmployee->save();

        // Garder aussi employee_slots synchronisé pour les écrans qui lisent encore ce JSON.
        $slots = is_array($order->employee_slots) ? $order->employee_slots : [];
        $slotUpdated = false;
        foreach ($slots as $idx => $slot) {
            $slotEmployeeId = isset($slot['employee_id']) ? (int) $slot['employee_id'] : null;
            if ($slotEmployeeId !== null && $slotEmployeeId === (int) $orderEmployee->employee_id) {
                $slots[$idx]['employee_group'] = $targetGroup;
                $slotUpdated = true;
                break;
            }
        }
        if ($slotUpdated) {
            $order->employee_slots = $slots;
            $order->save();
        }

        // Email d'information à l'utilisateur affecté (employé ou business admin inclus comme order_employee).
        $recipient = trim((string) ($orderEmployee->employee_email ?? ''));
        if ($recipient !== '' && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            try {
                $adminName = trim((string) ($user->name ?? 'Business Admin'));
                if ($adminName === '') {
                    $adminName = 'Business Admin';
                } elseif (! str_starts_with(mb_strtolower($adminName), 'm.')) {
                    $adminName = 'M. '.$adminName;
                }

                $employeeName = trim((string) ($orderEmployee->employee_name ?? ''));
                if ($employeeName === '') {
                    $employeeName = trim((string) ($orderEmployee->profile_name ?? ''));
                }
                if ($employeeName === '') {
                    $employeeName = $recipient;
                }

                $companyName = null;
                if (is_string($order->profile_name ?? null) && trim((string) $order->profile_name) !== '') {
                    $companyName = trim((string) $order->profile_name);
                } elseif ($order->relationLoaded('user') && $order->user) {
                    $companyName = $order->user->name;
                } elseif (isset($order->user_id)) {
                    $owner = User::find($order->user_id);
                    $companyName = $owner?->name;
                }

                \Mail::to($recipient)->send(new \App\Mail\EmployeeGroupAssignmentMail(
                    employeeName: $employeeName,
                    groupName: $targetGroup,
                    adminName: $adminName,
                    orderNumber: (string) ($order->order_number ?? $order->id),
                    companyName: $companyName,
                    groupConfig: $targetGroupConfig,
                    deviceModel: $orderEmployee->device_model
                ));
            } catch (\Throwable $e) {
                Log::warning('OrderController::updateEmployeeGroup email not sent', [
                    'order_id' => $order->id,
                    'order_employee_id' => $orderEmployee->id,
                    'recipient' => $recipient,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Employé affecté au groupe avec succès.',
            'order_employee_id' => $orderEmployee->id,
            'employee_id' => $orderEmployee->employee_id,
            'employee_group' => $orderEmployee->employee_group,
            'allowed_groups' => $allowedGroups,
        ], 200);
    }

    private function sendDevicePointageRestrictionEmail(Order $order, OrderEmployee $orderEmployee): void
    {
        $recipient = trim((string) ($orderEmployee->employee_email ?? ''));
        if ($recipient === '' || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $employeeName = trim((string) ($orderEmployee->employee_name ?? ''));
        if ($employeeName === '') {
            $employeeName = trim((string) ($orderEmployee->profile_name ?? ''));
        }
        if ($employeeName === '') {
            $employeeName = $recipient;
        }

        $companyName = null;
        if (is_string($order->profile_name ?? null) && trim((string) $order->profile_name) !== '') {
            $companyName = trim((string) $order->profile_name);
        } elseif ($order->relationLoaded('user') && $order->user) {
            $companyName = $order->user->name;
        } elseif (isset($order->user_id)) {
            $owner = User::find($order->user_id);
            $companyName = $owner?->name;
        }

        try {
            \Mail::to($recipient)->send(new \App\Mail\EmployeeDevicePointageRestrictionMail(
                employeeName: $employeeName,
                deviceModel: (string) ($orderEmployee->device_model ?? ''),
                orderNumber: (string) ($order->order_number ?? $order->id),
                companyName: $companyName
            ));
        } catch (\Throwable $e) {
            Log::warning('OrderController::sendDevicePointageRestrictionEmail failed', [
                'order_id' => $order->id,
                'order_employee_id' => $orderEmployee->id,
                'recipient' => $recipient,
                'error' => $e->getMessage(),
            ]);
        }
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
                // ✅ CORRECTION : Utiliser Storage::url() pour générer l'URL correcte
                // Laravel génère automatiquement l'URL basée sur la configuration (config/filesystems.php)
                // En production avec Nginx, cela génère /storage/custom_designs/image.jpg
                $url = Storage::disk('public')->url($result['path']);
            } else {
                // Pour les fichiers non-image (PDF, SVG, etc.), stocker tel quel
                $path = $file->store('custom_designs', 'public');
                // ✅ CORRECTION : Utiliser Storage::url() pour générer l'URL correcte
                // Laravel génère automatiquement l'URL basée sur la configuration (config/filesystems.php)
                // En production avec Nginx, cela génère /storage/custom_designs/file.pdf
                $url = Storage::disk('public')->url($path);
            }

            // Si un order_id est fourni, mettre à jour la commande
            if ($orderId && isset($order)) {
                // Supprimer l'ancien design personnalisé si il existe
                if ($order->card_design_custom_url) {
                    // ✅ CORRECTION : Gérer les deux formats (/storage/ et /api/storage/)
                    $oldPath = preg_replace('#^/api/storage/#', '', $order->card_design_custom_url);
                    $oldPath = preg_replace('#^/storage/#', '', $oldPath);
                    $oldPath = preg_replace('#^https?://[^/]+/(api/)?storage/#', '', $oldPath);
                    if (Storage::disk('public')->exists($oldPath)) {
                        Storage::disk('public')->delete($oldPath);
                    }
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

        // ✅ MODIFICATION: Appeler Chap Chap Pay pour générer un lien de paiement
        try {
            $chapChapPayService = new ChapChapPayService();

            // ✅ CORRECTION: Le montant total_price est déjà en centimes (ex: 180000 = 1800.00 GNF)
            // D'après OrderController::store(), total_price est calculé directement en centimes
            // Il ne faut donc PAS multiplier par 100
            $amount = (int) $order->total_price; // total_price est déjà en centimes

            Log::info('Chap Chap Pay: Calcul du montant', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'total_price_from_db' => $order->total_price,
                'total_price_type' => gettype($order->total_price),
                'amount_sent_to_api' => $amount,
            ]);

            // Construire les URLs
            // ✅ MODIFICATION: URL de retour après paiement : pointer vers le frontend Vue
            // En développement local, le frontend tourne sur localhost:5173, le backend sur localhost:8000
            // En production, ils sont généralement sur le même domaine (APP_URL)
            // ✅ CORRECTION: Utiliser config('app.frontend_url') qui gère déjà la logique de nettoyage
            $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173'));

            // ✅ CORRECTION: Nettoyer l'URL pour ne prendre que la première URL valide
            // Si plusieurs URLs sont séparées par une virgule, prendre seulement la première
            if (strpos($frontendUrl, ',') !== false) {
                $urls = explode(',', $frontendUrl);
                $frontendUrl = trim($urls[0]); // Prendre la première URL
            }
            $frontendUrl = trim($frontendUrl);

            // ✅ CRITIQUE: En production, s'assurer que l'URL pointe vers le frontend, pas le backend
            if (app()->environment('production') && str_contains($frontendUrl, 'digicard-api.arccenciel.com')) {
                // Remplacer digicard-api par digicard pour pointer vers le frontend
                $frontendUrl = str_replace('digicard-api.arccenciel.com', 'digicard.arccenciel.com', $frontendUrl);
                Log::warning("OrderController: Frontend URL corrigée pour pointer vers le frontend", [
                    'original_url' => config('app.frontend_url'),
                    'corrected_url' => $frontendUrl,
                ]);
            }

            // ✅ NOUVEAU: Générer un token de session pour la récupération après redirection externe
            $sessionToken = Str::random(60);
            $order->update(['payment_session_token' => $sessionToken]);

            Log::info('OrderController: Token de session généré pour la commande', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'token_length' => strlen($sessionToken),
            ]);

            // ✅ MODIFICATION: return_url pointe maintenant vers une page simple /payment/close
            // L'onglet principal fait du polling pour détecter le paiement
            // On passe l'ID de commande dans l'URL pour permettre la simulation en développement
            // ✅ CORRECTION: Utiliser config('app.frontend_url') qui gère déjà la logique de nettoyage
            $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173'));

            // ✅ CORRECTION: Nettoyer l'URL pour ne prendre que la première URL valide
            // Si plusieurs URLs sont séparées par une virgule, prendre seulement la première
            if (strpos($frontendUrl, ',') !== false) {
                $urls = explode(',', $frontendUrl);
                $frontendUrl = trim($urls[0]); // Prendre la première URL
            }
            $frontendUrl = trim($frontendUrl);

            // ✅ CRITIQUE: En production, s'assurer que l'URL pointe vers le frontend, pas le backend
            if (app()->environment('production') && str_contains($frontendUrl, 'digicard-api.arccenciel.com')) {
                // Remplacer digicard-api par digicard pour pointer vers le frontend
                $frontendUrl = str_replace('digicard-api.arccenciel.com', 'digicard.arccenciel.com', $frontendUrl);
                Log::warning("OrderController: Frontend URL corrigée pour pointer vers le frontend", [
                    'original_url' => config('app.frontend_url'),
                    'corrected_url' => $frontendUrl,
                ]);
            }

            $returnUrl = rtrim($frontendUrl, '/') . '/payment/close?order_id=' . $order->id;

            $notifyUrl = url('/') . '/api/payment/webhook'; // URL du webhook (URL complète pour que Chap Chap Pay puisse l'appeler)

            // Créer le lien de paiement via Chap Chap Pay
            // ✅ MODIFICATION: Nettoyer la description pour éviter les fausses alertes de sécurité
            // Remplacer le caractère # par "numéro" pour éviter la détection d'injection SQL
            // L'order_id doit rester le order_number original car l'API le requiert
            $description = 'Paiement commande numero ' . $order->order_number;

            $paymentData = [
                'amount' => $amount,
                'description' => $description, // Description nettoyée (sans #)
                'order_id' => $order->order_number, // ✅ Garder le order_number original (requis par l'API)
                // ✅ MODIFICATION: fee_handling supprimé car non disponible pour votre entreprise
                'return_url' => $returnUrl,
                'notify_url' => $notifyUrl,
                'options' => [
                    'auto-redirect' => true,
                ],
            ];

            Log::info('Chap Chap Pay: Données de paiement préparées', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'description' => $description,
                'amount' => $amount,
                'return_url' => $returnUrl,
                'notify_url' => $notifyUrl,
            ]);

            $paymentResponse = $chapChapPayService->createPaymentLink($paymentData);

            if ($paymentResponse && isset($paymentResponse['payment_url'])) {
                // Le paiement a été initié avec succès
                // ✅ MODIFICATION: Sauvegarder l'operation_id pour pouvoir vérifier le statut plus tard
                $operationId = $paymentResponse['operation_id'] ?? null;

                // Sauvegarder l'operation_id dans un champ personnalisé (si la table orders a un champ pour cela)
                // Sinon, on peut l'enregistrer dans les meta ou settings
                // Pour l'instant, on le retourne dans la réponse

                Log::info('Chap Chap Pay: Lien de paiement généré avec succès', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'amount' => $amount,
                    'payment_url' => $paymentResponse['payment_url'],
                    'operation_id' => $operationId,
                ]);

                return response()->json([
                    'message' => 'Redirection vers le paiement en cours...',
                    'payment_url' => $paymentResponse['payment_url'],
                    'operation_id' => $operationId, // ✅ Retourner l'operation_id pour pouvoir vérifier le statut
                    'order' => [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                    ],
                ]);
            } else {
                // Erreur lors de la création du lien de paiement
                // ✅ MODIFICATION: Ajouter des logs détaillés avant de retourner l'erreur
                Log::error('Chap Chap Pay: Erreur lors de la génération du lien de paiement - PaymentResponse est null', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'amount' => $amount,
                    'return_url' => $returnUrl,
                    'notify_url' => $notifyUrl,
                    'payment_response' => $paymentResponse,
                ]);

                // Log séparé pour faciliter le débogage
                Log::error('Chap Chap Pay: Détails de la requête qui a échoué', [
                    'request_data' => $paymentData,
                    'api_key_present' => !empty(config('services.chapchappay.public_key')),
                    'base_url' => config('services.chapchappay.base_url'),
                ]);

                return response()->json([
                    'message' => 'Erreur lors de la génération du lien de paiement. Veuillez réessayer plus tard. Consultez les logs pour plus de détails.',
                    'error' => 'payment_link_generation_failed',
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ], 500);
            }
        } catch (\Exception $e) {
            // ✅ MODIFICATION: Ajouter des logs détaillés pour les exceptions
            Log::error('Chap Chap Pay: Exception lors de la génération du lien de paiement', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'amount' => $amount ?? null,
                'return_url' => $returnUrl ?? null,
                'notify_url' => $notifyUrl ?? null,
            ]);

            // Log séparé avec le message d'erreur pour faciliter le débogage
            Log::error('Chap Chap Pay: Message d\'erreur exception dans OrderController: ' . $e->getMessage());
            Log::error('Chap Chap Pay: Trace complète de l\'exception: ' . $e->getTraceAsString());

            return response()->json([
                'message' => 'Erreur lors de l\'initialisation du paiement. Veuillez réessayer plus tard. Consultez les logs pour plus de détails.',
                'error' => 'payment_initialization_failed',
                'error_detail' => $e->getMessage(), // En développement seulement
            ], 500);
        }
    }

    /**
     * Webhook pour confirmer le paiement et valider la commande
     */
    public function paymentWebhook(Request $request)
    {
        try {
            // ✅ Log initial pour confirmer que le webhook est appelé
            Log::info('Chap Chap Pay: Webhook appelé', [
                'method' => $request->method(),
                'content_type' => $request->header('Content-Type'),
                'has_content' => strlen($request->getContent()) > 0,
                'content_length' => strlen($request->getContent()),
                'all_headers' => $request->headers->all(),
            ]);

            // 1. Tenter de récupérer les données (Méthode standard + Méthode brute fallback)
            $data = $request->all();

            // Log des données standard
            Log::info('Chap Chap Pay: Données via request->all()', [
                'data' => $data,
                'is_empty' => empty($data),
                'count' => count($data)
            ]);

            // Vérifier si $data est un tableau avec une seule chaîne JSON (cas spécial)
            $needsJsonDecode = false;
            if (is_array($data) && count($data) === 1 && isset($data[0]) && is_string($data[0])) {
                // Vérifier si c'est du JSON valide
                $testDecode = json_decode($data[0], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($testDecode)) {
                    $needsJsonDecode = true;
                }
            }

            // Si Laravel n'a rien trouvé via input() OU si on a détecté une chaîne JSON dans un tableau
            if (empty($data) || $needsJsonDecode) {
                $content = $request->getContent();

                // Si on a déjà une chaîne JSON dans le tableau, l'utiliser
                if ($needsJsonDecode && isset($data[0])) {
                    $content = $data[0];
                    Log::info('Chap Chap Pay: Utilisation de la chaîne JSON du tableau', [
                        'content_length' => strlen($content),
                        'content_preview' => substr($content, 0, 500),
                    ]);
                } else {
                    Log::info('Chap Chap Pay: Tentative de décodage JSON brut', [
                        'content_length' => strlen($content),
                        'content_preview' => substr($content, 0, 500),
                    ]);
                }

                $decodedData = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::warning('Chap Chap Pay: Erreur de décodage JSON', [
                        'error' => json_last_error_msg(),
                        'content' => substr($content, 0, 500)
                    ]);
                    // Ne pas écraser $data si le décodage échoue
                    if (empty($data)) {
                        $data = [];
                    }
                } else {
                    $data = $decodedData;
                    Log::info('Chap Chap Pay: JSON décodé avec succès', [
                        'data' => $data
                    ]);
                }
            }

            // Si toujours vide, essayer de parser comme query string
            if (empty($data)) {
                parse_str($request->getContent(), $parsedData);
                if (!empty($parsedData)) {
                    $data = $parsedData;
                    Log::info('Chap Chap Pay: Données parsées depuis query string', [
                        'data' => $data
                    ]);
                }
            }

            Log::info('Chap Chap Pay: Webhook reçu (Données finales)', [
                'data' => $data,
                'data_keys' => array_keys($data ?? []),
                'data_type' => gettype($data),
                'is_array' => is_array($data)
            ]);

            // Gestion spéciale : Si $data est un tableau avec une seule chaîne JSON
            if (is_array($data) && count($data) === 1 && isset($data[0]) && is_string($data[0])) {
                Log::info('Chap Chap Pay: Détection d\'une chaîne JSON dans un tableau', [
                    'first_element' => substr($data[0], 0, 200)
                ]);
                $decoded = json_decode($data[0], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $data = $decoded;
                    Log::info('Chap Chap Pay: Chaîne JSON décodée avec succès', [
                        'data' => $data
                    ]);
                }
            }

            // 2. Récupération des champs depuis $data (et non plus $request->input)
            $orderIdOrNumber = $data['order_id'] ?? null;
            $rawStatus = $data['status'] ?? null;

            // Gestion intelligente du statut (String ou Objet)
            $status = null;
            if (is_array($rawStatus) && isset($rawStatus['code'])) {
                $status = $rawStatus['code'];
            } elseif (is_string($rawStatus)) {
                $status = $rawStatus;
            }

            // Vérification des données
            if (!$orderIdOrNumber || !$status) {
                Log::error('Chap Chap Pay: Données incomplètes après décodage', ['data' => $data]);
                return response()->json(['message' => 'Données incomplètes.'], 400);
            }

            // 3. Recherche de la commande (ID ou Numéro)
            // ✅ IMPORTANT: Chap Chap Pay envoie order_number comme order_id (voir validate() ligne 1320)
            $order = null;

            // Essayer d'abord par order_number (format le plus probable depuis Chap Chap Pay)
            $order = Order::where('order_number', $orderIdOrNumber)->first();

            // Si pas trouvé et que c'est numérique, essayer par ID
            if (!$order && is_numeric($orderIdOrNumber)) {
                $order = Order::find((int) $orderIdOrNumber);
            }

            // Si toujours pas trouvé, essayer de chercher dans les différents formats possibles
            if (!$order) {
                // Peut-être que Chap Chap Pay envoie un format différent
                // Essayer de chercher avec différents formats
                $order = Order::where('order_number', 'like', '%' . $orderIdOrNumber . '%')->first();
            }

            if (!$order) {
                Log::error('Chap Chap Pay: Commande introuvable', [
                    'order_id_received' => $orderIdOrNumber,
                    'type' => gettype($orderIdOrNumber),
                    'is_numeric' => is_numeric($orderIdOrNumber),
                    'data_received' => $data
                ]);
                return response()->json(['message' => 'Commande non trouvée.'], 404);
            }

            Log::info('Chap Chap Pay: Commande trouvée', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'current_status' => $order->status,
                'order_id_received' => $orderIdOrNumber
            ]);

            // 4. Validation du paiement
            if ($status === 'success' || $status === 'paid' || $status === 'completed') {
                $wasAlreadyValidated = $order->status === 'validated';

                if (!$wasAlreadyValidated) {
                    // Mettre à jour le statut de la commande
                    $order->update([
                        'status' => 'validated',
                        'subscription_start_date' => now()->format('Y-m-d'),
                    ]);

                    // Recharger la commande pour avoir le statut à jour
                    $order->refresh();

                    Log::info('Chap Chap Pay: Commande validée avec succès', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'new_status' => $order->status,
                        'subscription_start_date' => $order->subscription_start_date
                    ]);

                    // ✅ Matching Marketplace (queue ou sync selon config)
                    try {
                        $driver = config('queue.default');
                        if ($driver === 'sync') {
                            (new \App\Jobs\ProcessMarketplaceMatching((int) $order->user_id))->handle();
                        } else {
                            \App\Jobs\ProcessMarketplaceMatching::dispatch($order->user_id);
                        }
                        Log::info('ProcessMarketplaceMatching: Job dispatché après validation de commande', [
                            'order_id' => $order->id,
                            'user_id' => $order->user_id
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Erreur lors du dispatch du Job ProcessMarketplaceMatching', [
                            'order_id' => $order->id,
                            'user_id' => $order->user_id,
                            'error' => $e->getMessage()
                        ]);
                    }

                    // Notifications (Email & Admin)
                    try {
                        // Admin Notif
                        $user = $order->user; // S'assurer que user est chargé
                        $profileUrl = url('/') . '/' . $user->username;

                        Log::info('Chap Chap Pay: Création de la notification admin', [
                            'order_id' => $order->id,
                            'user_id' => $user->id
                        ]);

                        \App\Models\AdminNotification::create([
                            'type' => 'order_validated',
                            'user_id' => $user->id,
                            'order_id' => $order->id,
                            'message' => 'Commande validée par ' . $user->name . ' (#' . $order->order_number . ')',
                            'url' => $profileUrl,
                            'meta' => ['order_number' => $order->order_number],
                        ]);

                        // Email Client
                        Log::info('Chap Chap Pay: Envoi email client', [
                            'order_id' => $order->id,
                            'user_email' => $user->email
                        ]);

                        try {
                            \Mail::to($user->email)->send(new \App\Mail\OrderValidated($order, $user));
                            Log::info('Chap Chap Pay: Email client envoyé avec succès', [
                                'order_id' => $order->id,
                                'user_email' => $user->email
                            ]);
                        } catch (\Throwable $emailError) {
                            Log::error('Chap Chap Pay: Erreur envoi email client', [
                                'order_id' => $order->id,
                                'user_email' => $user->email,
                                'error' => $emailError->getMessage(),
                                'file' => $emailError->getFile(),
                                'line' => $emailError->getLine(),
                                'trace' => $emailError->getTraceAsString()
                            ]);
                        }

                        // Email Admin
                        $adminEmail = 'charleshaba454@gmail.com';
                        Log::info('Chap Chap Pay: Envoi email admin', [
                            'order_id' => $order->id,
                            'admin_email' => $adminEmail
                        ]);

                        try {
                            $mailable = new \App\Mail\AdminOrderPaymentNotification($order, $user, false);
                            // Utiliser Mail::to() pour garantir l'envoi même si le destinataire est défini dans build()
                            \Mail::to($adminEmail)->send($mailable);
                            Log::info('Chap Chap Pay: Email admin envoyé avec succès', [
                                'order_id' => $order->id,
                                'admin_email' => $adminEmail
                            ]);
                        } catch (\Throwable $emailError) {
                            Log::error('Chap Chap Pay: Erreur envoi email admin', [
                                'order_id' => $order->id,
                                'admin_email' => $adminEmail,
                                'error' => $emailError->getMessage(),
                                'file' => $emailError->getFile(),
                                'line' => $emailError->getLine(),
                                'trace' => $emailError->getTraceAsString()
                            ]);
                        }

                        Log::info('Chap Chap Pay: Notifications et emails traités', [
                            'order_id' => $order->id,
                            'user_email' => $user->email
                        ]);

                    } catch (\Throwable $e) {
                        Log::error('Chap Chap Pay: Erreur notifications webhook', [
                            'order_id' => $order->id,
                            'error' => $e->getMessage(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'trace' => $e->getTraceAsString()
                        ]);
                    }
                } else {
                    Log::info('Chap Chap Pay: Commande déjà validée (webhook reçu plusieurs fois)', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'current_status' => $order->status
                    ]);
                }

                return response()->json([
                    'message' => 'Paiement confirmé avec succès.',
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'was_already_validated' => $wasAlreadyValidated
                ]);
            } else {
                Log::warning('Chap Chap Pay: Statut de paiement non valide', [
                    'order_id' => $order->id ?? null,
                    'order_number' => $order->order_number ?? null,
                    'status_received' => $status,
                    'raw_status' => $rawStatus
                ]);
                return response()->json(['message' => 'Statut non valide: ' . $status], 400);
            }

        } catch (\Exception $e) {
            Log::error('Erreur Webhook Fatal', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request_data' => $request->all(),
                'request_content' => $request->getContent(),
            ]);
            return response()->json(['message' => 'Erreur serveur.'], 500);
        }
    }

    /**
     * Webhook pour confirmer le paiement des cartes supplémentaires
     * Cette méthode est appelée par Chap Chap Pay après un paiement réussi
     */
    public function paymentWebhookAdditionalCards(Request $request)
    {
        // ✅ Log TRÈS tôt pour confirmer que la méthode est appelée
        \Log::info('=== WEBHOOK CARTES SUPPLEMENTAIRES DEBUT ===', [
            'timestamp' => now()->toDateTimeString(),
            'method' => $request->method(),
            'ip' => $request->ip(),
        ]);

        try {
            // ✅ Log initial pour confirmer que le webhook est appelé
            Log::info('Chap Chap Pay: Webhook cartes supplémentaires appelé', [
                'method' => $request->method(),
                'content_type' => $request->header('Content-Type'),
                'has_content' => strlen($request->getContent()) > 0,
                'content_length' => strlen($request->getContent()),
            ]);

            // 1. Tenter de récupérer les données (Méthode standard + Méthode brute fallback)
            $data = $request->all();

            // Log des données standard
            Log::info('Chap Chap Pay: Webhook cartes supplémentaires - Données via request->all()', [
                'data' => $data,
                'is_empty' => empty($data),
                'count' => count($data),
                'is_array' => is_array($data),
                'first_element_type' => is_array($data) && isset($data[0]) ? gettype($data[0]) : 'N/A'
            ]);

            // ✅ PRIORITÉ 1: Si $data est un tableau avec une seule chaîne JSON, décoder directement
            if (is_array($data) && count($data) === 1 && isset($data[0]) && is_string($data[0])) {
                Log::info('Chap Chap Pay: Webhook cartes supplémentaires - Détection d\'une chaîne JSON dans un tableau (décodage direct)', [
                    'content_preview' => substr($data[0], 0, 200)
                ]);

                $decoded = json_decode($data[0], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $data = $decoded;
                    Log::info('Chap Chap Pay: Webhook cartes supplémentaires - Chaîne JSON décodée avec succès (décodage direct)', [
                        'data' => $data,
                        'data_keys' => array_keys($data ?? [])
                    ]);
                } else {
                    Log::warning('Chap Chap Pay: Webhook cartes supplémentaires - Échec du décodage direct', [
                        'error' => json_last_error_msg(),
                        'content_preview' => substr($data[0], 0, 200)
                    ]);
                    // Continuer avec le processus normal
                }
            }

            // ✅ PRIORITÉ 2: Si les données sont vides ou ne contiennent pas les clés attendues, essayer le contenu brut
            if (empty($data) || (is_array($data) && !isset($data['order_id']) && !isset($data[0]))) {
                $content = $request->getContent();
                Log::info('Chap Chap Pay: Webhook cartes supplémentaires - Tentative de décodage JSON brut', [
                    'content_length' => strlen($content),
                    'content_preview' => substr($content, 0, 500),
                ]);

                $decodedData = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::warning('Chap Chap Pay: Webhook cartes supplémentaires - Erreur de décodage JSON brut', [
                        'error' => json_last_error_msg(),
                        'content' => substr($content, 0, 500)
                    ]);
                    // Ne pas écraser $data si le décodage échoue
                    if (empty($data)) {
                        $data = [];
                    }
                } else {
                    // Vérifier si le résultat est encore une chaîne JSON (double encodage)
                    if (is_string($decodedData)) {
                        Log::info('Chap Chap Pay: Webhook cartes supplémentaires - Détection d\'un double encodage JSON', [
                            'decoded_preview' => substr($decodedData, 0, 200)
                        ]);
                        $doubleDecoded = json_decode($decodedData, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($doubleDecoded)) {
                            $data = $doubleDecoded;
                            Log::info('Chap Chap Pay: Webhook cartes supplémentaires - Double décodage JSON réussi', [
                                'data' => $data
                            ]);
                        } else {
                            $data = $decodedData;
                            Log::info('Chap Chap Pay: Webhook cartes supplémentaires - JSON décodé (chaîne simple)', [
                                'data' => $data
                            ]);
                        }
                    } else {
                        $data = $decodedData;
                        Log::info('Chap Chap Pay: Webhook cartes supplémentaires - JSON brut décodé avec succès', [
                            'data' => $data
                        ]);
                    }
                }
            }

            // ✅ PRIORITÉ 3: Si toujours vide, essayer de parser comme query string
            if (empty($data) || (is_array($data) && !isset($data['order_id']))) {
                parse_str($request->getContent(), $parsedData);
                if (!empty($parsedData) && isset($parsedData['order_id'])) {
                    $data = $parsedData;
                    Log::info('Chap Chap Pay: Webhook cartes supplémentaires - Données parsées depuis query string', [
                        'data' => $data
                    ]);
                }
            }

            // Gestion spéciale : Si $data est toujours un tableau avec une seule chaîne JSON (après tous les décodages)
            // Cela peut arriver si le contenu brut était aussi une chaîne JSON échappée
            if (is_array($data) && count($data) === 1 && isset($data[0]) && is_string($data[0])) {
                Log::info('Chap Chap Pay: Webhook cartes supplémentaires - Détection finale d\'une chaîne JSON dans un tableau', [
                    'first_element_preview' => substr($data[0], 0, 200)
                ]);
                $decoded = json_decode($data[0], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $data = $decoded;
                    Log::info('Chap Chap Pay: Webhook cartes supplémentaires - Chaîne JSON décodée avec succès (étape finale)', [
                        'data' => $data,
                        'data_keys' => array_keys($data ?? [])
                    ]);
                } else {
                    Log::warning('Chap Chap Pay: Webhook cartes supplémentaires - Échec du décodage final', [
                        'error' => json_last_error_msg(),
                        'first_element_preview' => substr($data[0], 0, 200)
                    ]);
                }
            }

            Log::info('Chap Chap Pay: Webhook cartes supplémentaires reçu (Données finales)', [
                'data' => $data,
                'data_keys' => array_keys($data ?? []),
                'data_type' => gettype($data),
                'is_array' => is_array($data),
                'is_numeric_array' => is_array($data) && isset($data[0]) && !isset($data['order_id'])
            ]);

            // 2. Récupération des champs depuis $data (et non plus $request->validate)
            $orderIdOrNumber = $data['order_id'] ?? null;
            $rawStatus = $data['status'] ?? null;
            $transactionId = $data['transaction_id'] ?? null;
            $operationId = $data['operation_id'] ?? null;

            // Gestion intelligente du statut (String ou Objet)
            $status = null;
            if (is_array($rawStatus) && isset($rawStatus['code'])) {
                $status = $rawStatus['code'];
            } elseif (is_string($rawStatus)) {
                $status = $rawStatus;
            }

            // Vérification des données requises avec logging détaillé
            if (!$orderIdOrNumber || !$status) {
                Log::error('Chap Chap Pay: Données incomplètes après décodage (cartes supplémentaires)', [
                    'data' => $data,
                    'order_id' => $orderIdOrNumber,
                    'status' => $status,
                    'raw_status' => $rawStatus,
                    'data_type' => gettype($data),
                    'is_array' => is_array($data),
                    'data_keys' => is_array($data) ? array_keys($data) : 'not_array'
                ]);
                return response()->json(['message' => 'Données incomplètes.'], 400);
            }

            // Extraire l'ID du paiement supplémentaire depuis order_id (format: order_number-ADD-payment_id)
            $additionalPaymentId = null;
            if (strpos($orderIdOrNumber, '-ADD-') !== false) {
                $parts = explode('-ADD-', $orderIdOrNumber);
                if (isset($parts[1])) {
                    $additionalPaymentId = (int) $parts[1];
                }
            }

            // Chercher le paiement supplémentaire par operation_id ou par ID
            $additionalPayment = null;
            if ($operationId) {
                $additionalPayment = \App\Models\AdditionalCardPayment::where('payment_operation_id', $operationId)->first();
            }

            if (!$additionalPayment && $additionalPaymentId) {
                $additionalPayment = \App\Models\AdditionalCardPayment::find($additionalPaymentId);
            }

            if (!$additionalPayment) {
                Log::error('Chap Chap Pay: Paiement supplémentaire non trouvé dans le webhook', [
                    'order_id' => $orderIdOrNumber,
                    'operation_id' => $operationId,
                    'additional_payment_id' => $additionalPaymentId,
                    'transaction_id' => $transactionId,
                ]);
                return response()->json(['message' => 'Paiement supplémentaire non trouvé.'], 404);
            }

            // Vérifier que le paiement n'est pas déjà traité
            if ($additionalPayment->payment_status === 'paid') {
                Log::info('Chap Chap Pay: Paiement supplémentaire déjà traité', [
                    'additional_payment_id' => $additionalPayment->id,
                    'order_id' => $additionalPayment->order_id,
                ]);
                return response()->json([
                    'message' => 'Paiement déjà confirmé.',
                    'additional_payment_id' => $additionalPayment->id,
                ]);
            }

            // Si le paiement est réussi, appliquer les cartes à la commande
            if ($status === 'success' || $status === 'paid' || $status === 'completed') {
                $order = $additionalPayment->order;
                $user = $additionalPayment->user;
                $quantity = $additionalPayment->quantity;
                $distribution = $additionalPayment->distribution;
                $totalPrice = $additionalPayment->total_price;

                // Charger les order_employees
                $order->load(['orderEmployees.employee']);

                // Appliquer la distribution des cartes
                if (($order->order_type === 'business' || $order->order_type === 'entreprise') && $distribution) {
                    $adminQuantity = isset($distribution['admin']) ? (int) $distribution['admin'] : 0;
                    $employeesDistribution = $distribution['employees'] ?? [];

                    // Ajouter des cartes pour le business admin
                    if ($adminQuantity > 0) {
                        $adminOrderEmployee = $order->orderEmployees->where('employee_id', $user->id)->first();
                        if ($adminOrderEmployee) {
                            $adminOrderEmployee->increment('card_quantity', $adminQuantity);
                            \Log::info('Webhook cartes supplémentaires: Cartes ajoutées pour le business admin', [
                                'order_id' => $order->id,
                                'employee_id' => $user->id,
                                'quantity' => $adminQuantity,
                            ]);
                        }
                    }

                    // Ajouter des cartes pour les employés
                    foreach ($employeesDistribution as $employeeId => $employeeQuantity) {
                        $employeeQuantityInt = (int) $employeeQuantity;
                        if ($employeeQuantityInt > 0) {
                            $employeeOrderEmployee = $order->orderEmployees->where('employee_id', $employeeId)->first();
                            if ($employeeOrderEmployee) {
                                $employeeOrderEmployee->increment('card_quantity', $employeeQuantityInt);
                                \Log::info('Webhook cartes supplémentaires: Cartes ajoutées pour un employé', [
                                    'order_id' => $order->id,
                                    'employee_id' => $employeeId,
                                    'quantity' => $employeeQuantityInt,
                                ]);
                            }
                        }
                    }

                    // Mettre à jour le nombre total de cartes de la commande
                    $order->increment('card_quantity', $quantity);
                } else {
                    // Pour les commandes particulières
                    $order->increment('card_quantity', $quantity);
                }

                // Mettre à jour les compteurs de cartes supplémentaires et le montant total
                $order->increment('additional_cards_count', $quantity);
                $order->increment('additional_cards_total_price', $totalPrice);
                $order->increment('total_price', $totalPrice);

                // Mettre à jour le statut du paiement
                $additionalPayment->update([
                    'payment_status' => 'paid',
                    'paid_at' => now(),
                ]);

                // ✅ NOUVEAU: Notification super admin pour les cartes supplémentaires ajoutées
                try {
                    $profileUrl = url('/') . '/' . $user->username;
                    if ($order->is_configured) {
                        $profileUrl .= '?order=' . $order->id;
                    }

                    // Construire le message de notification
                    $message = "{$quantity} carte(s) supplémentaire(s) ajoutée(s) à la commande #{$order->order_number} par {$user->name}";

                    // Pour les commandes entreprise, ajouter les détails de la distribution
                    $distributionDetails = [];
                    if (($order->order_type === 'business' || $order->order_type === 'entreprise') && $distribution) {
                        $adminQuantity = isset($distribution['admin']) ? (int) $distribution['admin'] : 0;
                        $employeesDistribution = $distribution['employees'] ?? [];

                        // Détails pour le business admin
                        if ($adminQuantity > 0) {
                            $adminOrderEmployee = $order->orderEmployees->where('employee_id', $user->id)->first();
                            $adminName = $adminOrderEmployee ? $adminOrderEmployee->employee_name : $user->name;
                            $distributionDetails[] = [
                                'name' => $adminName,
                                'role' => 'business_admin',
                                'quantity' => $adminQuantity,
                            ];
                        }

                        // Détails pour les employés
                        foreach ($employeesDistribution as $employeeId => $employeeQuantity) {
                            $employeeQuantityInt = (int) $employeeQuantity;
                            if ($employeeQuantityInt > 0) {
                                $employeeOrderEmployee = $order->orderEmployees->where('employee_id', $employeeId)->first();
                                if ($employeeOrderEmployee) {
                                    $distributionDetails[] = [
                                        'name' => $employeeOrderEmployee->employee_name,
                                        'role' => 'employee',
                                        'employee_id' => $employeeId,
                                        'quantity' => $employeeQuantityInt,
                                    ];
                                }
                            }
                        }

                        // Ajouter les détails à la fin du message
                        if (!empty($distributionDetails)) {
                            $detailsText = [];
                            foreach ($distributionDetails as $detail) {
                                $detailsText[] = "{$detail['name']}: {$detail['quantity']} carte(s)";
                            }
                            $message .= " (" . implode(', ', $detailsText) . ")";
                        }
                    }

                    \App\Models\AdminNotification::create([
                        'type' => 'additional_cards_added',
                        'user_id' => $user->id,
                        'order_id' => $order->id,
                        'message' => $message,
                        'url' => $profileUrl,
                        'meta' => [
                            'order_number' => $order->order_number,
                            'quantity' => $quantity,
                            'total_price' => $totalPrice,
                            'unit_price' => $additionalPayment->unit_price,
                            'payment_transaction_id' => $transactionId,
                            'additional_payment_id' => $additionalPayment->id,
                            'order_type' => $order->order_type,
                            'distribution_details' => $distributionDetails, // Détails complets pour les commandes entreprise
                        ],
                    ]);

                    \Log::info('Notification admin créée pour cartes supplémentaires ajoutées', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'quantity' => $quantity,
                        'order_type' => $order->order_type,
                        'has_distribution_details' => !empty($distributionDetails),
                    ]);
                } catch (\Throwable $t) {
                    \Log::error('Erreur lors de la création de la notification admin pour cartes supplémentaires: ' . $t->getMessage(), [
                        'order_id' => $order->id,
                        'additional_payment_id' => $additionalPayment->id,
                        'error' => $t->getTraceAsString(),
                    ]);
                }

                // ✅ NOUVEAU: Envoyer l'email de confirmation au client pour les cartes supplémentaires
                Log::info('Chap Chap Pay: Envoi email client (cartes supplémentaires)', [
                    'order_id' => $order->id,
                    'user_email' => $user->email
                ]);

                try {
                    $clientMailable = new \App\Mail\AdditionalCardsAdded($order, $user, $additionalPayment);
                    \Mail::to($user->email)->send($clientMailable);

                    Log::info('Chap Chap Pay: Email client envoyé avec succès (cartes supplémentaires)', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'additional_payment_id' => $additionalPayment->id,
                        'user_id' => $user->id,
                        'user_email' => $user->email,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('Chap Chap Pay: Erreur envoi email client (cartes supplémentaires)', [
                        'order_id' => $order->id,
                        'user_email' => $user->email,
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString(),
                        'additional_payment_id' => $additionalPayment->id,
                    ]);
                }

                // ✅ NOUVEAU: Envoyer l'email de notification au super admin pour les cartes supplémentaires
                $adminEmail = 'charleshaba454@gmail.com';
                Log::info('Chap Chap Pay: Envoi email admin (cartes supplémentaires)', [
                    'order_id' => $order->id,
                    'admin_email' => $adminEmail
                ]);

                try {
                    $mailable = new \App\Mail\AdminOrderPaymentNotification($order, $user, true, $additionalPayment);
                    \Mail::to($adminEmail)->send($mailable);

                    Log::info('Chap Chap Pay: Email admin envoyé avec succès (cartes supplémentaires)', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'additional_payment_id' => $additionalPayment->id,
                        'user_id' => $user->id,
                        'admin_email' => $adminEmail,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('Chap Chap Pay: Erreur envoi email admin (cartes supplémentaires)', [
                        'order_id' => $order->id,
                        'admin_email' => $adminEmail,
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString(),
                        'additional_payment_id' => $additionalPayment->id,
                    ]);
                }

                \Log::info('Chap Chap Pay: Paiement supplémentaire confirmé et cartes ajoutées', [
                    'additional_payment_id' => $additionalPayment->id,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'quantity' => $quantity,
                    'total_price' => $totalPrice,
                    'transaction_id' => $transactionId,
                ]);

                return response()->json([
                    'message' => 'Paiement confirmé et cartes ajoutées avec succès.',
                    'additional_payment_id' => $additionalPayment->id,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]);
            } else {
                // Le paiement n'a pas été réussi
                $additionalPayment->update(['payment_status' => 'failed']);

                $order = $additionalPayment->order;

                Log::warning('Chap Chap Pay: Paiement supplémentaire échoué ou en attente', [
                    'additional_payment_id' => $additionalPayment->id,
                    'order_id' => $order->id ?? null,
                    'transaction_id' => $transactionId,
                    'status' => $status,
                ]);

                return response()->json([
                    'message' => 'Paiement non confirmé.',
                    'status' => $status,
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('=== WEBHOOK CARTES SUPPLEMENTAIRES ERREUR ===', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
                'request_content' => $request->getContent(),
            ]);

            return response()->json([
                'message' => 'Erreur lors du traitement du webhook.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Vérifie le statut d'un paiement supplémentaire
     */
    public function checkAdditionalPaymentStatus(Request $request, $additionalPaymentId)
    {
        try {
            $additionalPayment = \App\Models\AdditionalCardPayment::find($additionalPaymentId);

            // ✅ FORCER LE RECHARGEMENT depuis la base de données (éviter le cache)
            if ($additionalPayment) {
                $additionalPayment->refresh();
            }

            if (!$additionalPayment) {
                return response()->json([
                    'message' => 'Paiement supplémentaire non trouvé.',
                ], 404);
            }

            // ✅ CORRECTION: Permettre la vérification sans authentification pour les retours de paiement
            // Après une redirection externe, la session peut être perdue
            // On vérifie seulement que le paiement existe (sécurité basique)
            // En production, on pourrait ajouter une vérification par token ou signature
            $user = auth()->user();
            if ($user && $additionalPayment->user_id !== $user->id) {
                return response()->json([
                    'message' => 'Non autorisé à vérifier ce paiement.',
                ], 403);
            }
            // Si l'utilisateur n'est pas authentifié, on continue quand même
            // car c'est probablement un retour de paiement après redirection externe

            $order = $additionalPayment->order;

            // Si le paiement est payé, retourner le statut
            if ($additionalPayment->payment_status === 'paid') {
                Log::info('Chap Chap Pay: Paiement supplémentaire confirmé', [
                    'additional_payment_id' => $additionalPayment->id,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]);

                return response()->json([
                    'status' => 'paid',
                    'additional_payment_id' => $additionalPayment->id,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'quantity' => $additionalPayment->quantity,
                    'total_price' => $additionalPayment->total_price,
                    'message' => 'Paiement confirmé et cartes ajoutées avec succès.',
                ]);
            }

            // ✅ NOUVEAU: Si le paiement est en attente, valider automatiquement en développement local
            // (comme pour les paiements initiaux, le webhook ne peut pas être appelé en localhost)
            if ($additionalPayment->payment_status === 'pending') {
                // Calculer le temps depuis la création du paiement
                $minutesSinceCreation = abs($additionalPayment->created_at->diffInMinutes(now()));

                Log::info('Chap Chap Pay: Vérification du statut du paiement supplémentaire', [
                    'additional_payment_id' => $additionalPayment->id,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'current_status' => $additionalPayment->payment_status,
                    'minutes_since_creation' => $minutesSinceCreation,
                    'environment' => app()->environment(),
                ]);

                // ✅ VALIDATION AUTOMATIQUE: UNIQUEMENT en local
                // En production, seul le webhook de Chap Chap Pay doit valider les paiements
                // Cela évite de valider des paiements avant que l'utilisateur ait effectivement payé
                $shouldProcess = false;

                if (app()->environment('local')) {
                    // En local, traiter automatiquement car le webhook ne peut pas être appelé
                    $shouldProcess = true;
                    Log::info('Chap Chap Pay: Traitement automatique du paiement supplémentaire (environnement local - webhook non disponible)', [
                        'additional_payment_id' => $additionalPayment->id,
                        'order_id' => $order->id,
                    ]);
                } else {
                    // ✅ EN PRODUCTION: Ne JAMAIS valider automatiquement
                    // Seul le webhook de Chap Chap Pay doit valider le paiement après confirmation du paiement réel
                    Log::info('Chap Chap Pay: Paiement supplémentaire en attente (production - attente du webhook)', [
                        'additional_payment_id' => $additionalPayment->id,
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'minutes_since_creation' => $minutesSinceCreation,
                        'note' => 'Le webhook de Chap Chap Pay validera le paiement après confirmation du paiement réel',
                    ]);
                }

                if ($shouldProcess) {
                    // Appeler la logique du webhook pour traiter le paiement
                    // Simuler un webhook avec status = 'success'
                    try {
                        $user = $additionalPayment->user;
                        $quantity = $additionalPayment->quantity;
                        $distribution = $additionalPayment->distribution;
                        $totalPrice = $additionalPayment->total_price;

                        // Charger les order_employees
                        $order->load(['orderEmployees.employee']);

                        // Appliquer la distribution des cartes
                        if (($order->order_type === 'business' || $order->order_type === 'entreprise') && $distribution) {
                            $adminQuantity = isset($distribution['admin']) ? (int) $distribution['admin'] : 0;
                            $employeesDistribution = $distribution['employees'] ?? [];

                            // Ajouter des cartes pour le business admin
                            if ($adminQuantity > 0) {
                                $adminOrderEmployee = $order->orderEmployees->where('employee_id', $user->id)->first();
                                if ($adminOrderEmployee) {
                                    $adminOrderEmployee->increment('card_quantity', $adminQuantity);
                                }
                            }

                            // Ajouter des cartes pour les employés
                            foreach ($employeesDistribution as $employeeId => $employeeQuantity) {
                                $employeeQuantityInt = (int) $employeeQuantity;
                                if ($employeeQuantityInt > 0) {
                                    $employeeOrderEmployee = $order->orderEmployees->where('employee_id', $employeeId)->first();
                                    if ($employeeOrderEmployee) {
                                        $employeeOrderEmployee->increment('card_quantity', $employeeQuantityInt);
                                    }
                                }
                            }

                            // Mettre à jour le nombre total de cartes de la commande
                            $order->increment('card_quantity', $quantity);
                        } else {
                            // Pour les commandes particulières
                            $order->increment('card_quantity', $quantity);
                        }

                        // Mettre à jour les compteurs de cartes supplémentaires et le montant total
                        $order->increment('additional_cards_count', $quantity);
                        $order->increment('additional_cards_total_price', $totalPrice);
                        $order->increment('total_price', $totalPrice);

                        // Mettre à jour le statut du paiement
                        $additionalPayment->update([
                            'payment_status' => 'paid',
                            'paid_at' => now(),
                        ]);

                        // ✅ NOUVEAU: Créer la notification admin (comme dans le webhook)
                        try {
                            $profileUrl = url('/') . '/' . $user->username;
                            if ($order->is_configured) {
                                $profileUrl .= '?order=' . $order->id;
                            }

                            $message = "{$quantity} carte(s) supplémentaire(s) ajoutée(s) à la commande #{$order->order_number} par {$user->name}";

                            // Pour les commandes entreprise, ajouter les détails de la distribution
                            $distributionDetails = [];
                            if (($order->order_type === 'business' || $order->order_type === 'entreprise') && $distribution) {
                                $adminQuantity = isset($distribution['admin']) ? (int) $distribution['admin'] : 0;
                                $employeesDistribution = $distribution['employees'] ?? [];

                                if ($adminQuantity > 0) {
                                    $adminOrderEmployee = $order->orderEmployees->where('employee_id', $user->id)->first();
                                    $adminName = $adminOrderEmployee ? $adminOrderEmployee->employee_name : $user->name;
                                    $distributionDetails[] = [
                                        'name' => $adminName,
                                        'role' => 'business_admin',
                                        'quantity' => $adminQuantity,
                                    ];
                                }

                                foreach ($employeesDistribution as $employeeId => $employeeQuantity) {
                                    $employeeQuantityInt = (int) $employeeQuantity;
                                    if ($employeeQuantityInt > 0) {
                                        $employeeOrderEmployee = $order->orderEmployees->where('employee_id', $employeeId)->first();
                                        if ($employeeOrderEmployee) {
                                            $distributionDetails[] = [
                                                'name' => $employeeOrderEmployee->employee_name,
                                                'role' => 'employee',
                                                'employee_id' => $employeeId,
                                                'quantity' => $employeeQuantityInt,
                                            ];
                                        }
                                    }
                                }

                                if (!empty($distributionDetails)) {
                                    $detailsText = [];
                                    foreach ($distributionDetails as $detail) {
                                        $detailsText[] = "{$detail['name']}: {$detail['quantity']} carte(s)";
                                    }
                                    $message .= " (" . implode(', ', $detailsText) . ")";
                                }
                            }

                            \App\Models\AdminNotification::create([
                                'type' => 'additional_cards_added',
                                'user_id' => $user->id,
                                'order_id' => $order->id,
                                'message' => $message,
                                'url' => $profileUrl,
                                'meta' => [
                                    'order_number' => $order->order_number,
                                    'quantity' => $quantity,
                                    'total_price' => $totalPrice,
                                    'unit_price' => $additionalPayment->unit_price,
                                    'additional_payment_id' => $additionalPayment->id,
                                    'order_type' => $order->order_type,
                                    'distribution_details' => $distributionDetails,
                                ],
                            ]);
                        } catch (\Throwable $t) {
                            Log::error('Erreur lors de la création de la notification admin pour cartes supplémentaires: ' . $t->getMessage());
                        }

                        // ✅ NOUVEAU: Envoyer l'email de confirmation au client pour les cartes supplémentaires
                        try {
                            Log::info('Tentative d\'envoi de l\'email client (cartes supplémentaires - validation automatique)', [
                                'order_id' => $order->id,
                                'user_email' => $user->email,
                                'additional_payment_id' => $additionalPayment->id,
                                'mailer' => config('mail.default', 'log'),
                                'mail_host' => config('mail.mailers.smtp.host'),
                                'mail_from' => config('mail.from.address'),
                            ]);

                            $clientMailable = new \App\Mail\AdditionalCardsAdded($order, $user, $additionalPayment);
                            \Mail::to($user->email)->send($clientMailable);

                            Log::info('Email client envoyé avec succès pour cartes supplémentaires (validation automatique)', [
                                'order_id' => $order->id,
                                'order_number' => $order->order_number,
                                'additional_payment_id' => $additionalPayment->id,
                                'user_id' => $user->id,
                                'user_email' => $user->email,
                                'mailer' => config('mail.default', 'log'),
                            ]);
                        } catch (\Throwable $e) {
                            Log::error('Erreur lors de l\'envoi de l\'email client pour cartes supplémentaires (validation automatique)', [
                                'order_id' => $order->id,
                                'user_email' => $user->email,
                                'error' => $e->getMessage(),
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'trace' => $e->getTraceAsString(),
                                'additional_payment_id' => $additionalPayment->id,
                                'mailer' => config('mail.default', 'log'),
                            ]);
                        }

                        // ✅ NOUVEAU: Envoyer l'email de notification au super admin
                        try {
                            Log::info('Tentative d\'envoi de l\'email admin (cartes supplémentaires - validation automatique)', [
                                'order_id' => $order->id,
                                'admin_email' => 'charleshaba454@gmail.com',
                                'additional_payment_id' => $additionalPayment->id,
                                'mailer' => config('mail.default', 'log'),
                                'mail_host' => config('mail.mailers.smtp.host'),
                                'mail_from' => config('mail.from.address'),
                            ]);

                            $mailable = new \App\Mail\AdminOrderPaymentNotification($order, $user, true, $additionalPayment);
                            \Mail::to('charleshaba454@gmail.com')->send($mailable);

                            Log::info('Email de notification admin envoyé avec succès pour cartes supplémentaires (validation automatique)', [
                                'order_id' => $order->id,
                                'order_number' => $order->order_number,
                                'additional_payment_id' => $additionalPayment->id,
                                'admin_email' => 'charleshaba454@gmail.com',
                                'mailer' => config('mail.default', 'log'),
                            ]);
                        } catch (\Exception $e) {
                            Log::error('Erreur lors de l\'envoi de l\'email de notification admin pour cartes supplémentaires (validation automatique)', [
                                'error' => $e->getMessage(),
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'trace' => $e->getTraceAsString(),
                                'order_id' => $order->id,
                                'additional_payment_id' => $additionalPayment->id,
                                'admin_email' => 'charleshaba454@gmail.com',
                                'mailer' => config('mail.default', 'log'),
                            ]);
                        }

                        Log::info('Chap Chap Pay: Paiement supplémentaire traité automatiquement', [
                            'additional_payment_id' => $additionalPayment->id,
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'quantity' => $quantity,
                            'total_price' => $totalPrice,
                        ]);

                        // Retourner le statut payé
                        return response()->json([
                            'status' => 'paid',
                            'additional_payment_id' => $additionalPayment->id,
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'quantity' => $quantity,
                            'total_price' => $totalPrice,
                            'message' => 'Paiement confirmé et cartes ajoutées avec succès.',
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Erreur lors du traitement automatique du paiement supplémentaire', [
                            'additional_payment_id' => $additionalPayment->id,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);

                        // En cas d'erreur, retourner pending pour réessayer
                        return response()->json([
                            'status' => 'pending',
                            'additional_payment_id' => $additionalPayment->id,
                            'order_id' => $order->id,
                            'message' => 'Paiement en attente de confirmation.',
                        ]);
                    }
                }

                // Si on ne doit pas traiter automatiquement, retourner pending
                return response()->json([
                    'status' => 'pending',
                    'additional_payment_id' => $additionalPayment->id,
                    'order_id' => $order->id,
                    'message' => 'Paiement en attente de confirmation.',
                ]);
            }

            // Si le paiement a échoué
            return response()->json([
                'status' => 'failed',
                'additional_payment_id' => $additionalPayment->id,
                'order_id' => $order->id,
                'message' => 'Paiement échoué.',
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la vérification du statut du paiement supplémentaire', [
                'additional_payment_id' => $additionalPaymentId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Erreur lors de la vérification du statut.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ✅ NOUVEAU: Vérifie le statut d'un paiement supplémentaire (route publique, sans authentification)
     * Utilisée après une redirection externe depuis Chap Chap Pay où la session peut être perdue
     *
     * @param Request $request
     * @param int $additionalPaymentId
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkAdditionalPaymentStatusPublic(Request $request, $additionalPaymentId)
    {
        try {
            $additionalPayment = \App\Models\AdditionalCardPayment::find($additionalPaymentId);

            // ✅ FORCER LE RECHARGEMENT depuis la base de données (éviter le cache)
            if ($additionalPayment) {
                $additionalPayment->refresh();
            }

            if (!$additionalPayment) {
                return response()->json([
                    'message' => 'Paiement supplémentaire non trouvé.',
                ], 404);
            }

            $order = $additionalPayment->order;

            // Si le paiement est payé, retourner le statut
            if ($additionalPayment->payment_status === 'paid') {
                Log::info('Chap Chap Pay: Paiement supplémentaire confirmé (route publique)', [
                    'additional_payment_id' => $additionalPayment->id,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]);

                return response()->json([
                    'status' => 'paid',
                    'additional_payment_id' => $additionalPayment->id,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'quantity' => $additionalPayment->quantity,
                    'total_price' => $additionalPayment->total_price,
                    'message' => 'Paiement confirmé et cartes ajoutées avec succès.',
                ]);
            }

            // ✅ NOUVEAU: Si le paiement est en attente, valider automatiquement en développement local
            if ($additionalPayment->payment_status === 'pending') {
                $minutesSinceCreation = abs($additionalPayment->created_at->diffInMinutes(now()));

                Log::info('Chap Chap Pay: Vérification du statut du paiement supplémentaire (route publique)', [
                    'additional_payment_id' => $additionalPayment->id,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'current_status' => $additionalPayment->payment_status,
                    'minutes_since_creation' => $minutesSinceCreation,
                    'environment' => app()->environment(),
                ]);

                // ✅ VALIDATION AUTOMATIQUE: UNIQUEMENT en local (route publique)
                // En production, seul le webhook de Chap Chap Pay doit valider les paiements
                $shouldProcess = false;

                if (app()->environment('local')) {
                    $shouldProcess = true;
                    Log::info('Chap Chap Pay: Traitement automatique du paiement supplémentaire (environnement local - route publique)', [
                        'additional_payment_id' => $additionalPayment->id,
                        'order_id' => $order->id,
                    ]);
                } else {
                    // ✅ EN PRODUCTION: Ne JAMAIS valider automatiquement (route publique)
                    Log::info('Chap Chap Pay: Paiement supplémentaire en attente (production - route publique - attente du webhook)', [
                        'additional_payment_id' => $additionalPayment->id,
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'minutes_since_creation' => $minutesSinceCreation,
                    ]);
                }

                if ($shouldProcess) {
                    // Traiter le paiement (même logique que dans checkAdditionalPaymentStatus)
                    $user = $additionalPayment->user;
                    $quantity = $additionalPayment->quantity;
                    $distribution = $additionalPayment->distribution;
                    $totalPrice = $additionalPayment->total_price;

                    // Appliquer les cartes à la commande
                    if (($order->order_type === 'business' || $order->order_type === 'entreprise') && $distribution) {
                        $adminQuantity = isset($distribution['admin']) ? (int) $distribution['admin'] : 0;
                        $employeesDistribution = $distribution['employees'] ?? [];

                        if ($adminQuantity > 0) {
                            $order->increment('card_quantity', $adminQuantity);
                        }

                        foreach ($employeesDistribution as $employeeId => $employeeQuantity) {
                            $employeeQuantityInt = (int) $employeeQuantity;
                            if ($employeeQuantityInt > 0) {
                                $order->increment('card_quantity', $employeeQuantityInt);
                            }
                        }
                    } else {
                        $order->increment('card_quantity', $quantity);
                    }

                    $order->increment('additional_cards_count', $quantity);
                    $order->increment('additional_cards_total_price', $totalPrice);
                    $order->increment('total_price', $totalPrice);

                    $additionalPayment->update([
                        'payment_status' => 'paid',
                        'paid_at' => now(),
                    ]);

                    // ✅ NOUVEAU: Notification super admin : paiement supplémentaire validé
                    try {
                        $profileUrl = url('/') . '/' . $user->username;
                        if ($order->is_configured) {
                            $profileUrl .= '?order=' . $order->id;
                        }

                        $message = "{$quantity} carte(s) supplémentaire(s) ajoutée(s) à la commande #{$order->order_number} par {$user->name}";

                        // Pour les commandes entreprise, ajouter les détails de la distribution
                        $distributionDetails = [];
                        if (($order->order_type === 'business' || $order->order_type === 'entreprise') && $distribution) {
                            $adminQuantity = isset($distribution['admin']) ? (int) $distribution['admin'] : 0;
                            $employeesDistribution = $distribution['employees'] ?? [];

                            if ($adminQuantity > 0) {
                                $adminOrderEmployee = $order->orderEmployees->where('employee_id', $user->id)->first();
                                $adminName = $adminOrderEmployee ? $adminOrderEmployee->employee_name : $user->name;
                                $distributionDetails[] = [
                                    'name' => $adminName,
                                    'role' => 'business_admin',
                                    'quantity' => $adminQuantity,
                                ];
                            }

                            foreach ($employeesDistribution as $employeeId => $employeeQuantity) {
                                $employeeQuantityInt = (int) $employeeQuantity;
                                if ($employeeQuantityInt > 0) {
                                    $employeeOrderEmployee = $order->orderEmployees->where('employee_id', $employeeId)->first();
                                    if ($employeeOrderEmployee) {
                                        $distributionDetails[] = [
                                            'name' => $employeeOrderEmployee->employee_name,
                                            'role' => 'employee',
                                            'employee_id' => $employeeId,
                                            'quantity' => $employeeQuantityInt,
                                        ];
                                    }
                                }
                            }

                            if (!empty($distributionDetails)) {
                                $detailsText = [];
                                foreach ($distributionDetails as $detail) {
                                    $detailsText[] = "{$detail['name']}: {$detail['quantity']} carte(s)";
                                }
                                $message .= " (" . implode(', ', $detailsText) . ")";
                            }
                        }

                        \App\Models\AdminNotification::create([
                            'type' => 'additional_cards_added',
                            'user_id' => $user->id,
                            'order_id' => $order->id,
                            'message' => $message,
                            'url' => $profileUrl,
                            'meta' => [
                                'order_number' => $order->order_number,
                                'quantity' => $quantity,
                                'total_price' => $totalPrice,
                                'unit_price' => $additionalPayment->unit_price,
                                'additional_payment_id' => $additionalPayment->id,
                                'order_type' => $order->order_type,
                                'distribution_details' => $distributionDetails,
                            ],
                        ]);
                    } catch (\Throwable $t) {
                        Log::error('Erreur lors de la création de la notification admin pour cartes supplémentaires (route publique): ' . $t->getMessage());
                    }

                    // ✅ NOUVEAU: Envoyer l'email de confirmation au client pour les cartes supplémentaires
                    try {
                        Log::info('Tentative d\'envoi de l\'email client (cartes supplémentaires - route publique)', [
                            'order_id' => $order->id,
                            'user_email' => $user->email,
                            'additional_payment_id' => $additionalPayment->id,
                            'mailer' => config('mail.default', 'log'),
                            'mail_host' => config('mail.mailers.smtp.host'),
                            'mail_from' => config('mail.from.address'),
                        ]);

                        $clientMailable = new \App\Mail\AdditionalCardsAdded($order, $user, $additionalPayment);
                        \Mail::to($user->email)->send($clientMailable);

                        Log::info('Email client envoyé avec succès pour cartes supplémentaires (route publique)', [
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'additional_payment_id' => $additionalPayment->id,
                            'user_id' => $user->id,
                            'user_email' => $user->email,
                            'mailer' => config('mail.default', 'log'),
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('Erreur lors de l\'envoi de l\'email client pour cartes supplémentaires (route publique)', [
                            'order_id' => $order->id,
                            'user_email' => $user->email,
                            'error' => $e->getMessage(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'trace' => $e->getTraceAsString(),
                            'additional_payment_id' => $additionalPayment->id,
                            'mailer' => config('mail.default', 'log'),
                        ]);
                    }

                    // ✅ NOUVEAU: Envoyer l'email de notification au super admin
                    try {
                        Log::info('Tentative d\'envoi de l\'email admin (cartes supplémentaires - route publique)', [
                            'order_id' => $order->id,
                            'admin_email' => 'charleshaba454@gmail.com',
                            'additional_payment_id' => $additionalPayment->id,
                            'mailer' => config('mail.default', 'log'),
                            'mail_host' => config('mail.mailers.smtp.host'),
                            'mail_from' => config('mail.from.address'),
                        ]);

                        $mailable = new \App\Mail\AdminOrderPaymentNotification($order, $user, true, $additionalPayment);
                        \Mail::to('charleshaba454@gmail.com')->send($mailable);

                        Log::info('Email de notification admin envoyé avec succès pour cartes supplémentaires (route publique)', [
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'additional_payment_id' => $additionalPayment->id,
                            'user_id' => $user->id,
                            'admin_email' => 'charleshaba454@gmail.com',
                            'mailer' => config('mail.default', 'log'),
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Erreur lors de l\'envoi de l\'email de notification admin pour cartes supplémentaires (route publique)', [
                            'error' => $e->getMessage(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'trace' => $e->getTraceAsString(),
                            'order_id' => $order->id,
                            'additional_payment_id' => $additionalPayment->id,
                            'admin_email' => 'charleshaba454@gmail.com',
                            'mailer' => config('mail.default', 'log'),
                        ]);
                    }

                    Log::info('Chap Chap Pay: Paiement supplémentaire traité automatiquement (route publique)', [
                        'additional_payment_id' => $additionalPayment->id,
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'quantity' => $quantity,
                    ]);

                    return response()->json([
                        'status' => 'paid',
                        'additional_payment_id' => $additionalPayment->id,
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'quantity' => $quantity,
                        'total_price' => $totalPrice,
                        'message' => 'Paiement confirmé et cartes ajoutées avec succès.',
                    ]);
                }
            }

            return response()->json([
                'status' => 'pending',
                'additional_payment_id' => $additionalPayment->id,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'message' => 'Paiement en attente.',
            ]);
        } catch (\Exception $e) {
            Log::error('Chap Chap Pay: Erreur lors de la vérification du statut du paiement supplémentaire (route publique)', [
                'additional_payment_id' => $additionalPaymentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Erreur lors de la vérification du statut du paiement.',
            ], 500);
        }
    }

    /**
     * Vérifier manuellement le statut du paiement après le retour de l'utilisateur
     * Cette méthode est appelée par le frontend après un retour de paiement réussi
     * En développement local, le webhook ne peut pas être appelé (localhost inaccessible),
     * donc on valide directement la commande si l'utilisateur revient de la page de paiement
     */
    public function checkPaymentStatus(Request $request, Order $order)
    {
        try {
            // Vérifier que l'utilisateur est autorisé à vérifier le statut de cette commande
            if ($order->user_id !== auth()->id()) {
                return response()->json([
                    'message' => 'Non autorisé à vérifier cette commande.',
                ], 403);
            }

            // Si la commande est déjà validée, retourner le statut
            if ($order->status === 'validated') {
                Log::info('Chap Chap Pay: Commande déjà validée', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]);

                return response()->json([
                    'status' => 'validated',
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'message' => 'Commande déjà validée.',
                ]);
            }

            // ✅ MODIFICATION: En environnement local/test, on valide automatiquement la commande
            // si l'utilisateur revient de la page de paiement (le simple fait d'appeler cette méthode
            // indique que l'utilisateur est revenu avec payment=success)
            // Le webhook ne peut pas être appelé en localhost, donc on valide manuellement

            // ✅ LOGIQUE SIMPLIFIÉE: Si l'utilisateur revient avec payment=success, c'est que le paiement a été complété
            // Le simple fait d'appeler cette méthode avec payment=success dans l'URL indique que Chap Chap Pay
            // a redirigé l'utilisateur après un paiement réussi
            // En environnement local/test, le webhook ne peut pas être appelé, donc on valide automatiquement
            // En production, le webhook devrait être appelé, mais cette méthode sert de fallback

            // Calculer le temps depuis la dernière mise à jour pour logging
            $minutesSinceUpdate = abs($order->updated_at->diffInMinutes(now()));
            $minutesSinceCreation = abs($order->created_at->diffInMinutes(now()));

            Log::info('Chap Chap Pay: Vérification du statut de paiement', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'current_status' => $order->status,
                'minutes_since_update' => $minutesSinceUpdate,
                'minutes_since_creation' => $minutesSinceCreation,
                'environment' => app()->environment(),
            ]);

            // ✅ VALIDATION AUTOMATIQUE: Si l'utilisateur revient avec payment=success, valider la commande
            // On valide si :
            // 1. Le statut n'est pas déjà validé
            // 2. On est en environnement local (où le webhook ne peut pas être appelé)
            //    OU la commande a été créée/mise à jour récemment (moins de 1 heure) en production
            $shouldValidate = false;

            if ($order->status !== 'validated') {
                if (app()->environment('local')) {
                    // En local, valider automatiquement car le webhook ne peut pas être appelé
                    $shouldValidate = true;
                    Log::info('Chap Chap Pay: Validation automatique (environnement local - webhook non disponible)', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                    ]);
                } elseif ($minutesSinceUpdate < 60 || $minutesSinceCreation < 60) {
                    // En production, valider si la commande est récente (moins d'1 heure)
                    // Cela indique que le paiement vient probablement d'être effectué
                    $shouldValidate = true;
                    Log::info('Chap Chap Pay: Validation automatique (production - commande récente)', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'minutes_since_update' => $minutesSinceUpdate,
                        'minutes_since_creation' => $minutesSinceCreation,
                    ]);
                } else {
                    // Commande trop ancienne, ne pas valider automatiquement
                    Log::warning('Chap Chap Pay: Commande trop ancienne pour validation automatique', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'minutes_since_update' => $minutesSinceUpdate,
                        'minutes_since_creation' => $minutesSinceCreation,
                    ]);
                }
            }

            if ($shouldValidate) {
                Log::info('Chap Chap Pay: Validation manuelle de la commande (webhook non appelé - environnement local/test)', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'minutes_since_update' => $minutesSinceUpdate,
                    'minutes_since_creation' => $minutesSinceCreation,
                    'current_status' => $order->status,
                    'environment' => app()->environment(),
                ]);

                // Valider la commande manuellement (simuler ce que ferait le webhook)
                $order->update([
                    'status' => 'validated',
                    'subscription_start_date' => now()->format('Y-m-d'),
                ]);

                // Rafraîchir la commande pour avoir les données à jour
                $order->refresh();

                $user = $order->user;

                // Notification super admin : commande validée
                try {
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
                            'validated_manually' => true, // Indique que c'est une validation manuelle
                        ],
                    ]);
                } catch (\Throwable $t) {
                    Log::error('Erreur lors de la création de la notification admin: ' . $t->getMessage());
                }

                // Envoyer l'email de confirmation
                try {
                    \Mail::to($user->email)->send(new \App\Mail\OrderValidated($order, $user));
                    Log::info('Email de validation envoyé avec succès (validation manuelle)', [
                        'user_id' => $user->id,
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'email' => $user->email,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Erreur lors de l\'envoi de l\'email de validation (validation manuelle)', [
                        'error' => $e->getMessage(),
                        'order_id' => $order->id,
                    ]);
                }

                // ✅ NOUVEAU: Envoyer l'email de notification au super admin
                try {
                    $mailer = config('mail.default', 'log');
                    Log::info('Tentative d\'envoi email notification admin (validation manuelle) - Configuration', [
                        'mailer' => $mailer,
                        'mail_from' => config('mail.from.address'),
                        'mail_host' => config('mail.mailers.smtp.host'),
                        'order_id' => $order->id,
                        'order_status' => $order->status,
                        'admin_email' => 'charleshaba454@gmail.com',
                    ]);

                    $mailable = new \App\Mail\AdminOrderPaymentNotification($order, $user, false);
                    \Mail::to('charleshaba454@gmail.com')->send($mailable);

                    Log::info('Email de notification admin envoyé avec succès (validation manuelle)', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'user_id' => $user->id,
                        'admin_email' => 'charleshaba454@gmail.com',
                        'mailer_used' => $mailer,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Erreur lors de l\'envoi de l\'email de notification admin (validation manuelle)', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'user_id' => $user->id,
                        'admin_email' => 'charleshaba454@gmail.com',
                        'mailer' => config('mail.default', 'log'),
                    ]);
                }

                Log::info('Chap Chap Pay: Commande validée avec succès (validation manuelle)', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'environment' => app()->environment(),
                ]);

                return response()->json([
                    'status' => 'validated',
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'message' => 'Commande validée avec succès.',
                    'validated_manually' => true, // Indique que c'est une validation manuelle
                ]);
            }

            // Commande en attente de validation
            Log::info('Chap Chap Pay: Commande en attente de validation', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'minutes_since_update' => $minutesSinceUpdate,
                'minutes_since_creation' => $minutesSinceCreation,
                'current_status' => $order->status,
            ]);

            return response()->json([
                'status' => 'pending',
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'message' => 'Vérification du paiement en cours. Veuillez patienter...',
                'minutes_since_update' => $minutesSinceUpdate,
                'minutes_since_creation' => $minutesSinceCreation,
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la vérification du statut de paiement', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'order_id' => $order->id ?? null,
            ]);

            return response()->json([
                'message' => 'Erreur lors de la vérification du statut.',
                'error' => $e->getMessage(),
            ], 500);
        }
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

            // ✅ MODIFICATION: Ne plus ajouter directement les cartes
            // La distribution sera stockée dans le paiement et appliquée seulement après paiement réussi
            // Vérifier que le business admin est inclus dans la commande (si des cartes lui sont attribuées)
            if ($adminQuantityClean > 0) {
                $adminOrderEmployee = $order->orderEmployees->where('employee_id', $user->id)->first();
                if (!$adminOrderEmployee) {
                    return response()->json([
                        'message' => 'Vous n\'êtes pas inclus dans cette commande.',
                    ], 400);
                }
            }

            // Vérifier que tous les employés dans la distribution existent dans la commande
            foreach ($cleanedEmployeesDistribution as $employeeId => $employeeQuantity) {
                $employeeQuantityInt = (int) $employeeQuantity;
                if ($employeeQuantityInt > 0) {
                    $employeeOrderEmployee = $order->orderEmployees->where('employee_id', $employeeId)->first();
                    if (!$employeeOrderEmployee) {
                        return response()->json([
                            'message' => "L'employé avec l'ID {$employeeId} n'est pas associé à cette commande.",
                        ], 400);
                    }
                }
            }

            \Log::info('Ajout de cartes - Distribution validée (sera appliquée après paiement)', [
                'order_id' => $order->id,
                'quantity' => $quantity,
                'distribution' => $cleanedEmployeesDistribution,
                'admin_quantity' => $adminQuantityClean,
            ]);
        } else {
            // Pour les commandes particulières, comportement original
            if ($order->order_type !== 'personal' && $order->order_type !== 'individual') {
                return response()->json(['message' => 'Pour les commandes business, vous devez spécifier la distribution des cartes.'], 400);
            }

            \Log::info('Ajout de cartes - Commande particulière validée (sera appliquée après paiement)', [
                'order_id' => $order->id,
                'quantity' => $quantity,
            ]);
        }

        // ✅ NOUVEAU: Créer un paiement en attente au lieu d'ajouter directement le montant
        // Créer l'enregistrement de paiement supplémentaire
        $additionalPayment = \App\Models\AdditionalCardPayment::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'quantity' => $quantity,
            'distribution' => $distribution,
            'unit_price' => $additionalCardPrice,
            'total_price' => $additionalCardsTotalPrice,
            'payment_status' => 'pending',
            'payment_provider' => 'chapchap',
        ]);

        // Générer le lien de paiement via Chap Chap Pay
        try {
            $chapChapPayService = new \App\Services\ChapChapPayService();

            // Le montant est déjà en centimes (ex: 80000 = 800.00 GNF)
            $amount = (int) $additionalCardsTotalPrice;

            // Construire les URLs
            // ✅ CORRECTION: Utiliser config('app.frontend_url') qui gère déjà la logique de nettoyage
            $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173'));

            // ✅ CORRECTION: Nettoyer l'URL pour ne prendre que la première URL valide
            // Si plusieurs URLs sont séparées par une virgule, prendre seulement la première
            if (strpos($frontendUrl, ',') !== false) {
                $urls = explode(',', $frontendUrl);
                $frontendUrl = trim($urls[0]); // Prendre la première URL
            }
            $frontendUrl = trim($frontendUrl);

            // ✅ CRITIQUE: En production, s'assurer que l'URL pointe vers le frontend, pas le backend
            if (app()->environment('production') && str_contains($frontendUrl, 'digicard-api.arccenciel.com')) {
                // Remplacer digicard-api par digicard pour pointer vers le frontend
                $frontendUrl = str_replace('digicard-api.arccenciel.com', 'digicard.arccenciel.com', $frontendUrl);
                Log::warning("OrderController: Frontend URL corrigée pour pointer vers le frontend (additional cards)", [
                    'original_url' => config('app.frontend_url'),
                    'corrected_url' => $frontendUrl,
                ]);
            }

            // ✅ NOUVEAU: Générer un token de session pour la récupération après redirection externe
            // Utiliser le token de la commande principale si disponible, sinon en créer un nouveau
            if (!$order->payment_session_token) {
                $sessionToken = Str::random(60);
                $order->update(['payment_session_token' => $sessionToken]);

                \Log::info('OrderController: Token de session généré pour la commande (paiement supplémentaire)', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'additional_payment_id' => $additionalPayment->id,
                ]);
            } else {
                $sessionToken = $order->payment_session_token;
                \Log::info('OrderController: Token de session existant réutilisé (paiement supplémentaire)', [
                    'order_id' => $order->id,
                    'additional_payment_id' => $additionalPayment->id,
                ]);
            }

            // ✅ MODIFICATION: return_url pointe maintenant vers une page simple /payment/close
            // L'onglet principal fait du polling pour détecter le paiement
            // On passe l'ID de commande et l'ID de paiement supplémentaire dans l'URL pour permettre la simulation en développement
            $frontendUrl = env('FRONTEND_URL', 'https://digicard.arccenciel.com');
            $returnUrl = rtrim($frontendUrl, '/') . '/payment/close?order_id=' . $order->id . '&additional_payment_id=' . $additionalPayment->id;

            $notifyUrl = url('/') . '/api/payment/webhook-additional-cards';

            $description = 'Paiement cartes supplementaires - Commande ' . $order->order_number;

            $paymentData = [
                'amount' => $amount,
                'description' => $description,
                'order_id' => $order->order_number . '-ADD-' . $additionalPayment->id,
                'return_url' => $returnUrl,
                'notify_url' => $notifyUrl,
                'options' => [
                    'auto-redirect' => true,
                ],
            ];

            \Log::info('Chap Chap Pay: Création du lien de paiement pour cartes supplémentaires', [
                'additional_payment_id' => $additionalPayment->id,
                'order_id' => $order->id,
                'amount' => $amount,
                'quantity' => $quantity,
            ]);

            $paymentResponse = $chapChapPayService->createPaymentLink($paymentData);

            if ($paymentResponse && isset($paymentResponse['payment_url'])) {
                $operationId = $paymentResponse['operation_id'] ?? null;

                // Mettre à jour le paiement avec l'URL et l'operation_id
                $additionalPayment->update([
                    'payment_url' => $paymentResponse['payment_url'],
                    'payment_operation_id' => $operationId,
                ]);

                \Log::info('Chap Chap Pay: Lien de paiement généré pour cartes supplémentaires', [
                    'additional_payment_id' => $additionalPayment->id,
                    'payment_url' => $paymentResponse['payment_url'],
                    'operation_id' => $operationId,
                ]);

                // Retourner les détails du paiement au lieu d'ajouter directement
                return response()->json([
                    'message' => "Commande supplémentaire créée. Veuillez procéder au paiement.",
                    'requires_payment' => true,
                    'additional_payment' => [
                        'id' => $additionalPayment->id,
                        'quantity' => $additionalPayment->quantity,
                        'unit_price' => $additionalPayment->unit_price,
                        'total_price' => $additionalPayment->total_price,
                        'payment_url' => $additionalPayment->payment_url,
                        'payment_status' => $additionalPayment->payment_status,
                    ],
                    'order' => [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'total_price' => $order->total_price, // Prix actuel sans les cartes supplémentaires
                    ],
                ]);
            } else {
                // Erreur lors de la création du lien de paiement
                $additionalPayment->update(['payment_status' => 'failed']);
                \Log::error('Chap Chap Pay: Erreur lors de la création du lien de paiement pour cartes supplémentaires', [
                    'additional_payment_id' => $additionalPayment->id,
                    'response' => $paymentResponse,
                ]);

                return response()->json([
                    'message' => 'Erreur lors de la création du lien de paiement. Veuillez réessayer.',
                ], 500);
            }
        } catch (\Exception $e) {
            $additionalPayment->update(['payment_status' => 'failed', 'notes' => 'Erreur: ' . $e->getMessage()]);
            \Log::error('Erreur lors de la création du paiement pour cartes supplémentaires', [
                'additional_payment_id' => $additionalPayment->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Erreur lors de la création du paiement. Veuillez réessayer.',
            ], 500);
        }
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
                        if ($employee->avatar_url) {
                            // ✅ CORRECTION : Gérer les deux formats (/storage/ et /api/storage/)
                            $oldPath = preg_replace('#^/api/storage/#', '', $employee->avatar_url);
                            $oldPath = preg_replace('#^/storage/#', '', $oldPath);
                            $oldPath = preg_replace('#^https?://[^/]+/(api/)?storage/#', '', $oldPath);
                            if (Storage::disk('public')->exists($oldPath)) {
                                Storage::disk('public')->delete($oldPath);
                            }
                        }

                        // Supprimer aussi l'avatar de la commande depuis order_employees
                if ($orderEmployee->employee_avatar_url) {
                    // ✅ CORRECTION : Gérer les deux formats (/storage/ et /api/storage/)
                    $oldPath = preg_replace('#^/api/storage/#', '', $orderEmployee->employee_avatar_url);
                    $oldPath = preg_replace('#^/storage/#', '', $oldPath);
                    $oldPath = preg_replace('#^https?://[^/]+/(api/)?storage/#', '', $oldPath);
                    if (Storage::disk('public')->exists($oldPath)) {
                        Storage::disk('public')->delete($oldPath);
                    }
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

    /**
     * ✅ NOUVEAU: Callback pour restaurer la session après redirection depuis Chap Chap Pay
     * Route WEB (pas API) pour bénéficier nativement des cookies de session
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function handlePaymentCallback(Request $request)
    {
        try {
            // Récupérer les paramètres depuis l'URL
            $sessionToken = $request->query('session_token');
            $orderId = $request->query('order_id');
            $additionalPaymentId = $request->query('additional_payment_id');

            Log::info('OrderController: Callback de paiement reçu', [
                'session_token_present' => !empty($sessionToken),
                'order_id' => $orderId,
                'additional_payment_id' => $additionalPaymentId,
                'all_query_params' => $request->query(),
            ]);

            // Vérifier que le token est présent
            if (!$sessionToken) {
                Log::warning('OrderController: Callback de paiement sans token de session');

                // Rediriger vers le frontend avec une erreur
                $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173'));
                if (strpos($frontendUrl, ',') !== false) {
                    $urls = explode(',', $frontendUrl);
                    $frontendUrl = trim($urls[0]);
                }
                $frontendUrl = trim($frontendUrl);

                return redirect(rtrim($frontendUrl, '/') . '/mes-commandes?payment=error&message=session_token_missing');
            }

            // Trouver la commande via le token
            $order = Order::where('payment_session_token', $sessionToken)->first();

            if (!$order) {
                Log::warning('OrderController: Commande non trouvée avec le token de session', [
                    'token_length' => strlen($sessionToken),
                    'token_prefix' => substr($sessionToken, 0, 10) . '...',
                ]);

                // Rediriger vers le frontend avec une erreur
                $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173'));
                if (strpos($frontendUrl, ',') !== false) {
                    $urls = explode(',', $frontendUrl);
                    $frontendUrl = trim($urls[0]);
                }
                $frontendUrl = trim($frontendUrl);

                return redirect(rtrim($frontendUrl, '/') . '/mes-commandes?payment=error&message=invalid_token');
            }

            // Vérifier que la commande a un utilisateur associé
            if (!$order->user) {
                Log::error('OrderController: Commande trouvée mais sans utilisateur associé', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]);

                // Rediriger vers le frontend avec une erreur
                $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173'));
                if (strpos($frontendUrl, ',') !== false) {
                    $urls = explode(',', $frontendUrl);
                    $frontendUrl = trim($urls[0]);
                }
                $frontendUrl = trim($frontendUrl);

                return redirect(rtrim($frontendUrl, '/') . '/mes-commandes?payment=error&message=user_not_found');
            }

            // ✅ CORRECTION: Définir $user après la validation pour pouvoir l'utiliser dans les logs
            $user = $order->user;

            // ✅ NOUVEAU: Ne PAS connecter l'utilisateur ici (le navigateur refuse de stocker le cookie lors de la redirection)
            // Le frontend appellera /api/auth/exchange-token pour échanger le token contre une session
            // On garde le token dans la commande pour l'échange ultérieur

            Log::info('OrderController: Callback de paiement reçu, redirection vers frontend avec token', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'additional_payment_id' => $additionalPaymentId,
                'token_present' => !empty($sessionToken),
            ]);

            // Construire l'URL de redirection vers le frontend
            $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173'));

            // Nettoyer l'URL pour ne prendre que la première URL valide
            if (strpos($frontendUrl, ',') !== false) {
                $urls = explode(',', $frontendUrl);
                $frontendUrl = trim($urls[0]);
            }
            $frontendUrl = trim($frontendUrl);

            // ✅ CRITIQUE: En production, s'assurer que l'URL pointe vers le frontend, pas le backend
            if (app()->environment('production') && str_contains($frontendUrl, 'digicard-api.arccenciel.com')) {
                $frontendUrl = str_replace('digicard-api.arccenciel.com', 'digicard.arccenciel.com', $frontendUrl);
            }

            // Construire les paramètres de requête pour le frontend
            // ✅ IMPORTANT: Inclure le session_token pour que le frontend puisse l'échanger
            // ✅ NOUVEAU: Rediriger vers /payment/process (page publique) au lieu de /mes-commandes
            $queryParams = [
                'session_token' => $sessionToken, // ✅ Le token sera échangé par le frontend
            ];

            if ($orderId) {
                $queryParams['order_id'] = $orderId;
            }

            if ($additionalPaymentId) {
                $queryParams['additional_payment_id'] = $additionalPaymentId;
            }

            // ✅ NOUVEAU: Rediriger vers /payment/process (page publique de traitement)
            // Cette page échangera le token contre une session avant de rediriger vers /mes-commandes
            $redirectUrl = rtrim($frontendUrl, '/') . '/payment/process?' . http_build_query($queryParams);

            Log::info('OrderController: Redirection vers le frontend', [
                'redirect_url' => $redirectUrl,
                'user_id' => $user->id,
            ]);

            return redirect($redirectUrl);
        } catch (\Exception $e) {
            Log::error('OrderController: Erreur lors du callback de paiement', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // En cas d'erreur, rediriger vers le frontend avec une erreur
            $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173'));
            if (strpos($frontendUrl, ',') !== false) {
                $urls = explode(',', $frontendUrl);
                $frontendUrl = trim($urls[0]);
            }
            $frontendUrl = trim($frontendUrl);

            return redirect(rtrim($frontendUrl, '/') . '/mes-commandes?payment=error&message=callback_error');
        }
    }

    /**
     * ✅ NOUVEAU: Endpoint léger pour obtenir le statut de paiement d'une commande
     * Utilisé par le polling frontend pour vérifier rapidement le statut
     */
    public function getOrderStatus(Request $request, Order $order)
    {
        // Vérifier que l'utilisateur a accès à cette commande
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Non authentifié'], 401);
        }

        // Vérifier que l'utilisateur est propriétaire de la commande ou est un employé inclus
        $hasAccess = false;

        if ($order->user_id === $user->id) {
            $hasAccess = true;
        } else {
            // Vérifier si l'utilisateur est un employé inclus dans cette commande
            $isEmployee = \App\Models\OrderEmployee::where('order_id', $order->id)
                ->where('employee_id', $user->id)
                ->exists();

            if ($isEmployee) {
                $hasAccess = true;
            }
        }

        if (!$hasAccess) {
            return response()->json(['error' => 'Accès non autorisé'], 403);
        }

        // ✅ IMPORTANT: Recharger la commande depuis la base de données pour avoir le statut à jour
        $order->refresh();

        // ✅ VALIDATION AUTOMATIQUE: Si la commande est en attente en localhost, valider automatiquement
        // (comme pour les cartes supplémentaires, pour avoir la même vitesse de validation)
        if ($order->status === 'configured' && app()->environment('local')) {
            // Calculer le temps depuis la création du paiement
            $minutesSinceCreation = abs($order->created_at->diffInMinutes(now()));

            Log::info('Chap Chap Pay: Validation automatique de la commande (environnement local)', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'minutes_since_creation' => $minutesSinceCreation,
            ]);

            // Valider automatiquement la commande (simuler le webhook)
            try {
                $order->update([
                    'status' => 'validated',
                    'subscription_start_date' => now()->format('Y-m-d'),
                ]);

                $order->refresh();

                $user = $order->user;

                // Notification super admin
                try {
                    $profileUrl = url('/') . '/' . $user->username;
                    if ($order->is_configured) {
                        $profileUrl .= '?order=' . $order->id;
                    }
                    \App\Models\AdminNotification::create([
                        'type' => 'order_validated',
                        'user_id' => $user->id,
                        'order_id' => $order->id,
                        'message' => 'Commande validée par ' . $user->name . ' (#' . $order->order_number . ') [VALIDATION AUTOMATIQUE]',
                        'url' => $profileUrl,
                        'meta' => [
                            'order_number' => $order->order_number,
                            'total_price' => $order->total_price,
                            'simulated' => true,
                            'simulated_via' => 'automatic_validation',
                        ],
                    ]);
                } catch (\Throwable $t) {
                    Log::error('Erreur lors de la création de la notification admin (validation automatique): ' . $t->getMessage());
                }

                // Email Client
                try {
                    Log::info('Tentative d\'envoi de l\'email client (validation automatique)', [
                        'order_id' => $order->id,
                        'user_email' => $user->email,
                        'mailer' => config('mail.default', 'log'),
                    ]);

                    \Mail::to($user->email)->send(new \App\Mail\OrderValidated($order, $user));

                    Log::info('Email client envoyé avec succès (validation automatique)', [
                        'order_id' => $order->id,
                        'user_email' => $user->email,
                        'mailer' => config('mail.default', 'log'),
                    ]);
                } catch (\Throwable $emailError) {
                    Log::error('Erreur lors de l\'envoi de l\'email client (validation automatique)', [
                        'order_id' => $order->id,
                        'user_email' => $user->email,
                        'error' => $emailError->getMessage(),
                        'mailer' => config('mail.default', 'log'),
                    ]);
                }

                // Email Admin
                try {
                    Log::info('Tentative d\'envoi de l\'email admin (validation automatique)', [
                        'order_id' => $order->id,
                        'admin_email' => 'charleshaba454@gmail.com',
                        'mailer' => config('mail.default', 'log'),
                    ]);

                    $mailable = new \App\Mail\AdminOrderPaymentNotification($order, $user, false);
                    \Mail::to('charleshaba454@gmail.com')->send($mailable);

                    Log::info('Email admin envoyé avec succès (validation automatique)', [
                        'order_id' => $order->id,
                        'admin_email' => 'charleshaba454@gmail.com',
                        'mailer' => config('mail.default', 'log'),
                    ]);
                } catch (\Throwable $emailError) {
                    Log::error('Erreur lors de l\'envoi de l\'email admin (validation automatique)', [
                        'order_id' => $order->id,
                        'admin_email' => 'charleshaba454@gmail.com',
                        'error' => $emailError->getMessage(),
                        'mailer' => config('mail.default', 'log'),
                    ]);
                }

                Log::info('Chap Chap Pay: Commande validée automatiquement', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]);
            } catch (\Exception $e) {
                Log::error('Erreur lors de la validation automatique de la commande', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        // ✅ Retourner uniquement les informations essentielles pour le polling
        // Format standardisé pour faciliter la détection côté frontend
        $order->refresh(); // Recharger après la validation automatique si elle a eu lieu

        $isPaid = $order->status === 'validated';

        Log::info('getOrderStatus appelé', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'is_paid' => $isPaid,
        ]);

        return response()->json([
            'order_id' => $order->id,
            'status' => $order->status,
            'payment_status' => $isPaid ? 'paid' : ($order->status === 'pending' ? 'pending' : $order->status),
            'is_paid' => $isPaid,
            // ✅ Ajout: Format alternatif pour compatibilité
            'paid' => $isPaid,
        ]);
    }

    /**
     * ✅ NOUVEAU: Simule le succès d'un paiement pour le développement local
     * Cette méthode simule le webhook de Chap Chap Pay en développement
     */
    public function simulatePaymentSuccess($orderId, Request $request)
    {
        // Vérifier que nous sommes en mode développement
        if (!app()->environment('local', 'development')) {
            return response()->json(['error' => 'Cette route n\'est disponible qu\'en développement'], 403);
        }

        try {
            $order = Order::findOrFail($orderId);
            $additionalPaymentId = $request->input('additional_payment_id');

            Log::info('Simulation de paiement réussi (développement)', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'additional_payment_id' => $additionalPaymentId,
            ]);

            if ($additionalPaymentId) {
                // Simuler le paiement d'une carte supplémentaire
                $additionalPayment = \App\Models\AdditionalCardPayment::find($additionalPaymentId);

                if (!$additionalPayment) {
                    return response()->json(['error' => 'Paiement supplémentaire non trouvé'], 404);
                }

                // Mettre à jour le statut du paiement supplémentaire
                $additionalPayment->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                ]);

                // Mettre à jour le total de la commande
                $order->increment('additional_cards_count', $additionalPayment->quantity);
                $order->increment('additional_cards_total_price', $additionalPayment->total_price);
                $order->increment('total_price', $additionalPayment->total_price);

                Log::info('Paiement supplémentaire simulé avec succès', [
                    'additional_payment_id' => $additionalPaymentId,
                    'order_id' => $order->id,
                ]);

                return response()->json([
                    'message' => 'Paiement supplémentaire simulé avec succès',
                    'order_id' => $order->id,
                    'additional_payment_id' => $additionalPaymentId,
                    'status' => 'paid',
                ]);
            } else {
                // Simuler le paiement de la commande principale
                if ($order->status !== 'validated') {
                    $order->update([
                        'status' => 'validated',
                        'subscription_start_date' => now()->format('Y-m-d'),
                    ]);

                    $user = $order->user;

                    // Notification super admin : commande validée
                    try {
                        $profileUrl = url('/') . '/' . $user->username;
                        if ($order->is_configured) {
                            $profileUrl .= '?order=' . $order->id;
                        }
                        \App\Models\AdminNotification::create([
                            'type' => 'order_validated',
                            'user_id' => $user->id,
                            'order_id' => $order->id,
                            'message' => 'Commande validée par ' . $user->name . ' (#' . $order->order_number . ') [SIMULATION]',
                            'url' => $profileUrl,
                            'meta' => [
                                'order_number' => $order->order_number,
                                'total_price' => $order->total_price,
                                'simulated' => true,
                            ],
                        ]);
                    } catch (\Throwable $t) {
                        Log::error('Erreur lors de la création de la notification admin (simulation): ' . $t->getMessage());
                    }
                }

                Log::info('Paiement de commande simulé avec succès', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]);

                return response()->json([
                    'message' => 'Paiement simulé avec succès',
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => 'validated',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Erreur lors de la simulation du paiement', [
                'error' => $e->getMessage(),
                'order_id' => $orderId,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Erreur lors de la simulation du paiement',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ✅ NOUVEAU: Simule le webhook de Chap Chap Pay pour le développement local
     * Cette méthode est appelée depuis PaymentCloseView.vue en mode développement
     * pour simuler la validation du paiement quand le webhook ne peut pas atteindre localhost
     */
    public function simulateWebhook($orderId, Request $request)
    {
        // ✅ SÉCURITÉ: Interdire cette route en production
        $environment = app()->environment();
        Log::info('SimulateWebhook appelé', [
            'order_id' => $orderId,
            'environment' => $environment,
            'is_local' => app()->environment('local'),
            'is_development' => app()->environment('development'),
            'allowed' => app()->environment('local', 'development'),
        ]);

        if (!app()->environment('local', 'development')) {
            Log::warning('Tentative d\'accès à simulateWebhook en production', [
                'order_id' => $orderId,
                'environment' => $environment,
            ]);
            abort(403, 'Cette route n\'est disponible qu\'en développement');
        }

        try {
            // Vérifier si c'est un paiement supplémentaire
            $additionalPaymentId = $request->input('additional_payment_id');

            if ($additionalPaymentId) {
                // Simuler le webhook pour un paiement supplémentaire
                $additionalPayment = \App\Models\AdditionalCardPayment::find($additionalPaymentId);

                if (!$additionalPayment) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Paiement supplémentaire non trouvé',
                    ], 404);
                }

                if ($additionalPayment->payment_status === 'paid') {
                    return response()->json([
                        'success' => true,
                        'message' => 'Paiement supplémentaire déjà confirmé',
                        'additional_payment_id' => $additionalPayment->id,
                        'payment_status' => $additionalPayment->payment_status,
                    ]);
                }

                // Simuler le traitement du webhook pour paiement supplémentaire
                $order = $additionalPayment->order;
                $user = $additionalPayment->user;
                $quantity = $additionalPayment->quantity;
                $distribution = $additionalPayment->distribution;
                $totalPrice = $additionalPayment->total_price;

                // Charger les order_employees
                $order->load(['orderEmployees.employee']);

                // Appliquer la distribution des cartes (même logique que dans paymentWebhookAdditionalCards)
                if (($order->order_type === 'business' || $order->order_type === 'entreprise') && $distribution) {
                    $adminQuantity = isset($distribution['admin']) ? (int) $distribution['admin'] : 0;
                    $employeesDistribution = $distribution['employees'] ?? [];

                    if ($adminQuantity > 0) {
                        $adminOrderEmployee = $order->orderEmployees->where('employee_id', $user->id)->first();
                        if ($adminOrderEmployee) {
                            $adminOrderEmployee->increment('card_quantity', $adminQuantity);
                        }
                    }

                    foreach ($employeesDistribution as $employeeId => $employeeQuantity) {
                        $employeeQuantityInt = (int) $employeeQuantity;
                        if ($employeeQuantityInt > 0) {
                            $employeeOrderEmployee = $order->orderEmployees->where('employee_id', $employeeId)->first();
                            if ($employeeOrderEmployee) {
                                $employeeOrderEmployee->increment('card_quantity', $employeeQuantityInt);
                            }
                        }
                    }
                    $order->increment('card_quantity', $quantity);
                } else {
                    $order->increment('card_quantity', $quantity);
                }

                // Mettre à jour les compteurs
                $order->increment('additional_cards_count', $quantity);
                $order->increment('additional_cards_total_price', $totalPrice);
                $order->increment('total_price', $totalPrice);

                // Mettre à jour le statut du paiement
                $additionalPayment->update([
                    'payment_status' => 'paid',
                    'paid_at' => now(),
                ]);

                // ✅ Envoyer l'email de confirmation au client
                try {
                    Log::info('Tentative d\'envoi de l\'email client (cartes supplémentaires - simulation webhook)', [
                        'order_id' => $order->id,
                        'user_email' => $user->email,
                        'additional_payment_id' => $additionalPayment->id,
                        'mailer' => config('mail.default', 'log'),
                        'mail_host' => config('mail.mailers.smtp.host'),
                        'mail_from' => config('mail.from.address'),
                    ]);

                    $clientMailable = new \App\Mail\AdditionalCardsAdded($order, $user, $additionalPayment);
                    \Mail::to($user->email)->send($clientMailable);

                    Log::info('Email de confirmation client envoyé (simulation webhook)', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'additional_payment_id' => $additionalPayment->id,
                        'user_email' => $user->email,
                        'mailer' => config('mail.default', 'log'),
                    ]);
                } catch (\Throwable $e) {
                    Log::error('Erreur lors de l\'envoi de l\'email de confirmation client (simulation webhook)', [
                        'order_id' => $order->id,
                        'user_email' => $user->email,
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString(),
                        'additional_payment_id' => $additionalPayment->id,
                        'mailer' => config('mail.default', 'log'),
                    ]);
                }

                // ✅ Envoyer l'email de notification au super admin
                try {
                    Log::info('Tentative d\'envoi de l\'email admin (cartes supplémentaires - simulation webhook)', [
                        'order_id' => $order->id,
                        'admin_email' => 'charleshaba454@gmail.com',
                        'additional_payment_id' => $additionalPayment->id,
                        'mailer' => config('mail.default', 'log'),
                        'mail_host' => config('mail.mailers.smtp.host'),
                        'mail_from' => config('mail.from.address'),
                    ]);

                    $adminMailable = new \App\Mail\AdminOrderPaymentNotification($order, $user, true, $additionalPayment);
                    \Mail::to('charleshaba454@gmail.com')->send($adminMailable);

                    Log::info('Email admin envoyé avec succès (cartes supplémentaires - simulation webhook)', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'additional_payment_id' => $additionalPayment->id,
                        'admin_email' => 'charleshaba454@gmail.com',
                        'mailer' => config('mail.default', 'log'),
                    ]);
                } catch (\Throwable $e) {
                    Log::error('Erreur lors de l\'envoi de l\'email admin (cartes supplémentaires - simulation webhook)', [
                        'order_id' => $order->id,
                        'admin_email' => 'charleshaba454@gmail.com',
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString(),
                        'additional_payment_id' => $additionalPayment->id,
                        'mailer' => config('mail.default', 'log'),
                    ]);
                }

                Log::info('Webhook simulé avec succès - Paiement supplémentaire confirmé', [
                    'additional_payment_id' => $additionalPayment->id,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Webhook simulé avec succès (paiement supplémentaire)',
                    'additional_payment_id' => $additionalPayment->id,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'payment_status' => $additionalPayment->payment_status,
                ]);
            }

            // Sinon, simuler le webhook pour une commande principale
            $order = Order::findOrFail($orderId);

            Log::info('Simulation de webhook (développement)', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'current_status' => $order->status,
            ]);

            // Si la commande n'est pas déjà validée, la valider
            $wasAlreadyValidated = $order->status === 'validated';

            Log::info('SimulateWebhook - État de la commande avant mise à jour', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'current_status' => $order->status,
                'was_already_validated' => $wasAlreadyValidated,
            ]);

            if (!$wasAlreadyValidated) {
                $order->update([
                    'status' => 'validated',
                    'subscription_start_date' => now()->format('Y-m-d'),
                ]);

                // Recharger la commande pour avoir le statut à jour
                $order->refresh();

                Log::info('SimulateWebhook - Commande mise à jour', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'new_status' => $order->status,
                    'status_from_db' => \App\Models\Order::find($order->id)->status,
                ]);

                $user = $order->user;

                // Notification super admin : commande validée
                try {
                    $profileUrl = url('/') . '/' . $user->username;
                    if ($order->is_configured) {
                        $profileUrl .= '?order=' . $order->id;
                    }
                    \App\Models\AdminNotification::create([
                        'type' => 'order_validated',
                        'user_id' => $user->id,
                        'order_id' => $order->id,
                        'message' => 'Commande validée par ' . $user->name . ' (#' . $order->order_number . ') [SIMULATION WEBHOOK]',
                        'url' => $profileUrl,
                        'meta' => [
                            'order_number' => $order->order_number,
                            'total_price' => $order->total_price,
                            'simulated' => true,
                            'simulated_via' => 'webhook',
                        ],
                    ]);
                    Log::info('Notification admin créée (simulation webhook)', [
                        'order_id' => $order->id,
                    ]);
                } catch (\Throwable $t) {
                    Log::error('Erreur lors de la création de la notification admin (simulation webhook)', [
                        'error' => $t->getMessage(),
                        'trace' => $t->getTraceAsString(),
                        'order_id' => $order->id,
                    ]);
                }

                // Email Client
                try {
                    Log::info('Tentative d\'envoi de l\'email client (simulation webhook)', [
                        'order_id' => $order->id,
                        'user_email' => $user->email,
                        'mailer' => config('mail.default', 'log'),
                        'mail_host' => config('mail.mailers.smtp.host'),
                        'mail_from' => config('mail.from.address'),
                    ]);

                    $mailable = new \App\Mail\OrderValidated($order, $user);
                    \Mail::to($user->email)->send($mailable);

                    Log::info('Email client envoyé avec succès (simulation webhook)', [
                        'order_id' => $order->id,
                        'user_email' => $user->email,
                        'mailer' => config('mail.default', 'log'),
                    ]);
                } catch (\Throwable $emailError) {
                    Log::error('Erreur lors de l\'envoi de l\'email client (simulation webhook)', [
                        'order_id' => $order->id,
                        'user_email' => $user->email,
                        'error' => $emailError->getMessage(),
                        'file' => $emailError->getFile(),
                        'line' => $emailError->getLine(),
                        'trace' => $emailError->getTraceAsString(),
                        'mailer' => config('mail.default', 'log'),
                    ]);
                }

                // Email Admin
                try {
                    Log::info('Tentative d\'envoi de l\'email admin (simulation webhook)', [
                        'order_id' => $order->id,
                        'admin_email' => 'charleshaba454@gmail.com',
                        'mailer' => config('mail.default', 'log'),
                        'mail_host' => config('mail.mailers.smtp.host'),
                        'mail_from' => config('mail.from.address'),
                    ]);

                    $mailable = new \App\Mail\AdminOrderPaymentNotification($order, $user, false);
                    \Mail::to('charleshaba454@gmail.com')->send($mailable);

                    Log::info('Email admin envoyé avec succès (simulation webhook)', [
                        'order_id' => $order->id,
                        'admin_email' => 'charleshaba454@gmail.com',
                        'mailer' => config('mail.default', 'log'),
                    ]);
                } catch (\Throwable $emailError) {
                    Log::error('Erreur lors de l\'envoi de l\'email admin (simulation webhook)', [
                        'order_id' => $order->id,
                        'admin_email' => 'charleshaba454@gmail.com',
                        'error' => $emailError->getMessage(),
                        'file' => $emailError->getFile(),
                        'line' => $emailError->getLine(),
                        'trace' => $emailError->getTraceAsString(),
                        'mailer' => config('mail.default', 'log'),
                    ]);
                }

                Log::info('Webhook simulé avec succès - Commande validée', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'new_status' => $order->status,
                ]);
            } else {
                Log::info('Webhook simulé - Commande déjà validée', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Webhook simulé avec succès',
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la simulation du webhook', [
                'error' => $e->getMessage(),
                'order_id' => $orderId,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Erreur lors de la simulation du webhook',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
