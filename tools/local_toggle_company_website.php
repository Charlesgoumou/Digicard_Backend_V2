<?php

/**
 * Toggle company_website_url for a user to simulate "no website" flow.
 * Usage:
 *   php tools/local_toggle_company_website.php 3 off
 *   php tools/local_toggle_company_website.php 3 on https://example.com
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CompanyPage;

$userId = (int) ($argv[1] ?? 0);
$mode = (string) ($argv[2] ?? '');
$url = (string) ($argv[3] ?? '');

if ($userId <= 0 || !in_array($mode, ['on', 'off'], true)) {
    echo "Usage:\n";
    echo "  php tools/local_toggle_company_website.php <user_id> off\n";
    echo "  php tools/local_toggle_company_website.php <user_id> on <url>\n";
    exit(1);
}

$cp = CompanyPage::where('user_id', $userId)->first();
if (!$cp) {
    echo "NO_COMPANY_PAGE\n";
    exit(1);
}

if ($mode === 'off') {
    $cp->company_website_url = null;
    $cp->save();
    echo "USER_ID={$userId}\n";
    echo "COMPANY_WEBSITE_URL=null\n";
    exit(0);
}

if (trim($url) === '') {
    echo "URL_REQUIRED\n";
    exit(1);
}

$cp->company_website_url = trim($url);
$cp->save();
echo "USER_ID={$userId}\n";
echo "COMPANY_WEBSITE_URL={$cp->company_website_url}\n";

