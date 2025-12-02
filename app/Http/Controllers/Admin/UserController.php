<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    /**
     * Liste paginée de tous les utilisateurs avec filtres
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = User::query();

        // Filtre par rôle
        if ($request->has('role') && $request->role !== '') {
            $query->where('role', $request->role);
        }

        // Filtre par email (recherche partielle)
        if ($request->has('email') && $request->email !== '') {
            $query->where('email', 'like', '%' . $request->email . '%');
        }

        // Filtre par nom (recherche partielle)
        if ($request->has('name') && $request->name !== '') {
            $query->where('name', 'like', '%' . $request->name . '%');
        }

        // Filtre par statut admin
        if ($request->has('is_admin') && $request->is_admin !== '') {
            $query->where('is_admin', $request->is_admin === 'true');
        }

        // Filtre par statut suspension
        if ($request->has('is_suspended') && $request->is_suspended !== '') {
            $query->where('is_suspended', $request->is_suspended === 'true');
        }

        // Chargement des relations
        $query->with(['orders', 'employees']);

        // Tri par date de création (plus récents en premier)
        $query->orderBy('created_at', 'desc');

        // Pagination (20 par page par défaut)
        $perPage = $request->get('per_page', 20);
        $users = $query->paginate($perPage);

        return response()->json($users);
    }

    /**
     * Affiche les détails d'un utilisateur spécifique
     *
     * @param User $user
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(User $user)
    {
        // Charger toutes les relations utiles
        $user->load([
            'orders.orderEmployees',
            'employees.orders',
            'businessAdmin',
        ]);

        // Ajouter des statistiques personnalisées
        $userStats = [
            'total_orders' => $user->orders->count(),
            'total_spent' => $user->orders->where('status', 'validated')->sum('total_price'),
            'total_employees' => $user->employees->count(),
            'company_page_exists' => \App\Models\CompanyPage::where('user_id', $user->id)->exists(),
        ];

        return response()->json([
            'user' => $user,
            'stats' => $userStats,
        ]);
    }

    /**
     * Suspend ou réactive un utilisateur
     *
     * @param User $user
     * @return \Illuminate\Http\JsonResponse
     */
    public function suspend(User $user)
    {
        // Empêcher de suspendre un super admin
        if ($user->is_admin) {
            return response()->json([
                'message' => 'Impossible de suspendre un super administrateur'
            ], 403);
        }

        // Empêcher de se suspendre soi-même
        if ($user->id === auth()->id()) {
            return response()->json([
                'message' => 'Vous ne pouvez pas vous suspendre vous-même'
            ], 403);
        }

        // Basculer le statut de suspension
        $user->is_suspended = !$user->is_suspended;
        $user->save();

        // Si suspension, révoquer tous les tokens Sanctum de l'utilisateur
        if ($user->is_suspended) {
            $user->tokens()->delete();
        }

        // Logger l'action
        Log::info('User suspension toggled', [
            'admin_id' => auth()->id(),
            'admin_email' => auth()->user()->email,
            'target_user_id' => $user->id,
            'target_user_email' => $user->email,
            'is_suspended' => $user->is_suspended,
            'timestamp' => now(),
        ]);

        $message = $user->is_suspended
            ? 'Utilisateur suspendu avec succès'
            : 'Utilisateur réactivé avec succès';

        return response()->json([
            'message' => $message,
            'user' => $user
        ]);
    }

    /**
     * Impersonate (se connecter en tant qu'un autre utilisateur)
     *
     * @param User $user
     * @return \Illuminate\Http\JsonResponse
     */
    public function impersonate(User $user)
    {
        // Empêcher d'impersonate un super admin
        if ($user->is_admin) {
            return response()->json([
                'message' => 'Impossible de se connecter en tant que super administrateur'
            ], 403);
        }

        // Empêcher d'impersonate un utilisateur suspendu
        if ($user->is_suspended) {
            return response()->json([
                'message' => 'Impossible de se connecter en tant qu\'utilisateur suspendu'
            ], 403);
        }

        // Empêcher de s'impersonate soi-même
        if ($user->id === auth()->id()) {
            return response()->json([
                'message' => 'Vous ne pouvez pas vous impersonate vous-même'
            ], 403);
        }

        // Créer un nouveau token pour l'utilisateur cible
        // On marque ce token avec un nom spécial pour le retrouver
        $token = $user->createToken('admin-impersonation-' . auth()->id())->plainTextToken;

        // Logger l'action (important pour la sécurité)
        Log::warning('Admin impersonation started', [
            'admin_id' => auth()->id(),
            'admin_email' => auth()->user()->email,
            'target_user_id' => $user->id,
            'target_user_email' => $user->email,
            'timestamp' => now(),
        ]);

        return response()->json([
            'message' => 'Impersonation démarrée avec succès',
            'token' => $token,
            'user' => $user,
            'original_admin' => [
                'id' => auth()->id(),
                'email' => auth()->user()->email,
            ]
        ]);
    }

    /**
     * Arrêter l'impersonation et retourner au compte admin
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function stopImpersonating(Request $request)
    {
        $currentUser = auth()->user();

        // Vérifier si le token actuel est un token d'impersonation
        $currentToken = $request->user()->currentAccessToken();

        if (!$currentToken || !str_starts_with($currentToken->name, 'admin-impersonation-')) {
            return response()->json([
                'message' => 'Aucune impersonation en cours'
            ], 400);
        }

        // Extraire l'ID de l'admin original depuis le nom du token
        $adminId = (int) str_replace('admin-impersonation-', '', $currentToken->name);

        // Supprimer le token d'impersonation
        $currentToken->delete();

        // Récupérer l'admin original
        $admin = User::find($adminId);

        if (!$admin || !$admin->is_admin) {
            return response()->json([
                'message' => 'Administrateur original introuvable'
            ], 404);
        }

        // Créer un nouveau token pour l'admin original
        $newToken = $admin->createToken('admin-token')->plainTextToken;

        // Logger l'action
        Log::info('Admin impersonation stopped', [
            'admin_id' => $admin->id,
            'admin_email' => $admin->email,
            'impersonated_user_id' => $currentUser->id,
            'impersonated_user_email' => $currentUser->email,
            'timestamp' => now(),
        ]);

        return response()->json([
            'message' => 'Impersonation terminée, reconnexion en tant qu\'admin',
            'token' => $newToken,
            'user' => $admin
        ]);
    }

    /**
     * Supprime définitivement un utilisateur
     *
     * @param User $user
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(User $user)
    {
        // Empêcher de supprimer un super administrateur
        if ($user->is_admin) {
            return response()->json([
                'message' => 'Impossible de supprimer un super administrateur'
            ], 403);
        }

        // Empêcher de se supprimer soi-même
        if ($user->id === auth()->id()) {
            return response()->json([
                'message' => 'Vous ne pouvez pas supprimer votre propre compte'
            ], 403);
        }

        // Révoquer tous les tokens d'accès
        $user->tokens()->delete();

        // Supprimer l'avatar stocké si présent
        if ($user->avatar_url) {
            // ✅ CORRECTION : Gérer les deux formats (/storage/ et /api/storage/)
            $oldPath = preg_replace('#^/api/storage/#', '', $user->avatar_url);
            $oldPath = preg_replace('#^/storage/#', '', $oldPath);
            $oldPath = preg_replace('#^https?://[^/]+/(api/)?storage/#', '', $oldPath);
            if (Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        // Logger l'action
        Log::warning('Admin deleted user', [
            'admin_id' => auth()->id(),
            'admin_email' => auth()->user()->email,
            'target_user_id' => $user->id,
            'target_user_email' => $user->email,
            'timestamp' => now(),
        ]);

        // Suppression définitive
        $user->delete();

        return response()->json([
            'message' => 'Utilisateur supprimé définitivement'
        ]);
    }
}
