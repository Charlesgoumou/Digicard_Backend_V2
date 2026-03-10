<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Cette migration ajoute order_id à la table appointment_settings
     * pour permettre des paramètres de rendez-vous distincts par commande.
     */
    public function up(): void
    {
        // Étape 1: Ajouter la colonne order_id
        Schema::table('appointment_settings', function (Blueprint $table) {
            // Ajouter la colonne order_id (nullable pour compatibilité avec les données existantes)
            $table->unsignedBigInteger('order_id')->nullable()->after('user_id');
        });

        // Étape 2: Ajouter la clé étrangère séparément
        Schema::table('appointment_settings', function (Blueprint $table) {
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
        });

        // Étape 3: Créer un nouvel index unique composite
        // Note: On ne peut pas supprimer l'index unique existant s'il est utilisé par une FK
        // Donc on ajoute simplement le nouvel index composite
        Schema::table('appointment_settings', function (Blueprint $table) {
            // Ajouter un index unique composite sur user_id et order_id
            // Cela permet d'avoir une seule configuration par combinaison user_id + order_id
            $table->unique(['user_id', 'order_id'], 'appointment_settings_user_order_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointment_settings', function (Blueprint $table) {
            // Supprimer l'index unique composite
            $table->dropUnique('appointment_settings_user_order_unique');
            
            // Supprimer la contrainte de clé étrangère et la colonne
            $table->dropForeign(['order_id']);
            $table->dropColumn('order_id');
        });
    }
};
