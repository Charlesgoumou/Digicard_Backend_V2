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
            $table->string('company_website_url', 500)->nullable()->after('company_name_short');
            $table->boolean('website_featured_in_services_button')->default(false)->after('company_website_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_pages', function (Blueprint $table) {
            $table->dropColumn(['company_website_url', 'website_featured_in_services_button']);
        });
    }
};
