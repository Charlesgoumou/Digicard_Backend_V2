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
            $table->string('save_contact_button_color', 7)->nullable()->after('profile_border_color');
            $table->string('services_button_color', 7)->nullable()->after('save_contact_button_color');
            
            // Téléphones et emails (JSON)
            $table->json('phone_numbers')->nullable()->after('services_button_color');
            $table->json('emails')->nullable()->after('phone_numbers');
            
            // Date d'anniversaire
            $table->integer('birth_day')->nullable()->after('emails');
            $table->integer('birth_month')->nullable()->after('birth_day');
            
            // Site web
            $table->string('website_url')->nullable()->after('birth_month');
            
            // Adresse
            $table->string('address_neighborhood')->nullable()->after('website_url');
            $table->string('address_commune')->nullable()->after('address_neighborhood');
            $table->string('address_city')->nullable()->after('address_commune');
            $table->string('address_country')->nullable()->after('address_city');
            
            // Réseaux sociaux
            $table->text('whatsapp_url')->nullable()->after('address_country');
            $table->text('linkedin_url')->nullable()->after('whatsapp_url');
            $table->text('facebook_url')->nullable()->after('linkedin_url');
            $table->text('twitter_url')->nullable()->after('facebook_url');
            $table->text('youtube_url')->nullable()->after('twitter_url');
            $table->text('deezer_url')->nullable()->after('youtube_url');
            $table->text('spotify_url')->nullable()->after('deezer_url');
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
