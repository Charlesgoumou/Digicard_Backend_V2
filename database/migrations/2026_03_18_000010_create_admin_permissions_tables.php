<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // ex: marketplace.offers.read
            $table->string('label')->nullable();
            $table->timestamps();
        });

        Schema::create('admin_role_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role', 64); // ex: admin, super_admin
            $table->string('permission_key'); // FK logique vers admin_permissions.key
            $table->timestamps();

            $table->unique(['role', 'permission_key']);
            $table->index(['role']);
            $table->index(['permission_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_role_permissions');
        Schema::dropIfExists('admin_permissions');
    }
};

