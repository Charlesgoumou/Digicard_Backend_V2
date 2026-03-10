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
        // Modifier la colonne profile_type pour inclure 'restaurant'
        DB::statement("ALTER TABLE user_portfolios MODIFY COLUMN profile_type ENUM(
            'student',
            'teacher',
            'freelance',
            'pharmacist',
            'doctor',
            'lawyer',
            'notary',
            'bailiff',
            'architect',
            'engineer',
            'consultant',
            'accountant',
            'financial_analyst',
            'photographer',
            'graphic_designer',
            'developer',
            'banker',
            'restaurant'
        ) NULL");

        // Ajouter la colonne menu pour stocker le menu du restaurant (plats, boissons, etc.)
        Schema::table('user_portfolios', function (Blueprint $table) {
            if (!Schema::hasColumn('user_portfolios', 'menu')) {
                $table->json('menu')->nullable()->after('formations');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Supprimer la colonne menu
        Schema::table('user_portfolios', function (Blueprint $table) {
            if (Schema::hasColumn('user_portfolios', 'menu')) {
                $table->dropColumn('menu');
            }
        });

        // Revenir à l'enum sans 'restaurant'
        DB::statement("ALTER TABLE user_portfolios MODIFY COLUMN profile_type ENUM(
            'student',
            'teacher',
            'freelance',
            'pharmacist',
            'doctor',
            'lawyer',
            'notary',
            'bailiff',
            'architect',
            'engineer',
            'consultant',
            'accountant',
            'financial_analyst',
            'photographer',
            'graphic_designer',
            'developer',
            'banker'
        ) NULL");
    }
};
