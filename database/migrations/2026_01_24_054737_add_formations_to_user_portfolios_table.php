<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_portfolios', function (Blueprint $table) {
            // Ajouter la section Formations
            $table->json('formations')->nullable()->after('timeline');
            $table->string('formations_title')->default('Mes Formations')->after('timeline_title');
            
            // Mettre à jour le titre par défaut de timeline pour "Mon Parcours Professionnel"
            // (on garde timeline pour la colonne JSON pour la compatibilité)
        });
        
        // Mettre à jour les titres existants
        DB::table('user_portfolios')
            ->whereNull('timeline_title')
            ->orWhere('timeline_title', '=', 'Mon Parcours')
            ->orWhere('timeline_title', '=', 'Ma Formation & Stages')
            ->orWhere('timeline_title', '=', 'Mon Parcours & Clients')
            ->update(['timeline_title' => 'Mon Parcours Professionnel']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_portfolios', function (Blueprint $table) {
            $table->dropColumn(['formations', 'formations_title']);
        });
    }
};
