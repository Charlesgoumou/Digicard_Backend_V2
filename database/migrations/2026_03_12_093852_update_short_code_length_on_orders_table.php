<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Adapter la longueur de short_code à 8 caractères sans nécessiter doctrine/dbal.
        DB::statement('ALTER TABLE orders MODIFY short_code VARCHAR(8) NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revenir à 6 caractères en cas de rollback.
        DB::statement('ALTER TABLE orders MODIFY short_code VARCHAR(6) NULL');
    }
};
