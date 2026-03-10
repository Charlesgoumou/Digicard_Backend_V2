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
     * Supprime l'ancien index unique sur user_id seul s'il existe encore.
     * Cet index a été remplacé par un index composite (user_id, order_id).
     */
    public function up(): void
    {
        // Vérifier si l'index existe avant de le supprimer
        $indexExists = DB::select("SHOW INDEX FROM appointment_settings WHERE Key_name = 'appointment_settings_user_id_unique'");
        
        if (!empty($indexExists)) {
            Schema::table('appointment_settings', function (Blueprint $table) {
                // Supprimer l'ancien index unique sur user_id seul
                $table->dropUnique('appointment_settings_user_id_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recréer l'index unique sur user_id seul (pour rollback)
        Schema::table('appointment_settings', function (Blueprint $table) {
            // Vérifier que l'index composite n'existe pas avant de recréer l'ancien
            $compositeIndexExists = DB::select("SHOW INDEX FROM appointment_settings WHERE Key_name = 'appointment_settings_user_order_unique'");
            
            if (empty($compositeIndexExists)) {
                $table->unique('user_id', 'appointment_settings_user_id_unique');
            }
        });
    }
};
