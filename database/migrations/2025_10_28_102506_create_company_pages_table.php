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
        Schema::create('company_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Lien avec le business_admin

            // Identité de l'entreprise
            $table->string('company_name')->nullable();
            $table->string('company_name_short')->nullable(); // Acronyme
            $table->string('logo_url')->nullable();

            // Charte graphique
            $table->string('primary_color')->nullable(); // Couleur principale
            $table->string('secondary_color')->nullable(); // Couleur secondaire

            // Services (JSON pour stocker un tableau de services)
            $table->json('services')->nullable();

            // Hero Section
            $table->string('hero_headline')->nullable();
            $table->text('hero_subheadline')->nullable();
            $table->text('hero_description')->nullable();

            // Chart/Expertise
            $table->json('chart_labels')->nullable();
            $table->json('chart_data')->nullable();
            $table->json('chart_colors')->nullable();
            $table->string('chart_title')->nullable();
            $table->text('chart_description')->nullable();

            // Piliers/Services principaux
            $table->json('pillars')->nullable();
            $table->string('pillars_title')->nullable();

            // Engagement
            $table->text('engagement_description')->nullable();

            // Bouton produits
            $table->string('products_button_text')->default('Nos Produits');
            $table->string('products_button_icon')->default('fa-list');
            $table->string('products_modal_title')->default('Nos Produits et Services');

            // Contact (footer)
            $table->string('contact_name')->nullable();
            $table->text('contact_address')->nullable();
            $table->string('contact_phones')->nullable();
            $table->string('contact_email')->nullable();

            // Statut de publication
            $table->boolean('is_published')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_pages');
    }
};
