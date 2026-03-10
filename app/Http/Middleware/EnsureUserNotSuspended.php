<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserNotSuspended
{
    /**
     * Handle an incoming request.
     *
     * If the authenticated user is suspended, revoke the current Sanctum token
     * and block access with a 403 response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->is_suspended) {
            // Revoke only the current token when possible
            try {
                if (method_exists($user, 'currentAccessToken') && $user->currentAccessToken()) {
                    $user->currentAccessToken()->delete();
                }
            } catch (\Throwable $e) {
                // swallow token deletion errors; still block the request
            }

            return response()->json([
                'message' => 'Votre compte a été suspendu. Veuillez contacter l\'administrateur.',
                'code' => 'ACCOUNT_SUSPENDED'
            ], 403);
        }

        return $next($request);
    }
}


