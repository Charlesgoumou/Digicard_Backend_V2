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
        Schema::create('additional_card_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->integer('quantity')->default(1);
            $table->json('distribution')->nullable(); // Pour les commandes business: {admin: X, employees: {employee_id: Y, ...}}
            $table->decimal('unit_price', 10, 2); // Prix unitaire d'une carte supplémentaire
            $table->decimal('total_price', 10, 2); // Prix total
            $table->string('payment_status')->default('pending'); // pending, paid, failed, cancelled
            $table->string('payment_provider')->nullable(); // chapchap, etc.
            $table->string('payment_operation_id')->nullable(); // ID de l'opération de paiement
            $table->string('payment_url')->nullable(); // URL de paiement
            $table->timestamp('paid_at')->nullable(); // Date de paiement
            $table->text('notes')->nullable();
            $table->timestamps();
            
            // Index pour les recherches
            $table->index('order_id');
            $table->index('user_id');
            $table->index('payment_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('additional_card_payments');
    }
};
