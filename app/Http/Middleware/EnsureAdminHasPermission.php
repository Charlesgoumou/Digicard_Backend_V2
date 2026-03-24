<?php

namespace App\Http\Middleware;

use App\Services\AdminPermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminHasPermission
{
    public function handle(Request $request, Closure $next, string $permissionKey): Response
    {
        if (!auth()->check()) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $user = auth()->user();

        if (!AdminPermissionService::userHas($user, $permissionKey)) {
            return response()->json([
                'message' => 'Permission insuffisante.',
                'required_permission' => $permissionKey,
            ], 403);
        }

        return $next($request);
    }
}

