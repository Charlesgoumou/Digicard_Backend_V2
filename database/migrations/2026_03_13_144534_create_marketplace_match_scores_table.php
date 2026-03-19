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
        Schema::create('marketplace_match_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('offer_id')->constrained('marketplace_offers')->onDelete('cascade');
            $table->decimal('match_score', 5, 2)->default(0); // Score de 0 à 100
            $table->json('match_details')->nullable(); // Détails du matching (mots-clés correspondants, etc.)
            $table->boolean('notified')->default(false); // Si l'utilisateur a été notifié
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('last_calculated_at')->nullable();
            $table->timestamps();
            
            // Index pour les requêtes fréquentes
            $table->index(['user_id', 'match_score']);
            $table->index(['offer_id', 'match_score']);
            $table->index(['user_id', 'notified']);
            $table->unique(['user_id', 'offer_id']); // Un seul score par utilisateur/offre
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketplace_match_scores');
    }
};
