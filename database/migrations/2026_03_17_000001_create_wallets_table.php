<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            // Stocké en "minor units" (ex: centimes) pour éviter les erreurs de float/decimal.
            $table->bigInteger('balance_minor')->default(0);
            $table->string('currency', 3)->default('EUR');

            $table->timestamps();

            $table->unique(['user_id', 'currency']);
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};

