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
            $table->string('profile_name')->nullable()->after('order_avatar_url');
            $table->string('profile_title')->nullable()->after('profile_name');
            $table->string('profile_border_color', 7)->default('#facc15')->after('profile_title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['profile_name', 'profile_title', 'profile_border_color']);
        });
    }
};
