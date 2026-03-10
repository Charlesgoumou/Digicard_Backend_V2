<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\Order;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'access_token')) {
                $table->string('access_token', 64)->nullable()->unique()->after('status');
            }
        });

        // Générer les tokens pour toutes les commandes validées existantes
        $this->generateTokensForValidatedOrders();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('access_token');
        });
    }

    /**
     * Génère les tokens pour toutes les commandes validées existantes
     */
    private function generateTokensForValidatedOrders(): void
    {
        // Récupérer toutes les commandes validées sans token
        $orders = Order::where('status', 'validated')
            ->whereNull('access_token')
            ->get();

        foreach ($orders as $order) {
            try {
                // Générer un token unique
                $token = $this->generateUniqueToken();
                
                // Mettre à jour la commande directement en base de données
                // pour éviter de déclencher les événements du modèle
                DB::table('orders')
                    ->where('id', $order->id)
                    ->update(['access_token' => $token]);
            } catch (\Exception $e) {
                // Logger l'erreur mais continuer avec les autres commandes
                \Log::error("Erreur lors de la génération du token pour la commande #{$order->id}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Génère un token unique
     */
    private function generateUniqueToken(): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*';
        $token = '';
        
        do {
            $token = '';
            for ($i = 0; $i < 32; $i++) {
                $token .= $characters[random_int(0, strlen($characters) - 1)];
            }
        } while (DB::table('orders')->where('access_token', $token)->exists());
        
        return $token;
    }
};

