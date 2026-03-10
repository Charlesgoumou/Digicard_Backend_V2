<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\OrderEmployee;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_employees', function (Blueprint $table) {
            // Ajouter les colonnes de design après spotify_url
            $table->string('card_design_type')->nullable()->after('spotify_url'); // 'template' ou 'custom'
            $table->integer('card_design_number')->nullable()->after('card_design_type'); // Numéro du template (1-30)
            $table->string('card_design_custom_url')->nullable()->after('card_design_number'); // URL du design personnalisé
            $table->boolean('no_design_yet')->default(false)->after('card_design_custom_url'); // Case à cocher "Je n'ai pas encore mon design"
        });

        // Copier les données de design des anciennes commandes pour les business admins inclus
        $this->copyDesignDataFromOrdersToOrderEmployees();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_employees', function (Blueprint $table) {
            $table->dropColumn(['card_design_type', 'card_design_number', 'card_design_custom_url', 'no_design_yet']);
        });
    }

    /**
     * Copier les données de design des commandes vers order_employees pour les business admins inclus
     */
    private function copyDesignDataFromOrdersToOrderEmployees(): void
    {
        // Récupérer toutes les commandes de type business qui ont des données de design
        $orders = Order::where('order_type', 'business')
            ->where(function ($query) {
                $query->whereNotNull('card_design_type')
                    ->orWhere('no_design_yet', true);
            })
            ->get();

        foreach ($orders as $order) {
            // Vérifier si le business admin est inclus dans cette commande
            $orderEmployee = OrderEmployee::where('order_id', $order->id)
                ->where('employee_id', $order->user_id)
                ->first();

            if ($orderEmployee) {
                // Copier les données de design de la commande vers order_employee
                // seulement si order_employee n'a pas déjà de données de design
                if (!$orderEmployee->card_design_type && !$orderEmployee->no_design_yet) {
                    $orderEmployee->card_design_type = $order->card_design_type;
                    $orderEmployee->card_design_number = $order->card_design_number;
                    $orderEmployee->card_design_custom_url = $order->card_design_custom_url;
                    $orderEmployee->no_design_yet = $order->no_design_yet ?? false;
                    $orderEmployee->save();
                }
            }
        }
    }
};

