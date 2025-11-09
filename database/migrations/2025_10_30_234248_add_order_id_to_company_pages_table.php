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
        Schema::table('company_pages', function (Blueprint $table) {
            // Ajouter order_id pour lier les services à une commande
            $table->foreignId('order_id')->nullable()->after('user_id')->constrained()->onDelete('cascade');
            
            // Rendre user_id nullable (transition)
            $table->foreignId('user_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_pages', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->dropColumn('order_id');
            
            // Rétablir user_id comme non nullable
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
