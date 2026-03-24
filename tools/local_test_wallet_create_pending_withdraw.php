<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$user = User::where('email', 'test-wallet@digicard.local')->first();
if (!$user) {
    echo "USER_NOT_FOUND\n";
    exit(1);
}

$currency = 'EUR';
$amountMinor = 1234;

$ref = 'wallet_withdraw_' . $user->id . '_pending_' . Str::uuid()->toString();

DB::transaction(function () use ($user, $currency, $amountMinor, $ref) {
    $wallet = Wallet::firstOrCreate(
        ['user_id' => $user->id, 'currency' => $currency],
        ['balance_minor' => 0],
    );
    $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

    $wallet->balance_minor = (int) $wallet->balance_minor - $amountMinor;
    $wallet->save();

    WalletTransaction::create([
        'wallet_id' => $wallet->id,
        'user_id' => $user->id,
        'direction' => 'debit',
        'type' => 'withdraw_external',
        'amount_minor' => $amountMinor,
        'currency' => $currency,
        'status' => 'pending',
        'external_provider' => 'chapchappay',
        'external_reference' => $ref,
        'meta' => [
            'destination' => '600000000',
            'operator' => 'mtn',
            'created_for_test' => true,
        ],
    ]);
});

echo "REFERENCE={$ref}\n";
echo "AMOUNT_MINOR={$amountMinor}\n";

