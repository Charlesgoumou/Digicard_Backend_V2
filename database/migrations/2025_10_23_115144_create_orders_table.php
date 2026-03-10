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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('order_number')->unique(); // Numéro de commande unique
            $table->enum('order_type', ['personal', 'business'])->default('personal'); // Type de commande
            $table->integer('card_quantity'); // Nombre de cartes
            $table->decimal('unit_price', 10, 2); // Prix unitaire
            $table->decimal('total_price', 10, 2); // Prix total
            $table->decimal('annual_subscription', 10, 2)->default(40000.00); // Abonnement annuel (40,000 GNF)
            $table->date('subscription_start_date')->nullable(); // Date de début d'abonnement (définie lors de la validation)
            $table->enum('status', ['pending', 'validated', 'configured', 'completed', 'cancelled'])->default('pending');
            $table->boolean('is_configured')->default(false); // Si la carte a été paramétrée
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
