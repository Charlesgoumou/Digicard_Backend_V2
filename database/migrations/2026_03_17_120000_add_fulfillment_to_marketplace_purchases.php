<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_purchases', function (Blueprint $table) {
            $table->string('fulfillment_status', 32)->default('pending')->after('status');
            $table->timestamp('seller_fulfilled_at')->nullable()->after('fulfillment_status');
            $table->timestamp('buyer_confirmed_at')->nullable()->after('seller_fulfilled_at');
        });

        // Achats déjà finalisés avant ce flux : considérés comme clôturés côté livraison
        DB::table('marketplace_purchases')
            ->where('status', 'completed')
            ->update([
                'fulfillment_status' => 'completed',
                'seller_fulfilled_at' => DB::raw('created_at'),
                'buyer_confirmed_at' => DB::raw('created_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('marketplace_purchases', function (Blueprint $table) {
            $table->dropColumn(['fulfillment_status', 'seller_fulfilled_at', 'buyer_confirmed_at']);
        });
    }
};
