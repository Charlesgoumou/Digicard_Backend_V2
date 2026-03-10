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
        Schema::create('marketplace_offer_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_offer_id')->constrained('marketplace_offers')->onDelete('cascade');
            $table->string('image_url');
            $table->integer('order')->default(0); // Ordre d'affichage des images
            $table->boolean('is_primary')->default(false); // Image principale
            $table->timestamps();
            
            // Index pour améliorer les performances
            $table->index(['marketplace_offer_id', 'order']);
            $table->index(['marketplace_offer_id', 'is_primary']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketplace_offer_images');
    }
};
