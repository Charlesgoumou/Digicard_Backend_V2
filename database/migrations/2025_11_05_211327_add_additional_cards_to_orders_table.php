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
            if (!Schema::hasColumn('orders', 'additional_cards_count')) {
                $table->integer('additional_cards_count')->default(0)->after('card_quantity');
            }
            if (!Schema::hasColumn('orders', 'additional_cards_total_price')) {
                $table->decimal('additional_cards_total_price', 10, 2)->default(0)->after('additional_cards_count');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['additional_cards_count', 'additional_cards_total_price']);
        });
    }
};
