<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

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

        // Tentative de connexion
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'Les identifiants sont incorrects.'
            ], 401);
        }

        $user = Auth::user();

        // Vérifier que l'utilisateur est admin ou super_admin
        if (!in_array($user->role, ['admin', 'super_admin'])) {
            Auth::logout();
            return response()->json([
                'message' => 'Accès refusé. Vous devez être administrateur.'
            ], 403);
        }

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

