<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Mettre à jour toutes les commandes existantes avec le statut 'validated'
     * vers 'pending' pour qu'elles puissent recevoir l'email de validation.
     */
    public function up(): void
    {
        // Réinitialiser toutes les commandes 'validated' en 'pending'
        DB::table('orders')
            ->where('status', 'validated')
            ->update([
                'status' => 'pending',
                'subscription_start_date' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // En cas de rollback, remettre les commandes en 'validated'
        DB::table('orders')
            ->where('status', 'pending')
            ->whereNull('subscription_start_date')
            ->update([
                'status' => 'validated',
                'subscription_start_date' => DB::raw('DATE(created_at)'),
                'updated_at' => now(),
            ]);
    }
};
