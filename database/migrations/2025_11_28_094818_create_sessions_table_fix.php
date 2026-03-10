<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. ON SUPPRIME L'ANCIENNE TABLE (Force le nettoyage)
        Schema::dropIfExists('sessions');

        // 2. ON LA RECRÉE PROPREMENT
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();

            // Pour vos IDs classiques (1, 2, 3...)
            $table->foreignId('user_id')->nullable()->index();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        // Correction du nom ici aussi
        Schema::dropIfExists('sessions');
    }
};
