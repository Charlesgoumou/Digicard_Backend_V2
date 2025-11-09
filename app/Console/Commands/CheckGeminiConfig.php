<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckGeminiConfig extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gemini:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Vérifie la configuration de l\'API Google Gemini';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('========================================');
        $this->info('  VÉRIFICATION CONFIGURATION GEMINI');
        $this->info('========================================');
        $this->newLine();

        // Vérifier la clé API
        $apiKey = config('gemini.api_key');
        if (empty($apiKey)) {
            $this->error('❌ Clé API non définie !');
            $this->warn('   Ajoutez GEMINI_API_KEY dans votre fichier .env');
        } else {
            $maskedKey = substr($apiKey, 0, 10) . '...' . substr($apiKey, -4);
            $this->info('✅ Clé API définie : ' . $maskedKey);
        }

        $this->newLine();

        // Vérifier l'URL de l'API
        $apiUrl = config('gemini.api_url');
        if (empty($apiUrl)) {
            $this->error('❌ URL de l\'API non définie !');
            $this->warn('   Ajoutez GEMINI_API_URL dans votre fichier .env');
        } else {
            $this->info('✅ URL de l\'API définie :');
            $this->line('   ' . $apiUrl);

            // Vérifier que l'URL est complète
            if (!str_contains($apiUrl, ':generateContent')) {
                $this->error('⚠️  ATTENTION : L\'URL semble incomplète !');
                $this->warn('   Elle devrait se terminer par ":generateContent"');
                $this->line('   URL attendue : https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent');
            }

            // Vérifier le modèle
            if (str_contains($apiUrl, 'gemini-2.5-flash')) {
                $this->info('✅ Modèle : gemini-2.5-flash (recommandé)');
            } elseif (str_contains($apiUrl, 'gemini-1.5-flash')) {
                $this->warn('⚠️  Modèle : gemini-1.5-flash (ancienne version)');
                $this->warn('   Mettez à jour vers gemini-2.5-flash');
            } else {
                $this->warn('⚠️  Modèle non reconnu dans l\'URL');
            }
        }

        $this->newLine();
        $this->info('========================================');

        if (empty($apiKey) || empty($apiUrl)) {
            $this->newLine();
            $this->warn('Configuration incomplète !');
            $this->line('Consultez le fichier CONFIGURATION_GEMINI.txt pour les instructions.');
            return 1;
        }

        if (!str_contains($apiUrl, ':generateContent')) {
            $this->newLine();
            $this->error('L\'URL de l\'API est incorrecte !');
            $this->line('Corrigez GEMINI_API_URL dans votre .env puis lancez : php artisan config:clear');
            return 1;
        }

        $this->newLine();
        $this->info('✅ Configuration Gemini OK !');
        return 0;
    }
}
