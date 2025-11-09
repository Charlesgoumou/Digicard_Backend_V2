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
            // Organigrammes / Processus
            $table->string('processes_title')->nullable()->after('products_modal_title');

            // Processus de commande
            $table->string('process_order_title')->nullable()->after('processes_title');
            $table->text('process_order_description')->nullable()->after('process_order_title');
            $table->json('process_order_steps')->nullable()->after('process_order_description');

            // Processus logistique
            $table->string('process_logistics_title')->nullable()->after('process_order_steps');
            $table->text('process_logistics_description')->nullable()->after('process_logistics_title');
            $table->json('process_logistics_steps')->nullable()->after('process_logistics_description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_pages', function (Blueprint $table) {
            $table->dropColumn([
                'processes_title',
                'process_order_title',
                'process_order_description',
                'process_order_steps',
                'process_logistics_title',
                'process_logistics_description',
                'process_logistics_steps',
            ]);
        });
    }
};
