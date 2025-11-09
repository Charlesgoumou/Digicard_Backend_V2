<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Vérifier si l'utilisateur est authentifié
        if (!auth()->check()) {
            return response()->json([
                'message' => 'Non authentifié'
            ], 401);
        }

        $user = auth()->user();

        // Vérifier si l'utilisateur a le rôle admin ou super_admin
        if (!in_array($user->role, ['admin', 'super_admin']) && !$user->is_admin) {
            return response()->json([
                'message' => 'Accès non autorisé. Vous devez être administrateur.'
            ], 403);
        }

        return $next($request);
    }
}
