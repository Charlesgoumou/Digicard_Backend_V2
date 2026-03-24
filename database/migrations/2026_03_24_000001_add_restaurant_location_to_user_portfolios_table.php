<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_portfolios', function (Blueprint $table) {
            $table->string('restaurant_location', 512)->nullable()->after('hero_headline');
        });
    }

    public function down(): void
    {
        Schema::table('user_portfolios', function (Blueprint $table) {
            $table->dropColumn('restaurant_location');
        });
    }
};
