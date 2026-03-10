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
        Schema::table('order_employees', function (Blueprint $table) {
            // Champs de profil pour chaque employé
            $table->string('profile_name')->nullable()->after('is_configured');
            $table->string('profile_title')->nullable()->after('profile_name');
            $table->string('employee_avatar_url')->nullable()->after('profile_title');
            $table->string('profile_border_color', 7)->default('#facc15')->after('employee_avatar_url');
            $table->string('save_contact_button_color', 7)->default('#ca8a04')->after('profile_border_color');
            $table->string('services_button_color', 7)->default('#0ea5e9')->after('save_contact_button_color');

            // Informations de contact multiples
            $table->json('phone_numbers')->nullable()->after('services_button_color');
            $table->json('emails')->nullable()->after('phone_numbers');

            // Date importante
            $table->unsignedTinyInteger('birth_day')->nullable()->after('emails');
            $table->unsignedTinyInteger('birth_month')->nullable()->after('birth_day');

            // Site web
            $table->string('website_url')->nullable()->after('birth_month');

            // Adresse
            $table->string('address_neighborhood')->nullable()->after('website_url');
            $table->string('address_commune')->nullable()->after('address_neighborhood');
            $table->string('address_city')->nullable()->after('address_commune');
            $table->string('address_country')->nullable()->after('address_city');

            // Réseaux sociaux
            $table->string('whatsapp_url')->nullable()->after('address_country');
            $table->string('linkedin_url')->nullable()->after('whatsapp_url');
            $table->string('facebook_url')->nullable()->after('linkedin_url');
            $table->string('twitter_url')->nullable()->after('facebook_url');
            $table->string('youtube_url')->nullable()->after('twitter_url');
            $table->string('deezer_url')->nullable()->after('youtube_url');
            $table->string('spotify_url')->nullable()->after('deezer_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_employees', function (Blueprint $table) {
            $table->dropColumn([
                'profile_name',
                'profile_title',
                'employee_avatar_url',
                'profile_border_color',
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
