<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AdminNotification;
use App\Models\User;
use App\Models\Order;

class AdminNotificationSeeder extends Seeder
{
    public function run(): void
    {
        // Nettoyer les anciennes notifications de démonstration
        AdminNotification::query()->delete();

        $user = User::orderBy('id', 'asc')->first();
        if (!$user) {
            return; // Pas d'utilisateur en base
        }

        // Chercher une commande paramétrée pour cet utilisateur
        $configuredOrder = Order::where('user_id', $user->id)
            ->where('is_configured', true)
            ->orWhere(function($query) use ($user) {
                $query->whereHas('orderEmployees', function($q) use ($user) {
                    $q->where('employee_id', $user->id)
                      ->where('is_configured', true);
                });
            })
            ->first();

        $baseProfileUrl = url('/profil/' . $user->username);
        
        AdminNotification::insert([
            [
                'type' => 'user_registered',
                'user_id' => $user->id,
                'message' => 'Nouvelle inscription: ' . $user->name,
                'url' => $baseProfileUrl,
                'meta' => json_encode(['role' => $user->role, 'email' => $user->email]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'type' => 'order_validated',
                'user_id' => $user->id,
                'order_id' => $configuredOrder?->id,
                'message' => 'Commande validée par ' . $user->name,
                'url' => $configuredOrder ? $baseProfileUrl . '?order=' . $configuredOrder->id : $baseProfileUrl,
                'meta' => json_encode(['order_number' => $configuredOrder?->order_number]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'type' => 'card_added',
                'user_id' => $user->id,
                'order_id' => $configuredOrder?->id,
                'message' => $user->name . ' a ajouté une carte à une commande validée',
                'url' => $configuredOrder ? $baseProfileUrl . '?order=' . $configuredOrder->id : $baseProfileUrl,
                'meta' => json_encode(['order_number' => $configuredOrder?->order_number]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'type' => 'order_configured',
                'user_id' => $user->id,
                'order_id' => $configuredOrder?->id,
                'message' => 'Commande paramétrée par ' . $user->name,
                'url' => $configuredOrder ? $baseProfileUrl . '?order=' . $configuredOrder->id : $baseProfileUrl,
                'meta' => json_encode(['order_number' => $configuredOrder?->order_number]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}


