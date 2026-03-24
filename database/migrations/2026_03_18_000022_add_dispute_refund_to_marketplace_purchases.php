<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_purchases', function (Blueprint $table) {
            $table->timestamp('buyer_disputed_at')->nullable()->after('buyer_confirmed_at');
            $table->text('dispute_reason')->nullable()->after('buyer_disputed_at');
            $table->timestamp('admin_decided_at')->nullable()->after('dispute_reason');
            $table->timestamp('refund_processed_at')->nullable()->after('admin_decided_at');
            $table->text('refund_reason')->nullable()->after('refund_processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_purchases', function (Blueprint $table) {
            $table->dropColumn([
                'buyer_disputed_at',
                'dispute_reason',
                'admin_decided_at',
                'refund_processed_at',
                'refund_reason',
            ]);
        });
    }
};

