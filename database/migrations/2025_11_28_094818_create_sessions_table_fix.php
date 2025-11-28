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
    // On vérifie d'abord si la table existe pour éviter une erreur
    if (!Schema::hasTable('sessions')) {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();

            // ATTENTION ICI :
            // Si vos utilisateurs ont des IDs classiques (1, 2, 3...), laissez cette ligne :
            $table->foreignId('user_id')->nullable()->index();

            // SI (et seulement si) vos utilisateurs ont des UUID (ex: 9a2b-3c...),
            // Mettez cette ligne à la place de la précédente :
            // $table->string('user_id')->nullable()->index();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions_table_fix');
    }
};
