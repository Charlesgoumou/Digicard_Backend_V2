<?php

/**
 * Script de test pour la configuration email
 * 
 * Usage: php test_email.php
 * 
 * Ce script teste la configuration email et l'envoi d'un email de validation de commande.
 */

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Mail;
use App\Mail\OrderValidated;

// Charger l'application Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "\n";
echo "╔═══════════════════════════════════════════════════════════════╗\n";
echo "║        🧪 Test de Configuration Email - ARCC EN CIEL         ║\n";
echo "╚═══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Vérifier la configuration
echo "📋 Vérification de la configuration email...\n";
echo "────────────────────────────────────────────────────────────────\n";

$config = [
    'MAIL_MAILER' => env('MAIL_MAILER'),
    'MAIL_HOST' => env('MAIL_HOST'),
    'MAIL_PORT' => env('MAIL_PORT'),
    'MAIL_USERNAME' => env('MAIL_USERNAME') ? '***' . substr(env('MAIL_USERNAME'), -10) : 'NON CONFIGURÉ',
    'MAIL_ENCRYPTION' => env('MAIL_ENCRYPTION'),
    'MAIL_FROM_ADDRESS' => env('MAIL_FROM_ADDRESS'),
    'MAIL_FROM_NAME' => env('MAIL_FROM_NAME'),
];

foreach ($config as $key => $value) {
    $status = $value ? '✅' : '❌';
    printf("%s %-20s : %s\n", $status, $key, $value ?: 'NON DÉFINI');
}

echo "\n";

// Vérifier si la configuration est complète
if (!env('MAIL_HOST') || !env('MAIL_USERNAME')) {
    echo "❌ ERREUR : Configuration email incomplète !\n";
    echo "\n";
    echo "Veuillez configurer les variables suivantes dans votre fichier .env :\n";
    echo "  - MAIL_MAILER=smtp\n";
    echo "  - MAIL_HOST=smtp.gmail.com\n";
    echo "  - MAIL_PORT=587\n";
    echo "  - MAIL_USERNAME=votre-email@gmail.com\n";
    echo "  - MAIL_PASSWORD=votre-mot-de-passe-application\n";
    echo "  - MAIL_ENCRYPTION=tls\n";
    echo "  - MAIL_FROM_ADDRESS=votre-email@gmail.com\n";
    echo "  - MAIL_FROM_NAME=\"ARCC EN CIEL\"\n";
    echo "\n";
    echo "📖 Consultez EMAIL_VALIDATION_GUIDE.md pour plus d'informations.\n";
    echo "\n";
    exit(1);
}

echo "✅ Configuration email semble correcte.\n";
echo "\n";

// Demander l'email de destination
echo "📧 Entrez l'adresse email pour le test : ";
$testEmail = trim(fgets(STDIN));

if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
    echo "❌ ERREUR : Adresse email invalide !\n";
    exit(1);
}

echo "\n";
echo "🚀 Tests en cours...\n";
echo "────────────────────────────────────────────────────────────────\n";
echo "\n";

// Test 1 : Email simple
echo "Test 1/2 : Envoi d'un email de test simple...\n";

try {
    Mail::raw('Ceci est un email de test depuis ARCC EN CIEL. Si vous recevez cet email, votre configuration fonctionne !', function($message) use ($testEmail) {
        $message->to($testEmail)
                ->subject('🧪 Test Email - ARCC EN CIEL');
    });
    
    echo "✅ Email de test simple envoyé avec succès !\n";
    echo "   Vérifiez votre boîte de réception (et le dossier spam).\n";
} catch (\Exception $e) {
    echo "❌ ERREUR lors de l'envoi de l'email de test :\n";
    echo "   " . $e->getMessage() . "\n";
    echo "\n";
    echo "💡 Solutions possibles :\n";
    echo "   1. Vérifiez vos identifiants MAIL_USERNAME et MAIL_PASSWORD\n";
    echo "   2. Pour Gmail, utilisez un mot de passe d'application\n";
    echo "   3. Vérifiez que le port " . env('MAIL_PORT') . " n'est pas bloqué\n";
    echo "   4. Essayez MAIL_ENCRYPTION=tls au lieu de ssl (ou vice-versa)\n";
    echo "\n";
    exit(1);
}

echo "\n";

// Test 2 : Email de validation de commande
echo "Test 2/2 : Simulation d'un email de validation de commande...\n";

try {
    // Créer un utilisateur de test
    $testUser = new \App\Models\User([
        'name' => 'Utilisateur Test',
        'email' => $testEmail,
    ]);
    
    // Créer une commande de test
    $testOrder = new \App\Models\Order([
        'order_number' => 'TEST-' . date('Ymd-His'),
        'order_type' => 'business',
        'card_quantity' => 5,
        'unit_price' => 20000,
        'total_price' => 100000,
        'annual_subscription' => 200000,
        'subscription_start_date' => now(),
        'status' => 'validated',
    ]);
    
    // Envoyer l'email de validation
    Mail::to($testEmail)->send(new OrderValidated($testOrder, $testUser));
    
    echo "✅ Email de validation de commande envoyé avec succès !\n";
    echo "   Vérifiez votre boîte de réception.\n";
    echo "\n";
    echo "📧 Détails de l'email envoyé :\n";
    echo "   • À : " . $testEmail . "\n";
    echo "   • Sujet : Confirmation de votre commande - Arcc En Ciel\n";
    echo "   • Numéro de commande : " . $testOrder->order_number . "\n";
    echo "   • Type : Entreprise\n";
    echo "   • Cartes : " . $testOrder->card_quantity . "\n";
    echo "   • Total : " . number_format($testOrder->total_price, 0, ',', ' ') . " GNF\n";
    
} catch (\Exception $e) {
    echo "❌ ERREUR lors de l'envoi de l'email de validation :\n";
    echo "   " . $e->getMessage() . "\n";
    echo "\n";
    exit(1);
}

echo "\n";
echo "╔═══════════════════════════════════════════════════════════════╗\n";
echo "║                     ✅ TESTS RÉUSSIS !                        ║\n";
echo "╚═══════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "🎉 Votre configuration email fonctionne correctement !\n";
echo "\n";
echo "📋 Prochaines étapes :\n";
echo "   1. Vérifiez que vous avez bien reçu les 2 emails\n";
echo "   2. Vérifiez le dossier spam si nécessaire\n";
echo "   3. Testez la validation d'une vraie commande depuis /admin/orders\n";
echo "   4. Consultez les logs : storage/logs/laravel.log\n";
echo "\n";
echo "📖 Guide complet : EMAIL_VALIDATION_GUIDE.md\n";
echo "\n";

