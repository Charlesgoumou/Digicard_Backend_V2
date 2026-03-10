<?php
/**
 * Script de diagnostic des variables d'environnement
 * Exécuter avec: php check_env.php
 */

// Charger les variables d'environnement
require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

echo "\n";
echo "╔═══════════════════════════════════════════════════════════╗\n";
echo "║     DIAGNOSTIC DES VARIABLES D'ENVIRONNEMENT              ║\n";
echo "╚═══════════════════════════════════════════════════════════╝\n";
echo "\n";

// Fonction pour vérifier une variable
function checkEnvVar($name, $description, $required = true) {
    $value = env($name);
    $isEmpty = empty($value);
    
    $status = $isEmpty ? '✗ MANQUANT' : '✓ CONFIGURÉ';
    $color = $isEmpty ? "\033[31m" : "\033[32m"; // Rouge ou Vert
    $reset = "\033[0m";
    
    echo sprintf(
        "%-30s : %s%s%s\n",
        $description,
        $color,
        $status,
        $reset
    );
    
    if (!$isEmpty && strlen($value) < 50) {
        echo sprintf("%-30s   Valeur: %s\n", '', $value);
    } elseif (!$isEmpty) {
        echo sprintf("%-30s   Valeur: %s...\n", '', substr($value, 0, 30));
    }
    
    return !$isEmpty;
}

// ============================================
// 1. CONFIGURATION APPLICATION
// ============================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "📦 CONFIGURATION APPLICATION\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$appKeyOk = checkEnvVar('APP_KEY', 'Clé Application', true);
$appEnvOk = checkEnvVar('APP_ENV', 'Environnement', true);
$appDebugOk = checkEnvVar('APP_DEBUG', 'Mode Debug', true);
$appUrlOk = checkEnvVar('APP_URL', 'URL Application', true);
echo "\n";

// ============================================
// 2. CONFIGURATION BASE DE DONNÉES
// ============================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🗄️  CONFIGURATION BASE DE DONNÉES\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$dbConnectionOk = checkEnvVar('DB_CONNECTION', 'Type de BDD', true);
$dbHostOk = checkEnvVar('DB_HOST', 'Hôte BDD', true);
$dbPortOk = checkEnvVar('DB_PORT', 'Port BDD', true);
$dbDatabaseOk = checkEnvVar('DB_DATABASE', 'Nom de la BDD', true);
$dbUsernameOk = checkEnvVar('DB_USERNAME', 'Utilisateur BDD', true);
checkEnvVar('DB_PASSWORD', 'Mot de passe BDD', false);
echo "\n";

// ============================================
// 3. CONFIGURATION EMAIL
// ============================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "📧 CONFIGURATION EMAIL\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$mailMailerOk = checkEnvVar('MAIL_MAILER', 'Mailer', true);
$mailHostOk = checkEnvVar('MAIL_HOST', 'Hôte SMTP', true);
$mailPortOk = checkEnvVar('MAIL_PORT', 'Port SMTP', true);
$mailUsernameOk = checkEnvVar('MAIL_USERNAME', 'Username SMTP', true);
$mailPasswordOk = checkEnvVar('MAIL_PASSWORD', 'Password SMTP', true);
$mailEncryptionOk = checkEnvVar('MAIL_ENCRYPTION', 'Encryption', false);
$mailFromAddressOk = checkEnvVar('MAIL_FROM_ADDRESS', 'Email expéditeur', true);
$mailFromNameOk = checkEnvVar('MAIL_FROM_NAME', 'Nom expéditeur', false);

$mailConfigured = $mailHostOk && $mailUsernameOk && $mailPasswordOk && $mailFromAddressOk;
echo "\n";

// ============================================
// 4. CONFIGURATION GEMINI AI
// ============================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🤖 CONFIGURATION GEMINI AI\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$geminiApiKeyOk = checkEnvVar('GEMINI_API_KEY', 'Clé API Gemini', false);
echo "\n";

// ============================================
// RÉSUMÉ
// ============================================
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "📊 RÉSUMÉ\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$criticalIssues = 0;
$warnings = 0;

// Vérifications critiques
if (!$appKeyOk) {
    echo "🔴 CRITIQUE : APP_KEY manquante. Exécutez: php artisan key:generate\n";
    $criticalIssues++;
}

if (!$dbDatabaseOk || !$dbHostOk) {
    echo "🔴 CRITIQUE : Configuration base de données incomplète\n";
    $criticalIssues++;
}

// Avertissements
if (!$mailConfigured) {
    echo "🟡 AVERTISSEMENT : Configuration email incomplète\n";
    echo "   → Les utilisateurs ne pourront pas vérifier leurs emails\n";
    echo "   → Consultez CONFIG_ENV_GUIDE.md pour configurer l'email\n";
    $warnings++;
}

if (!$geminiApiKeyOk) {
    echo "🟡 AVERTISSEMENT : Gemini AI non configuré\n";
    echo "   → La génération automatique de contenu ne fonctionnera pas\n";
    echo "   → Cette fonctionnalité est optionnelle\n";
    $warnings++;
}

echo "\n";

if ($criticalIssues === 0 && $warnings === 0) {
    echo "✅ \033[32mToutes les configurations sont OK !\033[0m\n";
} elseif ($criticalIssues === 0) {
    echo "⚠️  \033[33mConfiguration fonctionnelle mais avec {$warnings} avertissement(s)\033[0m\n";
} else {
    echo "❌ \033[31m{$criticalIssues} problème(s) critique(s) détecté(s)\033[0m\n";
}

echo "\n";
echo "💡 Pour plus d'informations, consultez CONFIG_ENV_GUIDE.md\n";
echo "\n";

// Test de connexion à la base de données
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🔌 TEST DE CONNEXION\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

if ($dbDatabaseOk) {
    try {
        // Bootstrap Laravel
        $app = require_once __DIR__ . '/bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();
        
        // Tester la connexion
        $pdo = DB::connection()->getPdo();
        echo "✅ \033[32mConnexion à la base de données: OK\033[0m\n";
        
        // Obtenir des statistiques
        $userCount = DB::table('users')->count();
        $orderCount = DB::table('orders')->count();
        echo "   → Utilisateurs: {$userCount}\n";
        echo "   → Commandes: {$orderCount}\n";
        
    } catch (Exception $e) {
        echo "❌ \033[31mConnexion à la base de données: ÉCHEC\033[0m\n";
        echo "   Erreur: " . $e->getMessage() . "\n";
    }
} else {
    echo "⚠️  Configuration BDD manquante, test de connexion ignoré\n";
}

echo "\n";

