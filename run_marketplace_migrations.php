<?php

/**
 * Script pour exécuter les migrations de la marketplace
 * 
 * Usage: php run_marketplace_migrations.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Exécution des migrations de la marketplace...\n";

try {
    Artisan::call('migrate', [
        '--path' => 'database/migrations/2025_01_20_000001_create_marketplace_offers_table.php'
    ]);
    echo "✓ Migration marketplace_offers créée\n";
    
    Artisan::call('migrate', [
        '--path' => 'database/migrations/2025_01_20_000002_create_marketplace_reviews_table.php'
    ]);
    echo "✓ Migration marketplace_reviews créée\n";
    
    Artisan::call('migrate', [
        '--path' => 'database/migrations/2025_01_20_000003_create_marketplace_favorites_table.php'
    ]);
    echo "✓ Migration marketplace_favorites créée\n";
    
    Artisan::call('migrate', [
        '--path' => 'database/migrations/2025_01_20_000004_create_marketplace_purchases_table.php'
    ]);
    echo "✓ Migration marketplace_purchases créée\n";
    
    Artisan::call('migrate', [
        '--path' => 'database/migrations/2026_01_23_000001_create_marketplace_messages_table.php'
    ]);
    echo "✓ Migration marketplace_messages créée\n";
    
    Artisan::call('migrate', [
        '--path' => 'database/migrations/2026_01_23_000002_create_marketplace_offer_images_table.php'
    ]);
    echo "✓ Migration marketplace_offer_images créée\n";
    
    echo "\n✅ Toutes les migrations de la marketplace ont été exécutées avec succès !\n";
} catch (Exception $e) {
    echo "❌ Erreur lors de l'exécution des migrations: " . $e->getMessage() . "\n";
    exit(1);
}
