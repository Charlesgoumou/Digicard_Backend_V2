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
        Schema::table('orders', function (Blueprint $table) {
            // Vérifier si la colonne order_type existe déjà avant de l'ajouter
            if (!Schema::hasColumn('orders', 'order_type')) {
                $table->enum('order_type', ['personal', 'business'])->default('personal')->after('user_id');
            }
            // Vérifier si la colonne order_avatar_url existe déjà avant de l'ajouter
            if (!Schema::hasColumn('orders', 'order_avatar_url')) {
                $table->string('order_avatar_url')->nullable()->after('order_type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['order_type', 'order_avatar_url']);
        });
    }
};
