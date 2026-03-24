<?php

/**
 * Test local du workflow matching:
 * - Choisit un user avec website_url ou company_website_url
 * - Lance le matching 2 fois
 * - Vérifie que Perplexity n'est pas rappelé si déjà extrait (perplexity_website)
 * - Affiche quelques stats de scores
 *
 * Usage:
 *   php tools/local_test_matching_flow.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\CompanyPage;
use App\Models\MarketplaceUserNeed;
use App\Models\MarketplaceMatchScore;
use App\Jobs\ProcessMarketplaceMatching;

$user = User::query()
    ->whereNotNull('website_url')
    ->where('website_url', '!=', '')
    ->first();

if (!$user) {
    $cp = CompanyPage::query()
        ->whereNotNull('company_website_url')
        ->where('company_website_url', '!=', '')
        ->first();
    if ($cp) {
        $user = User::find($cp->user_id);
    }
}

if (!$user) {
    echo "Aucun utilisateur avec site web trouvé (website_url / company_website_url).\n";
    echo "➡️ Mets une URL sur un user (Mon profil) ou une CompanyPage (Nos services), puis relance.\n";
    exit(0);
}

echo "USER_ID={$user->id}\n";
echo "ROLE={$user->role}\n";
echo "TITLE=" . ($user->title ?? '') . "\n";
echo "WEBSITE_URL=" . ($user->website_url ?? '') . "\n";

$cp = CompanyPage::where('user_id', $user->id)->first();
echo "COMPANY_WEBSITE_URL=" . ($cp->company_website_url ?? '') . "\n";

function runMatchingFor(int $userId): void {
    if (config('queue.default') === 'sync') {
        (new ProcessMarketplaceMatching($userId))->handle();
    } else {
        // Pour test local sans worker, on exécute direct
        (new ProcessMarketplaceMatching($userId))->handle();
    }
}

// Snapshot avant
$before = MarketplaceUserNeed::where('user_id', $user->id)
    ->where('source', 'perplexity_website')
    ->first();

$beforeTs = $before?->last_extracted_at?->toISOString() ?? null;
$beforeUrl = $before?->source_ref ?? null;
$beforeKwCount = is_array($before?->keywords) ? count($before->keywords) : 0;

echo "PERPLEXITY_BEFORE last_extracted_at=" . ($beforeTs ?? 'null') . " url=" . ($beforeUrl ?? 'null') . " kw=" . $beforeKwCount . "\n";

echo "RUN_1...\n";
runMatchingFor($user->id);

$after1 = MarketplaceUserNeed::where('user_id', $user->id)
    ->where('source', 'perplexity_website')
    ->first();
$after1Ts = $after1?->last_extracted_at?->toISOString() ?? null;
$after1Url = $after1?->source_ref ?? null;
$after1KwCount = is_array($after1?->keywords) ? count($after1->keywords) : 0;
echo "PERPLEXITY_AFTER_1 last_extracted_at=" . ($after1Ts ?? 'null') . " url=" . ($after1Url ?? 'null') . " kw=" . $after1KwCount . "\n";

sleep(2);

echo "RUN_2...\n";
runMatchingFor($user->id);

$after2 = MarketplaceUserNeed::where('user_id', $user->id)
    ->where('source', 'perplexity_website')
    ->first();
$after2Ts = $after2?->last_extracted_at?->toISOString() ?? null;
$after2Url = $after2?->source_ref ?? null;
$after2KwCount = is_array($after2?->keywords) ? count($after2->keywords) : 0;
echo "PERPLEXITY_AFTER_2 last_extracted_at=" . ($after2Ts ?? 'null') . " url=" . ($after2Url ?? 'null') . " kw=" . $after2KwCount . "\n";

// Résultat: on s'attend à ce que last_extracted_at ne change pas entre RUN_1 et RUN_2 si URL identique et kw non vides
if ($after1Ts && $after2Ts && $after1Ts === $after2Ts) {
    echo "OK_PERPLEXITY_SINGLE_CALL=1 (timestamp inchangé entre run1 et run2)\n";
} else {
    echo "WARN_PERPLEXITY_SINGLE_CALL=0 (timestamp a changé ou manque)\n";
}

// Stats scores
$scoresCount = MarketplaceMatchScore::where('user_id', $user->id)->count();
echo "MATCH_SCORES_COUNT={$scoresCount}\n";

$notified = MarketplaceMatchScore::where('user_id', $user->id)->where('notified', true)->count();
echo "MATCH_SCORES_NOTIFIED_COUNT={$notified}\n";

$top = MarketplaceMatchScore::where('user_id', $user->id)
    ->orderByDesc('match_score')
    ->limit(5)
    ->get(['offer_id', 'match_score']);

echo "TOP_5_SCORES:\n";
foreach ($top as $row) {
    echo "- offer_id={$row->offer_id} score={$row->match_score}\n";
}

$topOne = MarketplaceMatchScore::where('user_id', $user->id)
    ->orderByDesc('match_score')
    ->first();

if ($topOne) {
    $details = $topOne->match_details;
    $km = is_array($details) ? ($details['keyword_matches_count'] ?? null) : null;
    echo "TOP_1_KEYWORD_MATCHES_COUNT=" . ($km === null ? 'null' : (string) $km) . "\n";
}

