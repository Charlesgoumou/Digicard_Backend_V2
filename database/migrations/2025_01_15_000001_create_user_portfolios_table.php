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
        Schema::create('user_portfolios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Lien avec l'utilisateur (particulier)
            
            // Type de profil
            $table->enum('profile_type', ['student', 'teacher', 'freelance'])->nullable();
            
            // Informations personnelles
            $table->string('name')->nullable();
            $table->string('photo_url')->nullable(); // Utilise la photo de la commande
            $table->string('hero_headline')->nullable();
            $table->text('bio')->nullable();
            
            // Compétences (JSON)
            $table->json('skills')->nullable();
            $table->string('skills_title')->default('Mes Compétences');
            
            // Projets (JSON)
            $table->json('projects')->nullable();
            $table->string('projects_title')->default('Mes Projets');
            
            // Timeline / Parcours (JSON)
            $table->json('timeline')->nullable();
            $table->string('timeline_title')->default('Mon Parcours');
            
            // Contact
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->string('github_url')->nullable();
            
            // Couleurs (provenant de la carte)
            $table->string('primary_color')->nullable();
            $table->string('secondary_color')->nullable();
            
            // Titres personnalisés (pour s'adapter au type de profil)
            $table->string('profile_title')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_portfolios');
    }
};

