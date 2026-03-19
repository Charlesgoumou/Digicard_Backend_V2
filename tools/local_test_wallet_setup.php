<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Wallet;

$email = 'test-wallet@digicard.local';
$password = 'password';

$user = User::firstOrCreate(
    ['email' => $email],
    [
        'name' => 'Test Wallet',
        'password' => bcrypt($password),
        'email_verified_at' => now(),
        'role' => 'individual',
        'is_suspended' => 0,
    ]
);

$token = $user->createToken('wallet-test')->plainTextToken;

$wallet = Wallet::firstOrCreate(
    ['user_id' => $user->id, 'currency' => 'EUR'],
    ['balance_minor' => 0]
);

$wallet->balance_minor = 500000; // 5 000,00 EUR (minor units)
$wallet->save();

echo "USER_ID={$user->id}\n";
echo "EMAIL={$email}\n";
echo "PASSWORD={$password}\n";
echo "TOKEN={$token}\n";
echo "BALANCE_MINOR={$wallet->balance_minor}\n";

