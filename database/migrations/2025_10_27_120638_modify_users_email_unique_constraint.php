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
        Schema::table('users', function (Blueprint $table) {
            // Supprimer l'index unique sur 'email' uniquement
            $table->dropUnique(['email']);

            // Ajouter un index unique sur la combinaison (email, role)
            // Cela permet à une même personne d'avoir un compte 'individual' et un compte 'business_admin'
            $table->unique(['email', 'role'], 'users_email_role_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Supprimer l'index unique sur (email, role)
            $table->dropUnique('users_email_role_unique');

            // Rétablir l'index unique sur 'email' uniquement
            $table->unique('email');
        });
    }
};
