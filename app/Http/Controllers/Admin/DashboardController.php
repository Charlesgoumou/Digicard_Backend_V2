<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Order;
use App\Models\CompanyPage;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Récupère les statistiques globales de la plateforme
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function stats()
    {
        // Nombre total d'utilisateurs
        $totalUsers = User::count();

        // Nombre total de commandes
        $totalOrders = Order::count();

        // Revenu total (basé sur les commandes validées)
        $totalRevenue = Order::where('status', 'validated')->sum('total_price');

        // Nombre total de pages entreprises
        $totalCompanyPages = CompanyPage::count();

        // Statistiques des utilisateurs
        $activeUsers = User::where('is_suspended', false)->count();
        $suspendedUsers = User::where('is_suspended', true)->count();

        // Statistiques des pages entreprise
        $publishedPages = CompanyPage::where('is_published', true)->count();
        $unpublishedPages = CompanyPage::where('is_published', false)->count();

        // Statistiques supplémentaires utiles
        $stats = [
            'total_users' => $totalUsers,
            'active_users' => $activeUsers,
            'suspended_users' => $suspendedUsers,
            
            'total_orders' => $totalOrders,
            'pending_orders' => Order::where('status', 'pending')->count(),
            'validated_orders' => Order::where('status', 'validated')->count(),
            'cancelled_orders' => Order::where('status', 'cancelled')->count(),
            
            'total_revenue' => $totalRevenue,
            
            'total_company_pages' => $totalCompanyPages,
            'published_pages' => $publishedPages,
            'unpublished_pages' => $unpublishedPages,
            
            // Statistiques détaillées
            'users_by_role' => User::selectRaw('role, COUNT(*) as count')
                ->groupBy('role')
                ->get()
                ->pluck('count', 'role'),
            
            'orders_by_status' => Order::selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->get()
                ->pluck('count', 'status'),
            
            'orders_by_type' => Order::selectRaw('order_type, COUNT(*) as count')
                ->groupBy('order_type')
                ->get()
                ->pluck('count', 'order_type'),
            
            'active_subscriptions' => Order::where('status', 'validated')
                ->whereNotNull('subscription_start_date')
                ->where('subscription_start_date', '<=', now())
                ->count(),
            
            'recent_users' => User::orderBy('created_at', 'desc')
                ->take(5)
                ->select('id', 'name', 'email', 'role', 'created_at')
                ->get(),
            
            'recent_orders' => Order::with('user:id,name,email')
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get(),
        ];

        return response()->json($stats);
    }
}
