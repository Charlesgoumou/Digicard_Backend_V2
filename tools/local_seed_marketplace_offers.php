<?php

/**
 * Seed Marketplace avec beaucoup d'annonces réalistes basées sur les profils existants.
 *
 * Usage (PowerShell):
 *   php tools/local_seed_marketplace_offers.php
 *
 * Options via env:
 *   SEED_OFFERS_PER_USER=12
 *   SEED_REVIEWS_MAX_PER_OFFER=6
 *   SEED_CURRENCY=GNF
 *   SEED_ONLY_ROLES=individual,business_admin
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\CompanyPage;
use App\Models\UserPortfolio;
use App\Models\MarketplaceOffer;
use App\Models\MarketplaceReview;
use Illuminate\Support\Str;

$offersPerUser = (int) (getenv('SEED_OFFERS_PER_USER') ?: 12);
$maxReviewsPerOffer = (int) (getenv('SEED_REVIEWS_MAX_PER_OFFER') ?: 6);
$currency = (string) (getenv('SEED_CURRENCY') ?: 'GNF');
$rolesCsv = (string) (getenv('SEED_ONLY_ROLES') ?: 'individual,business_admin');
$allowedRoles = array_values(array_filter(array_map('trim', explode(',', $rolesCsv))));

if ($offersPerUser < 1) $offersPerUser = 1;
if ($offersPerUser > 50) $offersPerUser = 50;
if ($maxReviewsPerOffer < 0) $maxReviewsPerOffer = 0;
if ($maxReviewsPerOffer > 20) $maxReviewsPerOffer = 20;

// Prix: GNF sans décimales côté usage, mais la colonne est decimal(10,2).
function priceForCurrency(string $currency): float {
    $currency = strtoupper($currency);
    if (in_array($currency, ['GNF', 'XOF'], true)) {
        // 5 000 -> 1 500 000
        $min = 5000;
        $max = 1500000;
        $step = 500;
        $v = random_int((int)($min / $step), (int)($max / $step)) * $step;
        return (float) $v;
    }
    // EUR ou autres
    $min = 5;
    $max = 900;
    return round($min + (mt_rand() / mt_getrandmax()) * ($max - $min), 2);
}

function pickType(): string {
    $r = random_int(1, 100);
    if ($r <= 55) return 'product';
    if ($r <= 90) return 'service';
    return 'offer';
}

function normalizeKeywords(array $kw): array {
    $out = [];
    foreach ($kw as $k) {
        $k = trim(mb_strtolower((string) $k));
        if ($k === '') continue;
        $k = preg_replace('/\s+/', ' ', $k);
        $out[] = $k;
    }
    $out = array_values(array_unique($out));
    return array_slice($out, 0, 12);
}

function buildOfferFromKeywords(string $type, array $keywords, string $fallbackTitle): array {
    $kw = $keywords;
    shuffle($kw);
    $primary = $kw[0] ?? $fallbackTitle;
    $secondary = $kw[1] ?? null;

    $templates = [
        'product' => [
            "Vente: {$primary}" . ($secondary ? " ({$secondary})" : ""),
            "Stock disponible: {$primary}" . ($secondary ? " - {$secondary}" : ""),
            "{$primary} - livraison possible",
        ],
        'service' => [
            "Service: {$primary}" . ($secondary ? " / {$secondary}" : ""),
            "Prestation: {$primary}" . ($secondary ? " ({$secondary})" : ""),
            "Accompagnement {$primary}" . ($secondary ? " - {$secondary}" : ""),
        ],
        'offer' => [
            "Offre spéciale: {$primary}",
            "Recherche/Proposition: {$primary}",
            "{$primary} - opportunité",
        ],
    ];

    $title = $templates[$type][array_rand($templates[$type])] ?? ("Annonce: {$primary}");

    $bullets = [];
    foreach (array_slice($kw, 0, 6) as $k) {
        $bullets[] = "- {$k}";
    }

    $desc = "Annonce générée pour test matching.\n\n"
        . "Mots-clés:\n" . implode("\n", $bullets) . "\n\n"
        . "Détails:\n"
        . "- Qualité vérifiée\n"
        . "- Réponse rapide\n"
        . "- Prix négociable selon quantité\n";

    return [$title, $desc];
}

// Récupérer users
$users = User::query()
    ->whereIn('role', $allowedRoles)
    ->where('is_suspended', 0)
    ->get();

if ($users->count() === 0) {
    echo "Aucun utilisateur trouvé pour roles: " . implode(',', $allowedRoles) . "\n";
    exit(0);
}

// Préparer une liste de reviewers (tous users actifs)
$reviewers = User::query()->where('is_suspended', 0)->get();

$createdOffers = 0;
$createdReviews = 0;

foreach ($users as $user) {
    // Collecter "keywords profil" (title + services company + portfolio hero_headline)
    $keywords = [];
    if (!empty($user->title)) $keywords[] = $user->title;

    if ($user->role === 'business_admin') {
        $companyPage = CompanyPage::where('user_id', $user->id)->first();
        if ($companyPage && is_array($companyPage->services)) {
            foreach ($companyPage->services as $svc) {
                $t = $svc['title'] ?? $svc['name'] ?? null;
                if (!empty($t)) $keywords[] = $t;
            }
        }
        if ($companyPage && !empty($companyPage->company_name)) $keywords[] = $companyPage->company_name;
    }

    if ($user->role === 'individual') {
        $portfolio = UserPortfolio::where('user_id', $user->id)->first();
        if ($portfolio && !empty($portfolio->hero_headline)) $keywords[] = $portfolio->hero_headline;
        if ($portfolio && !empty($portfolio->bio)) {
            // extraire quelques mots "simples" du bio (sans IA)
            $bio = strip_tags((string) $portfolio->bio);
            $bio = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $bio);
            $parts = preg_split('/\s+/', $bio);
            foreach (array_slice($parts, 0, 20) as $p) {
                if (mb_strlen($p) >= 4) $keywords[] = $p;
            }
        }
    }

    $keywords = normalizeKeywords($keywords);
    $fallbackTitle = $user->title ?: ($user->role === 'business_admin' ? 'Entreprise' : 'Professionnel');

    for ($i = 0; $i < $offersPerUser; $i++) {
        $type = pickType();
        [$title, $desc] = buildOfferFromKeywords($type, $keywords, $fallbackTitle);

        // Marquer seed pour faciliter nettoyage/filtre
        $title = "[SEED] " . $title;

        $offer = MarketplaceOffer::create([
            'user_id' => $user->id,
            'title' => Str::limit($title, 255, ''),
            'description' => $desc,
            'type' => $type,
            'price' => priceForCurrency($currency),
            'currency' => strtoupper($currency),
            'image_url' => null,
            'is_active' => true,
        ]);

        $createdOffers++;

        // Ajouter quelques avis (pour influencer ranking)
        $reviewsCount = $maxReviewsPerOffer > 0 ? random_int(0, $maxReviewsPerOffer) : 0;
        if ($reviewsCount > 0 && $reviewers->count() > 1) {
            $picked = $reviewers->shuffle()->take(min($reviewsCount, 12));
            foreach ($picked as $revUser) {
                if ($revUser->id === $user->id) continue;
                try {
                    MarketplaceReview::create([
                        'offer_id' => $offer->id,
                        'user_id' => $revUser->id,
                        'rating' => random_int(3, 5),
                        'comment' => 'Avis généré automatiquement pour tests matching.',
                    ]);
                    $createdReviews++;
                } catch (\Throwable $e) {
                    // unique (offer_id,user_id) peut déjà exister si rerun, ignorer
                }
            }
        }
    }
}

echo "USERS=" . $users->count() . "\n";
echo "OFFERS_CREATED=" . $createdOffers . "\n";
echo "REVIEWS_CREATED=" . $createdReviews . "\n";

