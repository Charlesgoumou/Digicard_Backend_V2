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
            // Code court URL-safe (ex: /p/Ab3x9Q) pour remplacer les URLs longues.
            // Nullable pour permettre un backfill progressif.
            $table->string('short_code', 6)->nullable()->unique()->after('access_token');
            $table->index('short_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['short_code']);
            $table->dropUnique(['short_code']);
            $table->dropColumn('short_code');
        });
    }
};
