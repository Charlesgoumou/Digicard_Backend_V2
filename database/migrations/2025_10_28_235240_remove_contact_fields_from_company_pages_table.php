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
            $table->dropColumn(['contact_name', 'contact_address', 'contact_phones']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_pages', function (Blueprint $table) {
            $table->string('contact_name')->nullable()->after('products_modal_title');
            $table->text('contact_address')->nullable()->after('contact_name');
            $table->string('contact_phones')->nullable()->after('contact_address');
        });
    }
};
