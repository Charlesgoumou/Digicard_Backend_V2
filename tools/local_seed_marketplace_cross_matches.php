<?php

/**
 * Génère des annonces "cross-match" pour tester les notifications.
 * - Prend les keywords d'un user cible (perplexity_website ou gemini_document)
 * - Crée des offres chez d'autres users en incluant 1–3 keywords dans titre/description
 *
 * Usage:
 *   php tools/local_seed_marketplace_cross_matches.php 3
 *
 * Env:
 *   CROSS_OFFERS_PER_USER=6
 *   CROSS_CURRENCY=GNF
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\MarketplaceOffer;
use App\Models\MarketplaceUserNeed;
use Illuminate\Support\Str;

$targetUserId = (int) ($argv[1] ?? 0);
if ($targetUserId <= 0) {
    echo "Usage: php tools/local_seed_marketplace_cross_matches.php <target_user_id>\n";
    exit(1);
}

$target = User::find($targetUserId);
if (!$target) {
    echo "User cible introuvable: {$targetUserId}\n";
    exit(1);
}

$currency = (string) (getenv('CROSS_CURRENCY') ?: 'GNF');
$offersPerUser = (int) (getenv('CROSS_OFFERS_PER_USER') ?: 6);
if ($offersPerUser < 1) $offersPerUser = 1;
if ($offersPerUser > 30) $offersPerUser = 30;

$need = MarketplaceUserNeed::where('user_id', $target->id)
    ->whereIn('source', ['perplexity_website', 'gemini_document'])
    ->orderByRaw("CASE WHEN source='perplexity_website' THEN 0 ELSE 1 END")
    ->first();

$keywords = $need?->keywords;
if (!is_array($keywords) || count($keywords) === 0) {
    echo "Aucun keyword trouvé pour user {$target->id}. Lance d'abord un matching (Perplexity/Gemini).\n";
    exit(0);
}

$keywords = array_values(array_unique(array_filter(array_map(function ($k) {
    $k = trim(mb_strtolower((string) $k));
    return mb_strlen($k) >= 3 ? $k : null;
}, $keywords))));
$keywords = array_slice($keywords, 0, 20);

function priceForCurrency(string $currency): float {
    $currency = strtoupper($currency);
    if (in_array($currency, ['GNF', 'XOF'], true)) {
        $min = 10000;
        $max = 800000;
        $step = 500;
        return (float) (random_int((int)($min / $step), (int)($max / $step)) * $step);
    }
    return round(10 + (mt_rand() / mt_getrandmax()) * 300, 2);
}

$others = User::query()
    ->where('id', '!=', $target->id)
    ->where('is_suspended', 0)
    ->limit(12)
    ->get();

if ($others->count() === 0) {
    echo "Aucun autre user disponible.\n";
    exit(0);
}

$created = 0;
foreach ($others as $seller) {
    for ($i = 0; $i < $offersPerUser; $i++) {
        shuffle($keywords);
        $k1 = $keywords[0] ?? 'service';
        $k2 = $keywords[1] ?? null;
        $k3 = $keywords[2] ?? null;

        $title = "[CROSS] " . ucfirst($k1) . ($k2 ? " / {$k2}" : "");
        $desc = "Annonce de test cross-match pour déclencher les alertes.\n\n"
            . "Mots-clés inclus:\n"
            . "- {$k1}\n"
            . ($k2 ? "- {$k2}\n" : "")
            . ($k3 ? "- {$k3}\n" : "")
            . "\nDétails:\n- Disponible immédiatement\n- Contact rapide\n";

        MarketplaceOffer::create([
            'user_id' => $seller->id,
            'title' => Str::limit($title, 255, ''),
            'description' => $desc,
            'type' => 'service',
            'price' => priceForCurrency($currency),
            'currency' => strtoupper($currency),
            'image_url' => null,
            'is_active' => true,
        ]);
        $created++;
    }
}

echo "TARGET_USER_ID={$target->id}\n";
echo "KEYWORDS_USED=" . count($keywords) . "\n";
echo "SELLERS=" . $others->count() . "\n";
echo "OFFERS_CREATED={$created}\n";

