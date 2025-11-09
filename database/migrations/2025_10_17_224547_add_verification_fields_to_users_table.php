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
        Schema::table('users', function (Blueprint $table) {
            $table->string('verification_code')->nullable()->after('password');
            $table->timestamp('verification_code_expires_at')->nullable()->after('verification_code');
            // Add email_verified_at if it doesn't exist from the default user migration
            if (!Schema::hasColumn('users', 'email_verified_at')) {
                 $table->timestamp('email_verified_at')->nullable()->after('email');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['verification_code', 'verification_code_expires_at']);
             // Only drop email_verified_at if your default user migration didn't add it
             // if (Schema::hasColumn('users', 'email_verified_at')) {
             //     $table->dropColumn('email_verified_at');
             // }
        });
    }
};
