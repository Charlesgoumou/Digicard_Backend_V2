<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\CompanyPage;

$users = User::where('role', 'business_admin')->where('is_suspended', 0)->get();
foreach ($users as $u) {
    $hasUserWebsite = !empty($u->website_url);
    $cp = CompanyPage::where('user_id', $u->id)->first();
    $hasCompanyWebsite = $cp && !empty($cp->company_website_url);
    if (!$hasUserWebsite && !$hasCompanyWebsite) {
        echo "USER_ID={$u->id}\n";
        echo "EMAIL={$u->email}\n";
        echo "HAS_USER_WEBSITE=0\n";
        echo "HAS_COMPANY_WEBSITE=0\n";
        exit(0);
    }
}

echo "NO_BUSINESS_WITHOUT_WEBSITE\n";

