<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminPermission;
use App\Models\AdminRolePermission;
use App\Services\AdminPermissionService;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function marketplaceIndex(Request $request)
    {
        AdminPermissionService::ensureSeeded();

        $permissions = AdminPermission::query()
            ->where('key', 'like', 'marketplace.%')
            ->orderBy('key')
            ->get()
            ->map(fn ($p) => ['key' => $p->key, 'label' => $p->label]);

        $roles = ['admin', 'super_admin'];

        $rolePermissions = [];
        foreach ($roles as $role) {
            $rolePermissions[$role] = AdminRolePermission::where('role', $role)
                ->pluck('permission_key')
                ->values()
                ->all();
        }

        return response()->json([
            'roles' => $roles,
            'permissions' => $permissions,
            'role_permissions' => $rolePermissions,
        ], 200);
    }

    public function updateRoleMarketplace(Request $request, string $role)
    {
        $user = $request->user();
        if (($user->role ?? null) !== 'super_admin') {
            return response()->json(['message' => 'Seul un super_admin peut modifier les permissions.'], 403);
        }

        $role = strtolower(trim($role));
        if (!in_array($role, ['admin', 'super_admin'], true)) {
            return response()->json(['message' => 'Rôle invalide.'], 422);
        }

        $validated = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'string',
        ]);

        AdminPermissionService::ensureSeeded();

        $allowed = AdminPermission::where('key', 'like', 'marketplace.%')->pluck('key')->all();
        $desired = array_values(array_unique(array_filter($validated['permissions'], fn ($k) => in_array($k, $allowed, true))));

        // super_admin garde toujours tout
        if ($role === 'super_admin') {
            $desired = $allowed;
        }

        \DB::transaction(function () use ($role, $desired) {
            AdminRolePermission::where('role', $role)->where('permission_key', 'like', 'marketplace.%')->delete();
            foreach ($desired as $key) {
                AdminRolePermission::create(['role' => $role, 'permission_key' => $key]);
            }
        });

        return response()->json([
            'role' => $role,
            'permissions' => $desired,
        ], 200);
    }
}

