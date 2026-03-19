<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_id')->constrained('wallets')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // redondant mais pratique pour requêtes

            // Sens et type
            $table->enum('direction', ['credit', 'debit']);
            $table->enum('type', [
                'deposit_external',
                'withdraw_external',
                'purchase_debit',
                'sale_credit',
                'adjustment',
            ]);

            // Montants en minor units
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);

            // États (utile pour externe / idempotence)
            $table->enum('status', ['pending', 'completed', 'failed', 'cancelled'])->default('completed');

            // Références optionnelles vers le métier
            $table->unsignedBigInteger('marketplace_offer_id')->nullable();
            $table->unsignedBigInteger('marketplace_purchase_id')->nullable();
            $table->string('external_provider')->nullable(); // ex: mtn, orange, wave, etc.
            $table->string('external_reference')->nullable(); // id transaction provider

            // Idempotence / corrélation
            $table->uuid('idempotency_key')->nullable();

            $table->json('meta')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['wallet_id', 'created_at']);
            $table->index(['marketplace_offer_id']);
            $table->index(['marketplace_purchase_id']);
            $table->unique(['external_provider', 'external_reference']);
            $table->unique(['idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};

