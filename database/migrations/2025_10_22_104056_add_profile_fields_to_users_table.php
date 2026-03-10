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
        // Vérifie si la table existe avant de la modifier
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                // Supprime l'ancienne colonne si elle existe
                if (Schema::hasColumn('users', 'contact_vcf_url')) {
                    $table->dropColumn('contact_vcf_url');
                }
                // Supprime l'ancienne colonne 'services_url' si elle existe (sera gérée autrement)
                 if (Schema::hasColumn('users', 'services_url')) {
                    $table->dropColumn('services_url');
                }

                // Ajoute les nouvelles colonnes (vérifie si elles existent avant pour éviter les erreurs)
                if (!Schema::hasColumn('users', 'username')) {
                    $table->string('username')->unique()->nullable()->after('email');
                }
                if (!Schema::hasColumn('users', 'title')) {
                    $table->string('title')->nullable()->after('company_name');
                }

                // Champs pour la génération de vCard
                if (!Schema::hasColumn('users', 'vcard_phone')) {
                    $table->string('vcard_phone')->nullable()->comment('Numéro de téléphone principal pour vCard');
                }
                 if (!Schema::hasColumn('users', 'vcard_email')) {
                    $table->string('vcard_email')->nullable()->comment('Email de contact pour vCard (peut différer de l\'email de connexion)');
                }
                 if (!Schema::hasColumn('users', 'vcard_company')) {
                    $table->string('vcard_company')->nullable()->comment('Nom de l\'entreprise pour vCard');
                }
                 if (!Schema::hasColumn('users', 'vcard_address')) {
                    $table->text('vcard_address')->nullable()->comment('Adresse pour vCard');
                }

                // Liens réseaux sociaux (type TEXT pour URLs potentiellement longues)
                if (!Schema::hasColumn('users', 'whatsapp_url')) {
                    $table->text('whatsapp_url')->nullable();
                }
                 if (!Schema::hasColumn('users', 'linkedin_url')) {
                    $table->text('linkedin_url')->nullable();
                }
                 if (!Schema::hasColumn('users', 'facebook_url')) {
                    $table->text('facebook_url')->nullable();
                }
                 if (!Schema::hasColumn('users', 'twitter_url')) {
                    $table->text('twitter_url')->nullable(); // Ajout Twitter (X)
                }
                 if (!Schema::hasColumn('users', 'youtube_url')) {
                    $table->text('youtube_url')->nullable();
                }
                 if (!Schema::hasColumn('users', 'deezer_url')) {
                    $table->text('deezer_url')->nullable();
                }
                 if (!Schema::hasColumn('users', 'spotify_url')) {
                    $table->text('spotify_url')->nullable();
                }
                 // Vous pouvez ajouter d'autres réseaux ici (Instagram, TikTok, etc.)
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
         if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                // Recrée les colonnes supprimées si elles n'existent pas déjà
                if (!Schema::hasColumn('users', 'contact_vcf_url')) {
                   $table->string('contact_vcf_url')->nullable(); // Recrée l'ancienne si besoin
                }
                 if (!Schema::hasColumn('users', 'services_url')) {
                   $table->string('services_url')->nullable(); // Recrée l'ancienne si besoin
                }

                // Supprime les nouvelles colonnes si elles existent
                $columnsToDrop = [
                    'username', 'title', 'vcard_phone', 'vcard_email', 'vcard_company', 'vcard_address',
                    'whatsapp_url', 'linkedin_url', 'facebook_url', 'twitter_url', 'youtube_url',
                    'deezer_url', 'spotify_url'
                ];
                // Vérifie chaque colonne avant de tenter de la supprimer
                foreach ($columnsToDrop as $column) {
                    if (Schema::hasColumn('users', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
