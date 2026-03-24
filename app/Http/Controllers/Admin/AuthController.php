<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Services\AdminPermissionService;

class AuthController extends Controller
{
    /**
     * Connexion admin
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // IMPORTANT: Chercher spécifiquement un compte admin ou super_admin avec cet email
        // Car un même email peut avoir plusieurs rôles (individual, business_admin, super_admin)
        $user = User::where('email', $request->email)
            ->whereIn('role', ['admin', 'super_admin'])
            ->first();

        // Vérifier que l'utilisateur existe et que le mot de passe est correct
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Les identifiants sont incorrects.'
            ], 401);
        }

        // Connecter l'utilisateur manuellement
        Auth::login($user);

        // Créer un token Sanctum
        $token = $user->createToken('admin-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'message' => 'Connexion réussie.'
        ], 200);
    }

    /**
     * Récupérer les informations de l'admin connecté
     */
    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'permissions' => AdminPermissionService::permissionsForUser($user),
        ], 200);
    }

    /**
     * Déconnexion admin
     */
    public function logout(Request $request)
    {
        // Révoquer le token actuel
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Déconnexion réussie.'
        ], 200);
    }
}

