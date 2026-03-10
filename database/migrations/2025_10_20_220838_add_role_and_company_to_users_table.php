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
        // Rôle : 'individual', 'business_admin', 'employee'
        $table->string('role')->default('individual')->after('remember_token');
        // Nom de l'entreprise (pour business_admin et employee)
        $table->string('company_name')->nullable()->after('role');
        // ID de l'admin qui a créé l'employé (pour lier les employés à un admin)
        $table->foreignId('business_admin_id')->nullable()->constrained('users')->onDelete('cascade')->after('company_name');
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        // Vérifie si la colonne existe avant de la supprimer (bonne pratique)
        if (Schema::hasColumn('users', 'business_admin_id')) {
            // Supprime d'abord la contrainte de clé étrangère
             $table->dropForeign(['business_admin_id']);
        }
         $table->dropColumn(['role', 'company_name', 'business_admin_id']);
    });
}
};
