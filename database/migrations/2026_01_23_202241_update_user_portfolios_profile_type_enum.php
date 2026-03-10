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
        // Modifier la colonne profile_type pour inclure tous les types de profils
        Schema::table('user_portfolios', function (Blueprint $table) {
            $table->enum('profile_type', [
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
            ])->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revenir à l'enum original
        Schema::table('user_portfolios', function (Blueprint $table) {
            $table->enum('profile_type', ['student', 'teacher', 'freelance'])->nullable()->change();
        });
    }
};
