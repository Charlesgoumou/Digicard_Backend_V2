<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Table des rendez-vous pris par les visiteurs.
     * Chaque rendez-vous est lié au propriétaire de la carte (user_id).
     */
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('visitor_name');
            $table->string('visitor_email');
            $table->string('visitor_phone')->nullable();
            $table->text('message')->nullable();
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->string('status')->default('confirmed'); // 'confirmed', 'cancelled', 'completed'
            $table->timestamps();

            // Index pour optimiser les requêtes de recherche
            $table->index(['user_id', 'start_time']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};


