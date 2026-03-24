<?php

/**
 * Reset le flag "notified" sur marketplace_match_scores pour un user,
 * afin de rejouer l'envoi des notifications.
 *
 * Usage:
 *   php tools/local_reset_user_match_notified.php 3
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MarketplaceMatchScore;

$userId = (int) ($argv[1] ?? 0);
if ($userId <= 0) {
    echo "Usage: php tools/local_reset_user_match_notified.php <user_id>\n";
    exit(1);
}

$count = MarketplaceMatchScore::where('user_id', $userId)->where('notified', true)->count();
MarketplaceMatchScore::where('user_id', $userId)->update([
    'notified' => false,
    'notified_at' => null,
]);

echo "USER_ID={$userId}\n";
echo "RESET_NOTIFIED_PREVIOUSLY_TRUE={$count}\n";

