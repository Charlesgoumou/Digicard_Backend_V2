<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_employee_pointages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_employee_id')->constrained('order_employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->timestamp('check_in_time')->nullable();
            $table->decimal('check_in_lat', 10, 7)->nullable();
            $table->decimal('check_in_lng', 10, 7)->nullable();
            $table->timestamp('check_out_time')->nullable();
            $table->decimal('check_out_lat', 10, 7)->nullable();
            $table->decimal('check_out_lng', 10, 7)->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->timestamps();

            $table->unique(['order_employee_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_employee_pointages');
    }
};
