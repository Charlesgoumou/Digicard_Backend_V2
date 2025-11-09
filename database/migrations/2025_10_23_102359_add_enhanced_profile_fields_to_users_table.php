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
            // Couleurs personnalisables
            $table->string('profile_border_color', 7)->default('#facc15')->after('avatar_url'); // Couleur bordure photo (par défaut jaune)
            $table->string('save_contact_button_color', 7)->default('#ca8a04')->after('profile_border_color'); // Couleur bouton "Enregistrer Contact"
            $table->string('services_button_color', 7)->default('#0ea5e9')->after('save_contact_button_color'); // Couleur bouton "Découvrir Services"

            // Téléphones multiples (JSON array)
            $table->json('phone_numbers')->nullable()->after('services_button_color'); // Tableau de numéros de téléphone

            // Emails multiples (JSON array)
            $table->json('emails')->nullable()->after('phone_numbers'); // Tableau d'emails additionnels

            // Date importante (anniversaire)
            $table->unsignedTinyInteger('birth_day')->nullable()->after('emails'); // Jour (1-31)
            $table->unsignedTinyInteger('birth_month')->nullable()->after('birth_day'); // Mois (1-12)

            // Site Web
            $table->string('website_url')->nullable()->after('birth_month');

            // Adresse complète (détaillée)
            $table->string('address_neighborhood')->nullable()->after('website_url'); // Quartier
            $table->string('address_commune')->nullable()->after('address_neighborhood'); // Commune
            $table->string('address_city')->nullable()->after('address_commune'); // Ville
            $table->string('address_country')->nullable()->after('address_city'); // Pays
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
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
            ]);
        });
    }
};
