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
            if (!Schema::hasColumn('orders', 'tiktok_url')) {
                $table->string('tiktok_url')->nullable()->after('spotify_url');
            }
            if (!Schema::hasColumn('orders', 'threads_url')) {
                $table->string('threads_url')->nullable()->after('tiktok_url');
            }
        });

        Schema::table('order_employees', function (Blueprint $table) {
            if (!Schema::hasColumn('order_employees', 'tiktok_url')) {
                $table->string('tiktok_url')->nullable()->after('spotify_url');
            }
            if (!Schema::hasColumn('order_employees', 'threads_url')) {
                $table->string('threads_url')->nullable()->after('tiktok_url');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['tiktok_url', 'threads_url']);
        });
        Schema::table('order_employees', function (Blueprint $table) {
            $table->dropColumn(['tiktok_url', 'threads_url']);
        });
    }
};
