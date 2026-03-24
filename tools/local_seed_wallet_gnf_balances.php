<?php

/**
 * Donne un solde GNF à tous les utilisateurs (tests internes Marketplace).
 *
 * Usage:
 *   php tools/local_seed_wallet_gnf_balances.php
 *
 * Env (optionnel):
 *   SEED_WALLET_GNF_AMOUNT=2000000
 *   SEED_WALLET_ONLY_ACTIVE=1
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Wallet;

$amount = (int) (getenv('SEED_WALLET_GNF_AMOUNT') ?: 2000000);
$onlyActive = (string) (getenv('SEED_WALLET_ONLY_ACTIVE') ?: '1');
$onlyActive = $onlyActive !== '0';

if ($amount < 0) $amount = 0;

$query = User::query();
if ($onlyActive) {
    $query->where('is_suspended', 0);
}

$users = $query->get();
if ($users->count() === 0) {
    echo "USERS=0\n";
    exit(0);
}

$created = 0;
$updated = 0;

foreach ($users as $user) {
    $wallet = Wallet::firstOrCreate(
        ['user_id' => $user->id, 'currency' => 'GNF'],
        ['balance_minor' => 0]
    );

    if ($wallet->wasRecentlyCreated) {
        $created++;
    }

    $wallet->balance_minor = $amount; // GNF: factor 1
    $wallet->save();
    $updated++;
}

echo "USERS={$users->count()}\n";
echo "WALLETS_CREATED={$created}\n";
echo "WALLETS_UPDATED={$updated}\n";
echo "BALANCE_MINOR_SET={$amount}\n";

