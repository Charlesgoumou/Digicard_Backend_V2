<?php

namespace App\Services;

use App\Models\AdminPermission;
use App\Models\AdminRolePermission;
use App\Models\User;

class AdminPermissionService
{
    /**
     * Permissions Marketplace (base).
     */
    public static function defaultPermissionKeys(): array
    {
        return [
            'marketplace.offers.read',
            'marketplace.offers.create',
            'marketplace.offers.update',
            'marketplace.offers.delete',
            'marketplace.offers.toggle',
            'marketplace.purchases.read',
        ];
    }

    public static function ensureSeeded(): void
    {
        $labels = [
            'marketplace.offers.read' => 'Voir les offres',
            'marketplace.offers.create' => 'Créer une offre',
            'marketplace.offers.update' => 'Modifier une offre',
            'marketplace.offers.delete' => 'Supprimer une offre',
            'marketplace.offers.toggle' => 'Activer / désactiver une offre',
            'marketplace.purchases.read' => 'Voir les achats',
        ];

        foreach (self::defaultPermissionKeys() as $key) {
            AdminPermission::updateOrCreate(
                ['key' => $key],
                ['label' => $labels[$key] ?? $key],
            );
        }

        // super_admin : tous les droits
        foreach (self::defaultPermissionKeys() as $key) {
            AdminRolePermission::firstOrCreate(['role' => 'super_admin', 'permission_key' => $key]);
        }

        // admin : par défaut tout sauf delete (modifiable ensuite)
        $adminDefaults = [
            'marketplace.offers.read',
            'marketplace.offers.create',
            'marketplace.offers.update',
            'marketplace.offers.toggle',
            'marketplace.purchases.read',
        ];
        foreach ($adminDefaults as $key) {
            AdminRolePermission::firstOrCreate(['role' => 'admin', 'permission_key' => $key]);
        }
    }

    public static function permissionsForUser(?User $user): array
    {
        if (!$user) return [];

        $role = (string) ($user->role ?? '');
        if ($role === '') return [];

        self::ensureSeeded();

        return AdminRolePermission::where('role', $role)
            ->pluck('permission_key')
            ->values()
            ->all();
    }

    public static function userHas(User $user, string $permissionKey): bool
    {
        $perms = self::permissionsForUser($user);
        return in_array($permissionKey, $perms, true);
    }
}

