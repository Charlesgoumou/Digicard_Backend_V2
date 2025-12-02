<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use App\Mail\OrderValidated;

class OrderController extends Controller
{
    /**
     * Liste paginée de toutes les commandes avec filtres
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = Order::query();

        // Filtre par statut (pending, validated, cancelled)
        if ($request->has('status') && $request->status !== '') {
            $query->where('status', $request->status);
        }

        // Filtre par type (personal, business)
        if ($request->has('order_type') && $request->order_type !== '') {
            $query->where('order_type', $request->order_type);
        }

        // Filtre par numéro de commande (recherche partielle)
        if ($request->has('order_number') && $request->order_number !== '') {
            $query->where('order_number', 'like', '%' . $request->order_number . '%');
        }

        // Filtre par user_id
        if ($request->has('user_id') && $request->user_id !== '') {
            $query->where('user_id', $request->user_id);
        }

        // Filtre par date de création (depuis)
        if ($request->has('created_from') && $request->created_from !== '') {
            $query->whereDate('created_at', '>=', $request->created_from);
        }

        // Filtre par date de création (jusqu'à)
        if ($request->has('created_to') && $request->created_to !== '') {
            $query->whereDate('created_at', '<=', $request->created_to);
        }

        // Filtre par montant minimum
        if ($request->has('min_amount') && $request->min_amount !== '') {
            $query->where('total_price', '>=', $request->min_amount);
        }

        // Filtre par montant maximum
        if ($request->has('max_amount') && $request->max_amount !== '') {
            $query->where('total_price', '<=', $request->max_amount);
        }

        // Chargement des relations
        // IMPORTANT: Inclure les champs de design et card_quantity dans orderEmployees pour les commandes business
        // IMPORTANT: Inclure aussi le username et access_token pour les profils publics
        // ✅ NOUVEAU: Inclure employee_avatar_url pour la colonne Photo
        $query->with([
            'user:id,name,email,username,role,vcard_phone,phone_numbers',
            'orderEmployees' => function ($query) {
                $query->select([
                    'id',
                    'order_id',
                    'employee_id',
                    'employee_name',
                    'employee_email',
                    'card_quantity',
                    'is_configured',
                    'card_design_type',
                    'card_design_number',
                    'card_design_custom_url',
                    'no_design_yet',
                    'employee_avatar_url', // ✅ NOUVEAU: Pour la colonne Photo
                ]);
            },
            'orderEmployees.employee:id,name,email,username,role'
        ]);

        // Tri (par défaut : plus récentes en premier)
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination (20 par page par défaut)
        $perPage = $request->get('per_page', 20);
        $orders = $query->paginate($perPage);

        // IMPORTANT: Enrichir les commandes avec les données de profil (username, access_token)
        // pour les business_admin et particuliers, et pour les employés dans order_employees
        $orders->getCollection()->transform(function ($order) {
            // Pour les commandes business, enrichir order_employees avec les données de profil
            if ($order->order_type === 'business' && $order->orderEmployees) {
                foreach ($order->orderEmployees as $orderEmployee) {
                    // Ajouter le username depuis la relation employee
                    if ($orderEmployee->employee && $orderEmployee->employee->username) {
                        $orderEmployee->username = $orderEmployee->employee->username;
                    }
                    // Ajouter l'access_token depuis la commande
                    if ($order->access_token) {
                        $orderEmployee->access_token = $order->access_token;
                    }
                }
            }

            // Pour toutes les commandes, s'assurer que le username et access_token sont accessibles
            // au niveau racine pour faciliter l'accès depuis le frontend
            if ($order->user && $order->user->username) {
                $order->profile_username = $order->user->username;
            }

            return $order;
        });

        // Ajouter des statistiques globales pour la page
        $stats = [
            'total_orders' => Order::count(),
            'pending_orders' => Order::where('status', 'pending')->count(),
            'validated_orders' => Order::where('status', 'validated')->count(),
            'cancelled_orders' => Order::where('status', 'cancelled')->count(),
            'total_revenue' => Order::where('status', 'validated')->sum('total_price'),
            'total_cards' => Order::where('status', 'validated')->sum('card_quantity'),
        ];

        return response()->json([
            'orders' => $orders,
            'stats' => $stats
        ]);
    }

    /**
     * Affiche les détails d'une commande spécifique
     *
     * @param Order $order
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Order $order)
    {
        // Charger toutes les relations utiles
        // IMPORTANT: Inclure les champs de design et card_quantity dans orderEmployees pour les commandes business
        // ✅ NOUVEAU: Inclure employee_avatar_url pour la colonne Photo
        $order->load([
            'user:id,name,email,username,role,company_name,avatar_url,vcard_phone,phone_numbers,emails',
            'orderEmployees' => function ($query) {
                $query->select([
                    'id',
                    'order_id',
                    'employee_id',
                    'employee_name',
                    'employee_email',
                    'card_quantity',
                    'is_configured',
                    'card_design_type',
                    'card_design_number',
                    'card_design_custom_url',
                    'no_design_yet',
                    'employee_avatar_url', // ✅ NOUVEAU: Pour la colonne Photo
                ]);
            },
            'orderEmployees.employee:id,name,email,username,avatar_url,role',
        ]);

        // Calculer des statistiques supplémentaires pour cette commande
        $orderStats = [
            'total_employees' => $order->orderEmployees->count(),
            'configured_employees' => $order->orderEmployees->where('is_configured', true)->count(),
            'pending_employees' => $order->orderEmployees->where('is_configured', false)->count(),
            'revenue_per_card' => $order->card_quantity > 0 ? $order->total_price / $order->card_quantity : 0,
        ];

        // Analyser les slots d'employés si c'est une commande business
        if ($order->order_type === 'business' && $order->employee_slots) {
            $slots = collect($order->employee_slots);
            $orderStats['total_slots'] = $slots->count();
            $orderStats['assigned_slots'] = $slots->where('is_assigned', true)->count();
            $orderStats['configured_slots'] = $slots->where('is_configured', true)->count();
        }

        // IMPORTANT: Enrichir la commande avec les données de profil (username, access_token)
        // pour les business_admin et particuliers, et pour les employés dans order_employees
        // Pour les commandes business, enrichir order_employees avec les données de profil
        if ($order->order_type === 'business' && $order->orderEmployees) {
            foreach ($order->orderEmployees as $orderEmployee) {
                // Ajouter le username depuis la relation employee
                if ($orderEmployee->employee && $orderEmployee->employee->username) {
                    $orderEmployee->username = $orderEmployee->employee->username;
                }
                // Ajouter l'access_token depuis la commande
                if ($order->access_token) {
                    $orderEmployee->access_token = $order->access_token;
                }
            }
        }

        // Pour toutes les commandes, s'assurer que le username et access_token sont accessibles
        // au niveau racine pour faciliter l'accès depuis le frontend
        if ($order->user && $order->user->username) {
            $order->profile_username = $order->user->username;
        }

        // ✅ NOUVEAU: Logger les données de photos pour le débogage
        Log::info('Admin OrderController::show - Données de photos', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'order_type' => $order->order_type,
            'order_avatar_url' => $order->order_avatar_url,
            'order_employees_count' => $order->orderEmployees->count(),
            'order_employees_with_photos' => $order->orderEmployees->filter(function($emp) {
                return !empty($emp->employee_avatar_url);
            })->map(function($emp) {
                return [
                    'employee_name' => $emp->employee_name,
                    'employee_avatar_url' => $emp->employee_avatar_url,
                    'is_configured' => $emp->is_configured,
                ];
            })->values()->toArray(),
        ]);

        return response()->json([
            'order' => $order,
            'stats' => $orderStats
        ]);
    }

    /**
     * Met à jour le statut d'une commande
     *
     * @param Request $request
     * @param Order $order
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStatus(Request $request, Order $order)
    {
        $request->validate([
            'status' => ['required', Rule::in(['pending', 'validated', 'cancelled'])],
            'reason' => 'nullable|string|max:500', // Raison du changement (optionnelle)
        ]);

        $oldStatus = $order->status;
        $newStatus = $request->status;

        // Vérifier si le statut a vraiment changé
        if ($oldStatus === $newStatus) {
            return response()->json([
                'message' => 'Le statut est déjà ' . $newStatus
            ], 400);
        }

        // Mettre à jour le statut
        $order->status = $newStatus;

        // Si passage à "validated", définir la date de début d'abonnement si elle n'existe pas
        // et générer le token d'accès si il n'existe pas
        if ($newStatus === 'validated') {
            if (!$order->subscription_start_date) {
                $order->subscription_start_date = now();
            }
            // Générer le token d'accès si il n'existe pas
            if (!$order->access_token) {
                $order->access_token = $order->generateAccessToken();
            }
        }

        // Si passage à "cancelled", supprimer la date d'abonnement
        if ($newStatus === 'cancelled') {
            $order->subscription_start_date = null;
        }

        $order->save();

        // Envoyer un email à l'utilisateur si la commande est validée
        if ($newStatus === 'validated') {
            try {
                Mail::to($order->user->email)->send(new OrderValidated($order, $order->user));

                Log::info('Order validation email sent', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'user_email' => $order->user->email,
                ]);
            } catch (\Exception $e) {
                // Logger l'erreur mais ne pas faire échouer la validation
                Log::error('Failed to send order validation email', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'user_email' => $order->user->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Logger l'action (très important pour la traçabilité)
        Log::warning('Admin order status change', [
            'admin_id' => auth()->id(),
            'admin_email' => auth()->user()->email,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'user_id' => $order->user_id,
            'user_email' => $order->user->email,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'reason' => $request->reason,
            'timestamp' => now(),
        ]);

        // Message personnalisé selon le changement
        $messages = [
            'validated' => 'Commande validée avec succès',
            'cancelled' => 'Commande annulée avec succès',
            'pending' => 'Commande remise en attente',
        ];

        return response()->json([
            'message' => $messages[$newStatus] ?? 'Statut mis à jour',
            'order' => $order->fresh(['user:id,name,email'])
        ]);
    }

    /**
     * Statistiques globales pour le dashboard des commandes
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function stats()
    {
        $stats = [
            // Compteurs globaux
            'total_orders' => Order::count(),
            'pending_orders' => Order::where('status', 'pending')->count(),
            'validated_orders' => Order::where('status', 'validated')->count(),
            'cancelled_orders' => Order::where('status', 'cancelled')->count(),

            // Revenus
            'total_revenue' => Order::where('status', 'validated')->sum('total_price'),
            'pending_revenue' => Order::where('status', 'pending')->sum('total_price'),
            'cancelled_revenue' => Order::where('status', 'cancelled')->sum('total_price'),

            // Cartes
            'total_cards' => Order::where('status', 'validated')->sum('card_quantity'),
            'pending_cards' => Order::where('status', 'pending')->sum('card_quantity'),

            // Par type
            'personal_orders' => Order::where('order_type', 'personal')->count(),
            'business_orders' => Order::where('order_type', 'business')->count(),

            // Moyennes
            'average_order_value' => Order::where('status', 'validated')->avg('total_price'),
            'average_cards_per_order' => Order::where('status', 'validated')->avg('card_quantity'),

            // Tendances (7 derniers jours)
            'orders_last_7_days' => Order::where('created_at', '>=', now()->subDays(7))->count(),
            'revenue_last_7_days' => Order::where('status', 'validated')
                ->where('created_at', '>=', now()->subDays(7))
                ->sum('total_price'),

            // Top clients
            'top_clients' => Order::selectRaw('user_id, COUNT(*) as order_count, SUM(total_price) as total_spent')
                ->where('status', 'validated')
                ->groupBy('user_id')
                ->orderByDesc('total_spent')
                ->with('user:id,name,email,company_name')
                ->take(5)
                ->get(),

            // Dernières commandes
            'recent_orders' => Order::with('user:id,name,email')
                ->orderBy('created_at', 'desc')
                ->take(10)
                ->get(),
        ];

        return response()->json($stats);
    }
}
