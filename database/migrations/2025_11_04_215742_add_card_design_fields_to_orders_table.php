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
            if (!Schema::hasColumn('orders', 'card_design_type')) {
                $table->string('card_design_type')->nullable()->after('spotify_url'); // 'template' ou 'custom'
            }
            if (!Schema::hasColumn('orders', 'card_design_number')) {
                $table->integer('card_design_number')->nullable()->after('card_design_type'); // Numéro du template (1-30)
            }
            if (!Schema::hasColumn('orders', 'card_design_custom_url')) {
                $table->string('card_design_custom_url')->nullable()->after('card_design_number'); // URL du design personnalisé
            }
            if (!Schema::hasColumn('orders', 'no_design_yet')) {
                $table->boolean('no_design_yet')->default(false)->after('card_design_custom_url'); // Case à cocher "Je n'ai pas encore mon design"
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['card_design_type', 'card_design_number', 'card_design_custom_url', 'no_design_yet']);
        });
    }
};
