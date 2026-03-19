<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_employees', function (Blueprint $table) {
            if (!Schema::hasColumn('order_employees', 'employee_matricule')) {
                $table->string('employee_matricule')->nullable()->after('employee_name');
            }
            if (!Schema::hasColumn('order_employees', 'employee_department')) {
                $table->string('employee_department')->nullable()->after('employee_matricule');
            }
            if (!Schema::hasColumn('order_employees', 'employee_group')) {
                $table->string('employee_group')->nullable()->after('employee_department');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_employees', function (Blueprint $table) {
            if (Schema::hasColumn('order_employees', 'employee_group')) {
                $table->dropColumn('employee_group');
            }
            if (Schema::hasColumn('order_employees', 'employee_department')) {
                $table->dropColumn('employee_department');
            }
            if (Schema::hasColumn('order_employees', 'employee_matricule')) {
                $table->dropColumn('employee_matricule');
            }
        });
    }
};

