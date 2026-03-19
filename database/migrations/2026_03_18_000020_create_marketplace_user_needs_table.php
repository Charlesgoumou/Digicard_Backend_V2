<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_user_needs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('source', 32); // perplexity_website | gemini_document
            $table->string('source_ref')->nullable(); // url ou path/nom fichier
            $table->json('keywords')->nullable();
            $table->json('needs')->nullable(); // supply_chain/partnerships/expertise/opportunities
            $table->text('last_error')->nullable();
            $table->timestamp('last_extracted_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'source']);
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_user_needs');
    }
};

