<?php

/**
 * Vérifie l'état marketplace_user_needs (gemini_document) pour un user.
 * Usage:
 *   php tools/local_check_gemini_needs.php 3
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MarketplaceUserNeed;

$userId = (int) ($argv[1] ?? 0);
if ($userId <= 0) {
    echo "Usage: php tools/local_check_gemini_needs.php <user_id>\n";
    exit(1);
}

$need = MarketplaceUserNeed::where('user_id', $userId)->where('source', 'gemini_document')->first();
if (!$need) {
    echo "USER_ID={$userId}\n";
    echo "GEMINI_DOCUMENT=0\n";
    exit(0);
}

$kw = $need->keywords;
$kwCount = is_array($kw) ? count($kw) : 0;
$preview = is_array($kw) ? array_slice($kw, 0, 10) : [];

echo "USER_ID={$userId}\n";
echo "GEMINI_DOCUMENT=1\n";
echo "LAST_EXTRACTED_AT=" . ($need->last_extracted_at?->toISOString() ?? 'null') . "\n";
echo "SOURCE_REF=" . ($need->source_ref ?? 'null') . "\n";
echo "KEYWORDS_COUNT={$kwCount}\n";
echo "KEYWORDS_PREVIEW=" . json_encode($preview, JSON_UNESCAPED_UNICODE) . "\n";
echo "LAST_ERROR=" . ($need->last_error ?? 'null') . "\n";

