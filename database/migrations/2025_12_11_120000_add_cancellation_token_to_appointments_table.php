<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Ajoute un token d'annulation pour permettre au demandeur d'annuler son rendez-vous
     * directement depuis l'email sans authentification.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('cancellation_token', 64)->nullable()->unique()->after('status');
            $table->index('cancellation_token'); // Index pour les recherches rapides
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['cancellation_token']);
            $table->dropColumn('cancellation_token');
        });
    }
};
