<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Nombre total d'employés dans cette commande entreprise
            $table->unsignedInteger('total_employees')->default(0)->after('card_quantity');

            // JSON contenant les "slots" d'employés
            // Structure : [{"slot_number": 1, "cards_quantity": 3, "employee_id": null, "is_assigned": false}, ...]
            $table->json('employee_slots')->nullable()->after('total_employees');

            // Nombre de cartes par employé (pour simplifier les calculs)
            $table->unsignedInteger('cards_per_employee')->default(1)->after('employee_slots');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['total_employees', 'employee_slots', 'cards_per_employee']);
        });
    }
};
