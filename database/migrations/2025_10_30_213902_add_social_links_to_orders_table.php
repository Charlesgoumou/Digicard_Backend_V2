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
        Schema::table('orders', function (Blueprint $table) {
            // Boutons de couleur
            if (!Schema::hasColumn('orders', 'save_contact_button_color')) {
                $table->string('save_contact_button_color', 7)->nullable()->after('profile_border_color');
            }
            if (!Schema::hasColumn('orders', 'services_button_color')) {
                $table->string('services_button_color', 7)->nullable()->after('save_contact_button_color');
            }
            
            // Téléphones et emails (JSON)
            if (!Schema::hasColumn('orders', 'phone_numbers')) {
                $table->json('phone_numbers')->nullable()->after('services_button_color');
            }
            if (!Schema::hasColumn('orders', 'emails')) {
                $table->json('emails')->nullable()->after('phone_numbers');
            }
            
            // Date d'anniversaire
            if (!Schema::hasColumn('orders', 'birth_day')) {
                $table->integer('birth_day')->nullable()->after('emails');
            }
            if (!Schema::hasColumn('orders', 'birth_month')) {
                $table->integer('birth_month')->nullable()->after('birth_day');
            }
            
            // Site web
            if (!Schema::hasColumn('orders', 'website_url')) {
                $table->string('website_url')->nullable()->after('birth_month');
            }
            
            // Adresse
            if (!Schema::hasColumn('orders', 'address_neighborhood')) {
                $table->string('address_neighborhood')->nullable()->after('website_url');
            }
            if (!Schema::hasColumn('orders', 'address_commune')) {
                $table->string('address_commune')->nullable()->after('address_neighborhood');
            }
            if (!Schema::hasColumn('orders', 'address_city')) {
                $table->string('address_city')->nullable()->after('address_commune');
            }
            if (!Schema::hasColumn('orders', 'address_country')) {
                $table->string('address_country')->nullable()->after('address_city');
            }
            
            // Réseaux sociaux
            if (!Schema::hasColumn('orders', 'whatsapp_url')) {
                $table->text('whatsapp_url')->nullable()->after('address_country');
            }
            if (!Schema::hasColumn('orders', 'linkedin_url')) {
                $table->text('linkedin_url')->nullable()->after('whatsapp_url');
            }
            if (!Schema::hasColumn('orders', 'facebook_url')) {
                $table->text('facebook_url')->nullable()->after('linkedin_url');
            }
            if (!Schema::hasColumn('orders', 'twitter_url')) {
                $table->text('twitter_url')->nullable()->after('facebook_url');
            }
            if (!Schema::hasColumn('orders', 'youtube_url')) {
                $table->text('youtube_url')->nullable()->after('twitter_url');
            }
            if (!Schema::hasColumn('orders', 'deezer_url')) {
                $table->text('deezer_url')->nullable()->after('youtube_url');
            }
            if (!Schema::hasColumn('orders', 'spotify_url')) {
                $table->text('spotify_url')->nullable()->after('deezer_url');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'save_contact_button_color',
                'services_button_color',
                'phone_numbers',
                'emails',
                'birth_day',
                'birth_month',
                'website_url',
                'address_neighborhood',
                'address_commune',
                'address_city',
                'address_country',
                'whatsapp_url',
                'linkedin_url',
                'facebook_url',
                'twitter_url',
                'youtube_url',
                'deezer_url',
                'spotify_url',
            ]);
        });
    }
};
