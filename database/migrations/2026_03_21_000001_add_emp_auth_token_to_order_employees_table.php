<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('order_employees', 'emp_auth_token')) {
            Schema::table('order_employees', function (Blueprint $table) {
                $table->string('emp_auth_token', 128)->nullable()->after('device_model');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_employees', 'emp_auth_token')) {
            Schema::table('order_employees', function (Blueprint $table) {
                $table->dropColumn('emp_auth_token');
            });
        }
    }
};
