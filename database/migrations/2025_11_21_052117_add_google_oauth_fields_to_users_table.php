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
            // Rendre password nullable pour permettre les utilisateurs Google (sans mot de passe)
            $table->string('password')->nullable()->change();
            
            // Champs Google OAuth
            $table->string('google_id')->nullable()->unique()->after('email');
            
            // Téléphone principal (obligatoire pour finaliser le profil)
            if (!Schema::hasColumn('users', 'phone')) {
                $table->string('phone')->nullable()->after('google_id');
            }
            
            // Type de compte (individual ou company)
            if (!Schema::hasColumn('users', 'account_type')) {
                $table->enum('account_type', ['individual', 'company'])->nullable()->after('phone');
            }
            
            // Nom de l'entreprise (requis si account_type = company)
            // Note: company_name existe déjà, on vérifie juste
            if (!Schema::hasColumn('users', 'company_name')) {
                $table->string('company_name')->nullable()->after('account_type');
            }
            
            // Indicateur de profil complet
            $table->boolean('is_profile_complete')->default(false)->after('company_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Remettre password en non-nullable (attention: peut causer des erreurs si des users Google existent)
            $table->string('password')->nullable(false)->change();
            
            // Supprimer les colonnes ajoutées
            $table->dropColumn([
                'google_id',
                'is_profile_complete',
            ]);
            
            // Supprimer phone et account_type seulement s'ils ont été créés par cette migration
            if (Schema::hasColumn('users', 'phone')) {
                $table->dropColumn('phone');
            }
            if (Schema::hasColumn('users', 'account_type')) {
                $table->dropColumn('account_type');
            }
        });
    }
};
