<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;

$user = User::where('email', 'test-wallet@digicard.local')->first();
if (!$user) {
    echo "USER_NOT_FOUND\n";
    exit(1);
}

$wallet = Wallet::where('user_id', $user->id)->where('currency', 'EUR')->first();
echo "USER_ID={$user->id}\n";
echo "BALANCE_MINOR=" . ($wallet ? $wallet->balance_minor : 'null') . "\n";

$txs = WalletTransaction::where('user_id', $user->id)->orderByDesc('id')->limit(10)->get();
foreach ($txs as $tx) {
    echo json_encode([
        'id' => $tx->id,
        'type' => $tx->type,
        'direction' => $tx->direction,
        'status' => $tx->status,
        'amount_minor' => (int) $tx->amount_minor,
        'external_provider' => $tx->external_provider,
        'external_reference' => $tx->external_reference,
        'meta' => $tx->meta,
        'created_at' => (string) $tx->created_at,
    ], JSON_UNESCAPED_SLASHES) . "\n";
}

