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
        Schema::create('shared_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Propriétaire de la carte
            $table->foreignId('order_id')->nullable()->constrained()->onDelete('set null'); // Commande associée (optionnel)
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('company')->nullable();
            $table->text('note')->nullable();
            $table->string('vcard_path')->nullable(); // Chemin vers le fichier vCard généré
            $table->timestamp('vcard_generated_at')->nullable(); // Date de génération du vCard
            $table->boolean('is_downloaded')->default(false); // Si le vCard a été téléchargé
            $table->timestamp('downloaded_at')->nullable(); // Date du téléchargement
            $table->timestamps();
            
            // Index pour les requêtes fréquentes
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Rollback the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shared_contacts');
    }
};

