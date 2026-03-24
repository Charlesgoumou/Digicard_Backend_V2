<?php

/**
 * Génère un token Sanctum pour un user existant.
 * Usage:
 *   php tools/local_make_user_token.php 3
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;

$userId = (int) ($argv[1] ?? 0);
if ($userId <= 0) {
    echo "Usage: php tools/local_make_user_token.php <user_id>\n";
    exit(1);
}

$user = User::find($userId);
if (!$user) {
    echo "USER_NOT_FOUND\n";
    exit(1);
}

$token = $user->createToken('local-ui-upload-test')->plainTextToken;
echo "USER_ID={$user->id}\n";
echo "EMAIL={$user->email}\n";
echo "ROLE={$user->role}\n";
echo "TOKEN={$token}\n";

