<?php

/**
 * Test "workflow Gemini" pour Enterprise + Particulier:
 * - Utilise une image locale (screenshot) pour Gemini Vision -> texte
 * - Utilise Gemini (texte) -> marketplace needs (keywords)
 * - Stocke marketplace_user_needs (source=gemini_document)
 * - Lance ProcessMarketplaceMatching
 *
 * Usage:
 *   php tools/local_test_gemini_workflow.php
 *
 * Notes:
 * - Nécessite GEMINI_API_KEY configurée (config('gemini.api_key'))
 * - Ce test n'utilise PAS Perplexity (c'est volontaire)
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\CompanyPage;
use App\Models\MarketplaceUserNeed;
use App\Models\MarketplaceMatchScore;
use App\Services\GeminiService;
use App\Jobs\ProcessMarketplaceMatching;
use Illuminate\Support\Facades\Storage;

$imagePath = 'C:\\Users\\PC\\.cursor\\projects\\c-Users-PC-Desktop-Digicard-project\\assets\\c__Users_PC_AppData_Roaming_Cursor_User_workspaceStorage_38a49410957dd940c9d4d41209b56c10_images_image-d40550d0-3cf5-4cd2-881c-1abafb5df1ae.png';
if (!file_exists($imagePath)) {
    echo "Image introuvable: {$imagePath}\n";
    exit(1);
}

$gemini = new GeminiService();

if (empty(config('gemini.api_key'))) {
    echo "GEMINI_API_KEY manquante (config('gemini.api_key') vide).\n";
    exit(1);
}

function runMatching(int $userId): void {
    // Exécution directe pour test local
    (new ProcessMarketplaceMatching($userId))->handle();
}

function storeNeeds(int $userId, string $sourceRef, array $needsData): void {
    $keywords = $needsData['keywords'] ?? [];
    if (!is_array($keywords)) $keywords = [];
    $keywords = array_values(array_unique(array_filter(array_map(function ($k) {
        $k = trim(mb_strtolower((string) $k));
        return mb_strlen($k) >= 3 ? $k : null;
    }, $keywords))));

    MarketplaceUserNeed::updateOrCreate(
        ['user_id' => $userId, 'source' => 'gemini_document'],
        [
            'source_ref' => $sourceRef,
            'keywords' => $keywords,
            'needs' => $needsData['needs'] ?? null,
            'last_error' => empty($keywords) ? 'Gemini: aucun keyword retourné (document)' : null,
            'last_extracted_at' => now(),
        ]
    );
}

// 1) Trouver un business_admin (on utilise id=3 si dispo)
$biz = User::where('role', 'business_admin')->where('is_suspended', 0)->orderBy('id')->first();
if (!$biz) {
    echo "Aucun business_admin trouvé.\n";
    exit(0);
}

// 2) Trouver un individual
$ind = User::where('role', 'individual')->where('is_suspended', 0)->orderBy('id')->first();
if (!$ind) {
    echo "Aucun individual trouvé.\n";
    exit(0);
}

echo "BIZ_USER_ID={$biz->id}\n";
echo "IND_USER_ID={$ind->id}\n";

// Extraire texte depuis image (Vision)
$imgData = base64_encode(file_get_contents($imagePath));
$mime = 'image/png';
$text = $gemini->extractTextFromImage($imgData, $mime);
echo "VISION_TEXT_LEN=" . strlen($text) . "\n";

if (strlen($text) < 20) {
    echo "Texte Gemini Vision trop court (possible quota/erreur). Stop.\n";
    exit(1);
}

// Enterprise: simuler "pas de site" (on n'altère pas l'UI, juste le test)
// -> on met company_website_url à null temporairement si présent, puis restore
$cp = CompanyPage::where('user_id', $biz->id)->first();
$restoreCompanyUrl = null;
if ($cp) {
    $restoreCompanyUrl = $cp->company_website_url;
    $cp->company_website_url = null;
    $cp->save();
}

$bizNeeds = $gemini->extractMarketplaceNeedsFromText($text, $biz->title ?? null) ?? null;
if (!$bizNeeds) {
    echo "BIZ_NEEDS_NULL (Gemini)\n";
} else {
    storeNeeds($biz->id, basename($imagePath), $bizNeeds);
    $kwCount = is_array($bizNeeds['keywords'] ?? null) ? count($bizNeeds['keywords']) : 0;
    echo "BIZ_KEYWORDS_COUNT={$kwCount}\n";
    runMatching($biz->id);
    $scores = MarketplaceMatchScore::where('user_id', $biz->id)->count();
    echo "BIZ_MATCH_SCORES_COUNT={$scores}\n";
}

// Restore company url
if ($cp) {
    $cp->company_website_url = $restoreCompanyUrl;
    $cp->save();
}

// Particulier: s'appuie sur gemini_document + title
$indNeeds = $gemini->extractMarketplaceNeedsFromText($text, $ind->title ?? null) ?? null;
if (!$indNeeds) {
    echo "IND_NEEDS_NULL (Gemini)\n";
} else {
    storeNeeds($ind->id, basename($imagePath), $indNeeds);
    $kwCount = is_array($indNeeds['keywords'] ?? null) ? count($indNeeds['keywords']) : 0;
    echo "IND_KEYWORDS_COUNT={$kwCount}\n";
    runMatching($ind->id);
    $scores = MarketplaceMatchScore::where('user_id', $ind->id)->count();
    echo "IND_MATCH_SCORES_COUNT={$scores}\n";
}

echo "DONE\n";

