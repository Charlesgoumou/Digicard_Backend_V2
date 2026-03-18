<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_offers', function (Blueprint $table) {
            $table->string('category', 48)->nullable()->after('type');
            $table->index(['category', 'is_active']);
        });
        DB::table('marketplace_offers')->whereNull('category')->update(['category' => 'autre']);
    }

    public function down(): void
    {
        Schema::table('marketplace_offers', function (Blueprint $table) {
            $table->dropIndex(['category', 'is_active']);
            $table->dropColumn('category');
        });
    }
};
