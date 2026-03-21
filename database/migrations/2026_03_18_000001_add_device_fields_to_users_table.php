<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'device_uuid')) {
                $table->string('device_uuid')->nullable()->after('remember_token');
            }
            if (!Schema::hasColumn('users', 'device_label')) {
                $table->string('device_label')->nullable()->after('device_uuid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'device_label')) {
                $table->dropColumn('device_label');
            }
            if (Schema::hasColumn('users', 'device_uuid')) {
                $table->dropColumn('device_uuid');
            }
        });
    }
};

