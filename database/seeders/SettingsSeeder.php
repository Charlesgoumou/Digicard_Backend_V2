<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;

class SettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            // Tarification
            [
                'key' => 'card_price',
                'value' => '180000', // Prix de la première carte
            ],
            [
                'key' => 'additional_card_price',
                'value' => '45000', // Prix des cartes additionnelles
            ],
            [
                'key' => 'subscription_price',
                'value' => '40000',
            ],

            // Limites
            [
                'key' => 'max_cards_per_order',
                'value' => '100',
            ],
            [
                'key' => 'max_employees_per_order',
                'value' => '100',
            ],

            // Informations du site
            [
                'key' => 'site_name',
                'value' => 'Digicard',
            ],
            [
                'key' => 'support_email',
                'value' => 'support@digicard.com',
            ],

            // Paramètres de sécurité
            [
                'key' => 'allow_registration',
                'value' => 'true',
            ],
            [
                'key' => 'require_email_verification',
                'value' => 'true',
            ],

            // Paramètres de contenu
            [
                'key' => 'welcome_message',
                'value' => 'Bienvenue sur Digicard - Votre solution de cartes de visite digitales',
            ],
            [
                'key' => 'footer_text',
                'value' => '© 2025 Digicard. Tous droits réservés.',
            ],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                ['value' => $setting['value']]
            );
        }

        $this->command->info('Paramètres par défaut créés avec succès!');
    }
}
