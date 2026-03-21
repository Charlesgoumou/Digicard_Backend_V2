<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_employees', function (Blueprint $table) {
            if (!Schema::hasColumn('order_employees', 'device_uuid')) {
                $table->string('device_uuid', 128)->nullable()->after('is_configured');
            }
            if (!Schema::hasColumn('order_employees', 'device_model')) {
                $table->string('device_model', 255)->nullable()->after('device_uuid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_employees', function (Blueprint $table) {
            if (Schema::hasColumn('order_employees', 'device_model')) {
                $table->dropColumn('device_model');
            }
            if (Schema::hasColumn('order_employees', 'device_uuid')) {
                $table->dropColumn('device_uuid');
            }
        });
    }
};
