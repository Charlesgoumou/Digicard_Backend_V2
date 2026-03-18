<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceOffer;
use App\Models\MarketplaceReview;
use App\Models\MarketplaceFavorite;
use App\Models\MarketplacePurchase;
use App\Models\MarketplaceMessage;
use App\Models\MarketplaceOfferImage;
use App\Models\MarketplaceMatchScore;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\CompanyPage;
use App\Models\User;
use App\Notifications\MarketplaceTransactionNotification;
use App\Notifications\MarketplaceMatchNotification;
use App\Services\GeminiService;
use App\Services\PerplexityService;
use App\Services\ImageCompressionService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Jobs\ProcessMarketplaceMatching;
use Illuminate\Validation\ValidationException;

class MarketplaceController extends Controller
{
    /**
     * Exécuter le matching maintenant (utile en dev/support).
     * - Si QUEUE_CONNECTION=sync : exécute immédiatement.
     * - Sinon : dispatch en queue et retourne "queued".
     */
    public function runMatchingNow(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non autorisé'], 401);
        }

        $mode = strtolower((string) config('queue.default'));
        $runKey = "marketplace_matching_last_run_{$user->id}";

        try {
            if ($mode === 'sync') {
                (new ProcessMarketplaceMatching((int) $user->id))->handle();
                return response()->json([
                    'status' => 'completed',
                    'mode' => 'sync',
                    'last_run' => Cache::get($runKey),
                ], 200);
            }

            ProcessMarketplaceMatching::dispatch((int) $user->id);

            return response()->json([
                'status' => 'queued',
                'mode' => $mode,
                'message' => 'Matching planifié. Lancez le worker queue pour exécuter.',
            ], 202);
        } catch (\Throwable $e) {
            Log::error('Marketplace matching: erreur runMatchingNow', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
            return response()->json([
                'status' => 'failed',
                'message' => 'Erreur lors du lancement du matching.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Statut du matching pour l’utilisateur courant (dernier run).
     */
    public function matchingStatus(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non autorisé'], 401);
        }

        $runKey = "marketplace_matching_last_run_{$user->id}";
        $last = Cache::get($runKey);

        return response()->json([
            'queue_driver' => config('queue.default'),
            'has_perplexity_key' => !empty(config('perplexity.api_key')),
            'has_gemini_key' => !empty(config('gemini.api_key')),
            'last_run' => $last,
        ], 200);
    }
    /**
     * Endpoint unifié: notifications Marketplace (matching + transactions) via Laravel notifications (database).
     */
    public function getMarketplaceNotifications(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['notifications' => [], 'unread_count' => 0], 200);
        }

        try {
            $allowedTypes = [
                MarketplaceMatchNotification::class,
                MarketplaceTransactionNotification::class,
            ];

            $notifications = $user->notifications()
                ->whereIn('type', $allowedTypes)
                ->orderBy('created_at', 'desc')
                ->take(30)
                ->get()
                ->map(function ($notification) {
                    $data = $notification->data ?? [];
                    return [
                        'id' => $notification->id,
                        'category' => $data['type'] ?? 'marketplace',
                        'message' => $data['message'] ?? null,
                        'offer_id' => $data['offer_id'] ?? null,
                        'purchase_id' => $data['purchase_id'] ?? null,
                        'match_score' => $data['match_score'] ?? null,
                        'url' => $data['url'] ?? null,
                        'read_at' => $notification->read_at,
                        'created_at' => $notification->created_at,
                        'raw_type' => $notification->type,
                    ];
                });

            $unreadCount = $user->unreadNotifications()
                ->whereIn('type', $allowedTypes)
                ->count();

            return response()->json([
                'notifications' => $notifications,
                'unread_count' => $unreadCount,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des notifications marketplace', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['notifications' => [], 'unread_count' => 0], 200);
        }
    }

    /**
     * Reçu d'achat (transaction interne) - accessible par l'acheteur et le vendeur.
     */
    public function getPurchaseReceipt(Request $request, $purchaseId)
    {
        $user = $request->user();

        $purchase = MarketplacePurchase::with(['offer.seller', 'buyer'])
            ->findOrFail($purchaseId);

        $offer = $purchase->offer;

        if (!$offer) {
            return response()->json(['message' => 'Offre introuvable.'], 404);
        }

        $isBuyer = (int) $purchase->buyer_id === (int) $user->id;
        $isSeller = (int) $offer->user_id === (int) $user->id;
        if (!$isBuyer && !$isSeller) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        // Récupérer les transactions wallet associées (si existantes)
        $buyerTx = WalletTransaction::where('marketplace_purchase_id', $purchase->id)
            ->where('type', 'purchase_debit')
            ->orderByDesc('id')
            ->first();
        $sellerTx = WalletTransaction::where('marketplace_purchase_id', $purchase->id)
            ->where('type', 'sale_credit')
            ->orderByDesc('id')
            ->first();

        $reference = 'MP-' . $purchase->id;

        return response()->json([
            'reference' => $reference,
            'purchase' => [
                'id' => $purchase->id,
                'status' => $purchase->status,
                'fulfillment_status' => $purchase->fulfillment_status ?? MarketplacePurchase::FULFILLMENT_COMPLETED,
                'seller_fulfilled_at' => $purchase->seller_fulfilled_at,
                'buyer_confirmed_at' => $purchase->buyer_confirmed_at,
                'buyer_disputed_at' => $purchase->buyer_disputed_at,
                'dispute_reason' => $purchase->dispute_reason,
                'admin_decided_at' => $purchase->admin_decided_at,
                'refund_processed_at' => $purchase->refund_processed_at,
                'refund_reason' => $purchase->refund_reason,
                'price' => $purchase->price,
                'currency' => $purchase->currency,
                'created_at' => $purchase->created_at,
            ],
            'offer' => [
                'id' => $offer->id,
                'title' => $offer->title,
                'type' => $offer->type,
                'is_active' => (bool) $offer->is_active,
            ],
            'buyer' => [
                'id' => $purchase->buyer?->id,
                'name' => $purchase->buyer?->name ?? 'Acheteur',
            ],
            'seller' => [
                'id' => $offer->seller?->id,
                'name' => $offer->seller?->name ?? 'Vendeur',
            ],
            'wallet' => [
                'buyer_tx' => $buyerTx ? [
                    'id' => $buyerTx->id,
                    'type' => $buyerTx->type,
                    'direction' => $buyerTx->direction,
                    'status' => $buyerTx->status,
                    'amount_minor' => (int) $buyerTx->amount_minor,
                    'currency' => $buyerTx->currency,
                    'idempotency_key' => $buyerTx->idempotency_key,
                    'external_reference' => $buyerTx->external_reference,
                    'created_at' => $buyerTx->created_at,
                ] : null,
                'seller_tx' => $sellerTx ? [
                    'id' => $sellerTx->id,
                    'type' => $sellerTx->type,
                    'direction' => $sellerTx->direction,
                    'status' => $sellerTx->status,
                    'amount_minor' => (int) $sellerTx->amount_minor,
                    'currency' => $sellerTx->currency,
                    'idempotency_key' => $sellerTx->idempotency_key,
                    'external_reference' => $sellerTx->external_reference,
                    'created_at' => $sellerTx->created_at,
                ] : null,
            ],
        ], 200);
    }

    /**
     * Ouvrir un litige (acheteur) sur une commande.
     * Permet d'initier ensuite un remboursement côté admin.
     */
    public function requestDispute(Request $request, MarketplacePurchase $purchase)
    {
        $user = $request->user();
        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        $purchase->loadMissing('offer');
        $offer = $purchase->offer;

        if (! $offer) {
            return response()->json(['message' => 'Offre introuvable.'], 404);
        }

        if ((int) $purchase->buyer_id !== (int) $user->id) {
            return response()->json(['message' => 'Action réservée à l’acheteur.'], 403);
        }

        $fs = (string) ($purchase->fulfillment_status ?? MarketplacePurchase::FULFILLMENT_COMPLETED);
        if (in_array($fs, [
            MarketplacePurchase::FULFILLMENT_COMPLETED,
            MarketplacePurchase::FULFILLMENT_REFUNDED,
            MarketplacePurchase::FULFILLMENT_CANCELLED,
        ], true)) {
            return response()->json(['message' => 'Litige impossible dans l’état actuel.'], 422);
        }

        if ($fs === MarketplacePurchase::FULFILLMENT_DISPUTE_REQUESTED) {
            return response()->json(['message' => 'Litige déjà ouvert pour cette commande.'], 409);
        }

        $purchase->fulfillment_status = MarketplacePurchase::FULFILLMENT_DISPUTE_REQUESTED;
        $purchase->buyer_disputed_at = now();
        $purchase->dispute_reason = (string) ($validated['reason'] ?? 'Litige ouvert par l’acheteur');
        $purchase->save();

        return response()->json([
            'message' => 'Litige ouvert. Un admin pourra traiter la demande.',
            'purchase' => [
                'id' => $purchase->id,
                'fulfillment_status' => $purchase->fulfillment_status,
                'buyer_disputed_at' => $purchase->buyer_disputed_at,
            ],
        ], 200);
    }

    /**
     * Cycle de vie commande (après paiement interne) : vendeur / acheteur.
     */
    public function updatePurchaseFulfillment(Request $request, MarketplacePurchase $purchase)
    {
        $user = $request->user();
        $validated = $request->validate([
            'action' => 'required|string|in:seller_in_progress,seller_delivered,buyer_confirm',
        ]);

        $purchase->loadMissing('offer');
        $offer = $purchase->offer;
        if (! $offer || $purchase->status !== 'completed') {
            return response()->json(['message' => 'Cet achat ne peut pas être mis à jour.'], 422);
        }

        $fs = (string) ($purchase->fulfillment_status ?? MarketplacePurchase::FULFILLMENT_PENDING);

        if ($validated['action'] === 'seller_in_progress') {
            if ((int) $offer->user_id !== (int) $user->id) {
                return response()->json(['message' => 'Action réservée au vendeur.'], 403);
            }
            if ($fs !== MarketplacePurchase::FULFILLMENT_PENDING) {
                return response()->json(['message' => 'Cette commande n’est plus à l’étape « à traiter ».'], 422);
            }
            $purchase->fulfillment_status = MarketplacePurchase::FULFILLMENT_IN_PROGRESS;
            $purchase->save();
        } elseif ($validated['action'] === 'seller_delivered') {
            if ((int) $offer->user_id !== (int) $user->id) {
                return response()->json(['message' => 'Action réservée au vendeur.'], 403);
            }
            if (! in_array($fs, [MarketplacePurchase::FULFILLMENT_PENDING, MarketplacePurchase::FULFILLMENT_IN_PROGRESS], true)) {
                return response()->json(['message' => 'Impossible de marquer comme livré / prestation terminée dans cet état.'], 422);
            }
            $purchase->fulfillment_status = MarketplacePurchase::FULFILLMENT_AWAITING_BUYER;
            $purchase->seller_fulfilled_at = now();
            $purchase->save();

            $buyer = User::find($purchase->buyer_id);
            if ($buyer) {
                $buyer->notify(new MarketplaceTransactionNotification(
                    message: 'Le vendeur a indiqué la livraison ou la fin de prestation : '.$offer->title,
                    offerId: (int) $offer->id,
                    purchaseId: (int) $purchase->id,
                    otherUserId: (int) $user->id,
                ));
            }
        } elseif ($validated['action'] === 'buyer_confirm') {
            if ((int) $purchase->buyer_id !== (int) $user->id) {
                return response()->json(['message' => 'Action réservée à l’acheteur.'], 403);
            }
            if ($fs !== MarketplacePurchase::FULFILLMENT_AWAITING_BUYER) {
                return response()->json(['message' => 'Vous ne pouvez confirmer qu’après l’indication du vendeur.'], 422);
            }
            $purchase->fulfillment_status = MarketplacePurchase::FULFILLMENT_COMPLETED;
            $purchase->buyer_confirmed_at = now();
            $purchase->save();

            $seller = User::find($offer->user_id);
            if ($seller) {
                $seller->notify(new MarketplaceTransactionNotification(
                    message: 'L’acheteur a confirmé la réception : '.$offer->title,
                    offerId: (int) $offer->id,
                    purchaseId: (int) $purchase->id,
                    otherUserId: (int) $user->id,
                ));
            }
        }

        return response()->json([
            'message' => 'Statut mis à jour.',
            'purchase' => [
                'id' => $purchase->id,
                'fulfillment_status' => $purchase->fulfillment_status,
                'seller_fulfilled_at' => $purchase->seller_fulfilled_at,
                'buyer_confirmed_at' => $purchase->buyer_confirmed_at,
            ],
        ], 200);
    }

    private function currencyFactor(string $currency): int
    {
        $c = strtoupper($currency);
        if (in_array($c, ['GNF', 'XOF'], true)) return 1;
        return 100;
    }

    private function moneyToMinor($amount, string $currency = 'EUR'): int
    {
        // $amount peut être string (decimal cast). On force une conversion robuste.
        $factor = $this->currencyFactor($currency);
        return (int) round(((float) $amount) * $factor);
    }

    /**
     * Récupérer toutes les offres disponibles
     * Avec recherche en temps réel, recommandation intelligente et tri personnalisé
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $filter = $request->query('filter', 'all'); // all, purchases, sales, favorites
        $search = $request->query('search', ''); // Recherche en temps réel
        
        // Charger les relations (gérer le cas où la table images n'existe pas encore)
        $withRelations = ['seller:id,name,title,avatar_url,website_url,whatsapp_url,linkedin_url,facebook_url,twitter_url,youtube_url', 'reviews'];
        try {
            // Vérifier si la table existe en essayant une requête simple
            DB::table('marketplace_offer_images')->limit(1)->get();
            $withRelations[] = 'images';
        } catch (\Exception $e) {
            // Table n'existe pas encore, ne pas charger la relation images
            Log::debug('Table marketplace_offer_images n\'existe pas encore, relation images ignorée');
        }
        
        // Par défaut: on expose seulement les offres actives.
        // Exceptions: "sales" et "purchases" doivent aussi inclure les offres désactivées (vendues).
        $query = MarketplaceOffer::query()
            ->with($withRelations)
            ->withCount('reviews');
        
        // ✅ DEBUG : Logger le nombre d'offres avant le mapping
        Log::debug('MarketplaceController: Nombre d\'offres trouvées', [
            'count' => $query->count(),
            'filter' => $filter,
            'user_id' => $user ? $user->id : null,
            'search' => $search
        ]);
        
        // Recherche en temps réel (dès la première lettre)
        if (!empty($search)) {
            $searchTerms = explode(' ', trim($search));
            $query->where(function($q) use ($searchTerms) {
                foreach ($searchTerms as $term) {
                    $q->where('title', 'like', "%{$term}%")
                      ->orWhere('description', 'like', "%{$term}%");
                }
            });
        }
        
        // Filtrer selon le type demandé
        if ($filter === 'sales' && $user) {
            // Mes ventes : toutes les offres créées par l'utilisateur (actives + désactivées)
            $query->where('user_id', $user->id);
        } elseif ($filter === 'purchases' && $user) {
            // Mes achats : toutes les offres achetées par l'utilisateur (actives + désactivées)
            $purchasedOfferIds = MarketplacePurchase::where('buyer_id', $user->id)
                ->where('status', '=', 'completed')
                ->pluck('offer_id');
            $query->whereIn('id', $purchasedOfferIds);
        } elseif ($filter === 'favorites' && $user) {
            // Mes favoris : offres actives ajoutées aux favoris
            $favoriteOfferIds = MarketplaceFavorite::where('user_id', $user->id)
                ->pluck('offer_id');
            $query->whereIn('id', $favoriteOfferIds)
                  ->where('is_active', true);
        } else {
            // "all" (et tout autre filtre inconnu) => offres actives uniquement
            $query->where('is_active', true);
        }

        $applyListingFilters = in_array($filter, ['all', 'favorites'], true);
        $sortMode = strtolower((string) $request->query('sort', 'match'));
        if (! in_array($sortMode, ['match', 'newest', 'price_asc', 'price_desc', 'rating'], true)) {
            $sortMode = 'match';
        }

        if ($applyListingFilters) {
            $category = (string) $request->query('category', '');
            if ($category !== '' && $category !== 'all' && in_array($category, MarketplaceOffer::allowedCategoryKeys(), true)) {
                $query->where('category', $category);
            }
            $offerType = (string) $request->query('offer_type', '');
            if (in_array($offerType, ['offer', 'product', 'service'], true)) {
                $query->where('type', $offerType);
            }
            $cur = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $request->query('currency', '')));
            if (strlen($cur) === 3) {
                $query->where('currency', $cur);
            }
            $pmin = $request->query('price_min');
            $pmax = $request->query('price_max');
            if ($pmin !== null && $pmin !== '' && is_numeric($pmin)) {
                $query->where('price', '>=', max(0, (float) $pmin));
            }
            if ($pmax !== null && $pmax !== '' && is_numeric($pmax)) {
                $query->where('price', '<=', max(0, (float) $pmax));
            }
        }
        
        // ✅ AMÉLIORATION : Charger les scores de matching pour l'utilisateur si connecté
        $matchScores = [];
        if ($user) {
            $matchScores = MarketplaceMatchScore::where('user_id', $user->id)
                ->pluck('match_score', 'offer_id')
                ->toArray();
        }
        
        $offers = $query->get();
        
        // ✅ DEBUG : Logger le nombre d'offres après get()
        Log::debug('MarketplaceController: Nombre d\'offres après get()', [
            'count' => $offers->count()
        ]);
        
        $offers = $offers->map(function ($offer) use ($user, $matchScores, $filter) {
            try {
                // Calculer la note moyenne
                $averageRating = $offer->reviews()->avg('rating');
                
                // ✅ NOUVEAU : Récupérer le score de matching
                $matchScore = $matchScores[$offer->id] ?? null;
                
                // Vérifier si l'offre est dans les favoris de l'utilisateur
                $isFavorite = $user ? MarketplaceFavorite::where('offer_id', $offer->id)
                    ->where('user_id', $user->id)
                    ->exists() : false;
                
                // Vérifier si l'utilisateur est le vendeur
                $isSeller = $user && $offer->user_id === $user->id;
                
                // Vérifier si l'utilisateur a acheté cette offre + récupérer le dernier achat (pour reçu)
                $latestPurchase = null;
                if ($user) {
                    $latestPurchase = MarketplacePurchase::where('offer_id', $offer->id)
                        ->where('buyer_id', $user->id)
                        ->where('status', '=', 'completed')
                        ->orderByDesc('id')
                        ->first();
                }
                $isPurchased = (bool) $latestPurchase;

                // Pour le vendeur: récupérer l'acheteur du dernier achat completed (si vendu)
                $latestSale = null;
                if ($user && $offer->user_id === $user->id) {
                    $latestSale = MarketplacePurchase::where('offer_id', $offer->id)
                        ->where('status', '=', 'completed')
                        ->orderByDesc('id')
                        ->first();
                }
                
                // ✅ CORRECTION : Vérifier que le seller existe avant d'accéder à ses propriétés
                $seller = $offer->seller ?? null;
                $isProfileComplete = $seller ? $this->isProfileComplete($seller) : false;
                
                // Formater les images (si disponibles)
                $images = [];
                if (isset($offer->images) && $offer->images && $offer->images->count() > 0) {
                    $images = $offer->images->map(function ($image) {
                        return [
                            'id' => $image->id,
                            'url' => $image->image_url,
                            'order' => $image->order,
                            'is_primary' => $image->is_primary,
                        ];
                    })->sortBy('order')->values()->toArray();
                }

                $fulfillmentStatus = null;
                $fulfillmentActions = [
                    'buyer_confirm' => false,
                    'buyer_can_dispute' => false,
                    'seller_in_progress' => false,
                    'seller_delivered' => false,
                ];
                if ($filter === 'purchases' && $latestPurchase) {
                    $fulfillmentStatus = $latestPurchase->fulfillment_status ?? MarketplacePurchase::FULFILLMENT_COMPLETED;
                    $fulfillmentActions['buyer_confirm'] = $fulfillmentStatus === MarketplacePurchase::FULFILLMENT_AWAITING_BUYER;
                    $fulfillmentActions['buyer_can_dispute'] = in_array($fulfillmentStatus, [
                        MarketplacePurchase::FULFILLMENT_PENDING,
                        MarketplacePurchase::FULFILLMENT_IN_PROGRESS,
                        MarketplacePurchase::FULFILLMENT_AWAITING_BUYER,
                    ], true);
                }
                if ($filter === 'sales' && $latestSale) {
                    $fulfillmentStatus = $latestSale->fulfillment_status ?? MarketplacePurchase::FULFILLMENT_PENDING;
                    $fulfillmentActions['seller_in_progress'] = $fulfillmentStatus === MarketplacePurchase::FULFILLMENT_PENDING;
                    $fulfillmentActions['seller_delivered'] = in_array($fulfillmentStatus, [
                        MarketplacePurchase::FULFILLMENT_PENDING,
                        MarketplacePurchase::FULFILLMENT_IN_PROGRESS,
                    ], true);
                }
                
                return [
                    'id' => $offer->id,
                    'title' => $offer->title,
                    'description' => $offer->description,
                    'type' => $offer->type,
                    'category' => $offer->category,
                    'category_label' => MarketplaceOffer::categoryLabel($offer->category),
                    'price' => $offer->price,
                    'currency' => $offer->currency,
                    'image_url' => $offer->image_url, // Image principale (pour compatibilité)
                    'images' => $images, // Toutes les images
                    'is_active' => (bool) $offer->is_active,
                    'seller_id' => $offer->user_id,
                    'seller_name' => $seller ? ($seller->name ?? 'Anonyme') : 'Anonyme',
                    'seller_title' => $seller ? ($seller->title ?? null) : null,
                    'seller_avatar' => $seller ? ($seller->avatar_url ?? null) : null,
                    'average_rating' => $averageRating ? round($averageRating, 1) : null,
                    'reviews_count' => $offer->reviews_count,
                    'is_favorite' => $isFavorite,
                    'is_seller' => $isSeller,
                    'is_purchased' => $isPurchased,
                    'purchase_id' => $latestPurchase ? $latestPurchase->id : null,
                    'purchased_at' => $latestPurchase ? $latestPurchase->created_at : null,
                    'sale_purchase_id' => $latestSale ? $latestSale->id : null,
                    'sold_at' => $latestSale ? $latestSale->created_at : null,
                    'is_profile_complete' => $isProfileComplete,
                    'match_score' => $matchScore ? (float) $matchScore : null, // ✅ NOUVEAU : Score de matching
                    'created_at' => $offer->created_at,
                    'fulfillment_status' => $fulfillmentStatus,
                    'fulfillment_actions' => $fulfillmentActions,
                ];
            } catch (\Exception $e) {
                Log::error('MarketplaceController: Erreur lors du mapping d\'une offre', [
                    'offer_id' => $offer->id ?? 'unknown',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                // Retourner null pour cette offre, elle sera filtrée après
                return null;
            }
        })->filter(function ($offer) {
            // Filtrer les offres null (celles qui ont causé une erreur)
            return $offer !== null;
        });
        
        // ✅ Ranking amélioré (sans filtrer): adapte l'affichage au profil
        // Principes:
        // - match_score est prioritaire (si présent)
        // - si recherche: boost fort sur match du titre, puis description
        // - ensuite: profil complet + avis + note + récence
        $searchLower = trim(mb_strtolower((string) $search));
        $searchTerms = $searchLower !== '' ? array_values(array_filter(preg_split('/\s+/', $searchLower))) : [];

        $rankFn = function ($offer) use ($user, $filter, $searchTerms) {
            $score = 0.0;

            $matchScore = isset($offer['match_score']) && $offer['match_score'] !== null ? (float) $offer['match_score'] : 0.0;
            if ($user && $filter === 'all') {
                // Poids fort: matching personnalisé
                $score += $matchScore * 120.0; // ex: 1 match (6–12) devient visible en haut
            }

            // Pertinence recherche (dès 1 lettre) : titre >> description
            if (!empty($searchTerms)) {
                $t = mb_strtolower((string) ($offer['title'] ?? ''));
                $d = mb_strtolower((string) ($offer['description'] ?? ''));
                foreach ($searchTerms as $term) {
                    if ($term === '') continue;
                    if (mb_strlen($term) < 2) continue;

                    if (mb_strpos($t, $term) !== false) {
                        $score += 500.0; // gros boost si match titre
                    } elseif (mb_strpos($d, $term) !== false) {
                        $score += 120.0; // boost moindre si match description
                    }
                }
            }

            // Profil vendeur complet
            if (!empty($offer['is_profile_complete'])) {
                $score += 80.0;
            }

            // Avis / note
            $reviewsCount = (int) ($offer['reviews_count'] ?? 0);
            $avg = (float) ($offer['average_rating'] ?? 0);
            $score += min(60.0, $reviewsCount * 6.0); // cap avis
            $score += min(40.0, $avg * 8.0);          // cap note

            // Récence (max ~30 points)
            $createdAt = $offer['created_at'] ?? null;
            try {
                if ($createdAt) {
                    $hours = now()->diffInHours(\Carbon\Carbon::parse($createdAt));
                    $score += max(0.0, 30.0 - min(30.0, $hours / 24.0 * 10.0)); // 0-24h: ~30, puis décroît
                }
            } catch (\Throwable $e) {
                // ignore
            }

            return $score;
        };

        if ($applyListingFilters && $sortMode === 'price_asc') {
            $offers = $offers->sortBy(fn ($o) => (float) ($o['price'] ?? 0))->values();
        } elseif ($applyListingFilters && $sortMode === 'price_desc') {
            $offers = $offers->sortByDesc(fn ($o) => (float) ($o['price'] ?? 0))->values();
        } elseif ($applyListingFilters && $sortMode === 'newest') {
            $offers = $offers->sortByDesc(fn ($o) => strtotime((string) ($o['created_at'] ?? '')))->values();
        } elseif ($applyListingFilters && $sortMode === 'rating') {
            $offers = $offers->sortByDesc(function ($o) {
                $avg = (float) ($o['average_rating'] ?? 0);
                $n = (int) ($o['reviews_count'] ?? 0);

                return $avg * 1000 + min(500, $n * 10);
            })->values();
        } else {
            $offers = $offers->sortByDesc($rankFn)->values();
        }
        
        // ✅ DEBUG : Logger le nombre d'offres final
        Log::debug('MarketplaceController: Nombre d\'offres final après tri', [
            'count' => $offers->count()
        ]);
        
        return response()->json($offers);
    }

    /**
     * Libellés catégories + options de tri (pour la barre de filtres).
     */
    public function filterOptions(Request $request)
    {
        $categories = [];
        foreach (MarketplaceOffer::allowedCategoryKeys() as $key) {
            $categories[] = [
                'value' => $key,
                'label' => MarketplaceOffer::categoryLabel($key),
            ];
        }

        return response()->json([
            'categories' => $categories,
            'sort' => [
                ['value' => 'match', 'label' => 'Pour vous (pertinence)'],
                ['value' => 'newest', 'label' => 'Plus récentes'],
                ['value' => 'price_asc', 'label' => 'Prix croissant'],
                ['value' => 'price_desc', 'label' => 'Prix décroissant'],
                ['value' => 'rating', 'label' => 'Mieux notées'],
            ],
            'offer_types' => [
                ['value' => '', 'label' => 'Tous types'],
                ['value' => 'product', 'label' => 'Produit'],
                ['value' => 'service', 'label' => 'Service'],
                ['value' => 'offer', 'label' => 'Offre'],
            ],
        ], 200);
    }

    /**
     * Récupérer les détails d'une offre avec ses avis
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        
        // Charger les relations (gérer le cas où la table images n'existe pas encore)
        $withRelations = ['seller:id,name', 'reviews.user:id,name'];
        try {
            // Vérifier si la table existe
            DB::table('marketplace_offer_images')->limit(1)->get();
            $withRelations[] = 'images';
        } catch (\Exception $e) {
            // Table n'existe pas encore
            Log::debug('Table marketplace_offer_images n\'existe pas encore, relation images ignorée');
        }
        
        $offer = MarketplaceOffer::with($withRelations)
            ->findOrFail($id);
        
        $averageRating = $offer->reviews()->avg('rating');
        $isFavorite = $user ? MarketplaceFavorite::where('offer_id', $offer->id)
            ->where('user_id', $user->id)
            ->exists() : false;
        
        $reviews = $offer->reviews->map(function ($review) {
            return [
                'id' => $review->id,
                'user_name' => $review->user->name ?? 'Anonyme',
                'rating' => $review->rating,
                'comment' => $review->comment,
                'created_at' => $review->created_at,
            ];
        });
        
        // Récupérer le dernier avis (pour le vendeur)
        $latestReview = null;
        if ($offer->reviews->count() > 0) {
            $latestReviewModel = $offer->reviews()->latest()->first();
            $latestReview = [
                'id' => $latestReviewModel->id,
                'user_name' => $latestReviewModel->user->name ?? 'Anonyme',
                'rating' => $latestReviewModel->rating,
                'comment' => $latestReviewModel->comment,
                'created_at' => $latestReviewModel->created_at,
            ];
        }
        
        // Récupérer le dernier message (pour le vendeur)
        $latestMessage = null;
        try {
            $latestMessageModel = MarketplaceMessage::where('offer_id', $offer->id)
                ->latest()
                ->first();
            if ($latestMessageModel) {
                $latestMessage = [
                    'id' => $latestMessageModel->id,
                    'sender_id' => $latestMessageModel->sender_id,
                    'sender_name' => $latestMessageModel->sender->name ?? 'Anonyme',
                    'receiver_id' => $latestMessageModel->receiver_id,
                    'receiver_name' => $latestMessageModel->receiver->name ?? 'Anonyme',
                    'message' => $latestMessageModel->message,
                    'is_read' => $latestMessageModel->is_read,
                    'created_at' => $latestMessageModel->created_at,
                ];
            }
        } catch (\Exception $e) {
            // Table n'existe pas encore
            Log::debug('Table marketplace_messages n\'existe pas encore, latestMessage ignoré');
        }
        
        // Formater les images (si disponibles)
        $images = [];
        if (isset($offer->images) && $offer->images && $offer->images->count() > 0) {
            $images = $offer->images->map(function ($image) {
                return [
                    'id' => $image->id,
                    'url' => $image->image_url,
                    'order' => $image->order,
                    'is_primary' => $image->is_primary,
                ];
            })->sortBy('order')->values()->toArray();
        }
        
        // Vérifier si l'utilisateur est le vendeur
        $isSeller = $user && $offer->user_id === $user->id;

        // Éligibilité avis : uniquement l'acheteur, après confirmation de réception.
        $hasReviewed = false;
        $canReview = false;
        if ($user && ! $isSeller) {
            $hasReviewed = MarketplaceReview::where('offer_id', $offer->id)
                ->where('user_id', $user->id)
                ->exists();

            $buyerPurchase = MarketplacePurchase::where('offer_id', $offer->id)
                ->where('buyer_id', $user->id)
                ->where('status', 'completed')
                ->orderByDesc('id')
                ->first();

            if ($buyerPurchase) {
                $fs = $buyerPurchase->fulfillment_status ?? MarketplacePurchase::FULFILLMENT_COMPLETED;
                $canReview = ! $hasReviewed && $fs === MarketplacePurchase::FULFILLMENT_COMPLETED;
            }
        }
        
        return response()->json([
            'id' => $offer->id,
            'title' => $offer->title,
            'description' => $offer->description,
            'type' => $offer->type,
            'category' => $offer->category,
            'category_label' => MarketplaceOffer::categoryLabel($offer->category),
            'price' => $offer->price,
            'currency' => $offer->currency,
            'image_url' => $offer->image_url, // Image principale (pour compatibilité)
            'images' => $images, // Toutes les images
            'seller_id' => $offer->user_id,
            'user_id' => $offer->user_id, // Pour compatibilité
            'seller_name' => $offer->seller->name ?? 'Anonyme',
            'average_rating' => $averageRating ? round($averageRating, 1) : null,
            'reviews_count' => $offer->reviews->count(),
            'reviews' => $reviews,
            'latest_review' => $latestReview, // Dernier avis (pour le vendeur)
            'latest_message' => $latestMessage, // Dernier message (pour le vendeur)
            'is_favorite' => $isFavorite,
            'is_seller' => $isSeller, // Indique si l'utilisateur actuel est le vendeur
            'can_review' => $canReview,
            'has_reviewed' => $hasReviewed,
            'created_at' => $offer->created_at,
        ]);
    }

    /**
     * Créer une nouvelle offre
     */
    public function store(Request $request)
    {
        $user = $request->user();
        
        // ✅ CORRECTION : Validation améliorée pour éviter l'erreur 422
        // Si des images sont envoyées, elles doivent être valides
        // Si aucune image n'est envoyée, c'est accepté (nullable)
        $catRule = 'nullable|string|in:'.implode(',', MarketplaceOffer::allowedCategoryKeys());
        $rules = [
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'type' => 'required|in:offer,product,service',
            'category' => $catRule,
            'price' => 'required|numeric|min:0',
            'currency' => 'nullable|string|max:3',
        ];
        
        // Ajouter la validation des images seulement si elles sont présentes
        if ($request->hasFile('images')) {
            $rules['images'] = 'required|array';
            $rules['images.*'] = 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120';
        }
        
        $validated = $request->validate($rules);
        
        $imageUrl = null;
        $imageUrls = [];
        
        // Gérer l'upload des images (plusieurs images possibles)
        if ($request->hasFile('images')) {
            try {
                $images = $request->file('images');
                $compressionService = new ImageCompressionService();
                
                foreach ($images as $image) {
                    // Compresser et stocker chaque image
                    $result = $compressionService->compressImage($image, 'marketplace/offers');
                    $imageUrls[] = Storage::disk('public')->url($result['path']);
                }
                
                // Utiliser la première image comme image principale
                $imageUrl = !empty($imageUrls) ? $imageUrls[0] : null;
            } catch (\Exception $e) {
                Log::error('Erreur lors de l\'upload des images marketplace: ' . $e->getMessage());
                return response()->json([
                    'message' => 'Erreur lors de l\'upload des images.',
                    'error' => $e->getMessage()
                ], 500);
            }
        }
        
        $cat = $validated['category'] ?? null;
        if (! $cat || ! in_array($cat, MarketplaceOffer::allowedCategoryKeys(), true)) {
            $cat = 'autre';
        }
        $offer = MarketplaceOffer::create([
            'user_id' => $user->id,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'type' => $validated['type'],
            'category' => $cat,
            'price' => $validated['price'],
            'currency' => $validated['currency'] ?? 'EUR',
            'image_url' => $imageUrl, // Image principale (première image)
            'is_active' => true,
        ]);
        
        // Stocker toutes les images dans la table marketplace_offer_images
        if (!empty($imageUrls)) {
            foreach ($imageUrls as $index => $url) {
                try {
                    MarketplaceOfferImage::create([
                        'marketplace_offer_id' => $offer->id,
                        'image_url' => $url,
                        'order' => $index,
                        'is_primary' => $index === 0, // La première image est principale
                    ]);
                } catch (\Exception $e) {
                    // Si la table n'existe pas encore, logger l'erreur mais continuer
                    Log::warning('Impossible de créer l\'image dans marketplace_offer_images: ' . $e->getMessage());
                    // Ne pas faire échouer la création de l'offre si la table n'existe pas encore
                }
            }
        }
        
        // Charger les relations pour la réponse (gérer le cas où la table images n'existe pas)
        try {
            $offer->load('images', 'seller:id,name');
        } catch (\Exception $e) {
            // Si la relation images échoue (table n'existe pas), charger seulement seller
            Log::warning('Impossible de charger les images: ' . $e->getMessage());
            $offer->load('seller:id,name');
        }
        
        return response()->json($offer, 201);
    }

    /**
     * Générer la description d'une offre avec l'IA à partir d'une image
     */
    public function generateDescriptionFromImage(Request $request)
    {
        $user = $request->user();
        
        $validated = $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'type' => 'nullable|in:offer,product,service',
        ]);
        
        try {
            // Sauvegarder temporairement l'image
            $image = $request->file('image');
            $tempPath = $image->store('temp', 'public');
            $fullPath = storage_path('app/public/' . $tempPath);
            
            // Utiliser Gemini pour analyser l'image
            $geminiService = new GeminiService();
            
            // Lire le contenu de l'image en base64
            $imageContent = file_get_contents($fullPath);
            $imageBase64 = base64_encode($imageContent);
            $mimeType = $image->getMimeType();
            
            // Construire le prompt pour Gemini
            $prompt = "Analyse cette image et génère une description détaillée pour une offre de marketplace. ";
            $prompt .= "Si c'est un produit, décris ses caractéristiques, son utilité et sa valeur. ";
            $prompt .= "Si c'est un service, décris ce qui est proposé, les avantages et les bénéfices. ";
            $prompt .= "Génère un titre accrocheur (max 100 caractères), une description détaillée (200-500 mots), ";
            $prompt .= "et suggère un type (offer, product, ou service) et un prix estimé. ";
            $prompt .= "Réponds UNIQUEMENT en JSON valide avec cette structure: ";
            $prompt .= '{"title": "Titre de l\'offre", "description": "Description détaillée", "type": "product|service|offer", "suggested_price": 0.00}';
            
            // Utiliser l'URL de l'API depuis la configuration (gemini-2.5-flash)
            $apiUrl = config('gemini.api_url');
            $apiKey = config('gemini.api_key');
            
            if (empty($apiKey)) {
                Storage::disk('public')->delete($tempPath);
                return response()->json([
                    'message' => 'Clé API Gemini non configurée.',
                ], 500);
            }
            
            // Appeler Gemini Vision API avec le bon modèle
            $response = Http::timeout(120)->post($apiUrl . '?key=' . $apiKey, [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => $prompt
                            ],
                            [
                                'inline_data' => [
                                    'mime_type' => $mimeType,
                                    'data' => $imageBase64
                                ]
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'topK' => 40,
                    'topP' => 0.95,
                    'maxOutputTokens' => 4096,
                ]
            ]);
            
            // Supprimer le fichier temporaire
            Storage::disk('public')->delete($tempPath);
            
            if (!$response->successful()) {
                Log::error('Erreur Gemini API: ' . $response->body());
                return response()->json([
                    'message' => 'Erreur lors de l\'analyse de l\'image par l\'IA.',
                    'error' => $response->body()
                ], 500);
            }
            
            $responseData = $response->json();
            $generatedText = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '';
            
            if (empty($generatedText)) {
                return response()->json([
                    'message' => 'Aucune réponse de l\'IA.',
                    'raw_response' => $responseData
                ], 500);
            }
            
            // Extraire le JSON de la réponse (peut être entouré de markdown)
            $jsonStart = strpos($generatedText, '{');
            $jsonEnd = strrpos($generatedText, '}');
            
            if ($jsonStart === false || $jsonEnd === false) {
                return response()->json([
                    'message' => 'Format de réponse IA invalide.',
                    'raw_response' => $generatedText
                ], 500);
            }
            
            $jsonString = substr($generatedText, $jsonStart, $jsonEnd - $jsonStart + 1);
            $parsedData = json_decode($jsonString, true);
            
            if (!$parsedData) {
                return response()->json([
                    'message' => 'Impossible de parser la réponse de l\'IA.',
                    'raw_response' => $generatedText,
                    'json_error' => json_last_error_msg()
                ], 500);
            }
            
            return response()->json([
                'title' => $parsedData['title'] ?? '',
                'description' => $parsedData['description'] ?? '',
                'type' => $parsedData['type'] ?? ($validated['type'] ?? 'offer'),
                'suggested_price' => $parsedData['suggested_price'] ?? 0,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erreur lors de la génération de description: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            // Nettoyer le fichier temporaire en cas d'erreur
            if (isset($tempPath)) {
                try {
                    Storage::disk('public')->delete($tempPath);
                } catch (\Exception $cleanupException) {
                    // Ignorer l'erreur de nettoyage
                }
            }
            
            return response()->json([
                'message' => 'Erreur lors de la génération de description.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Ajouter/Retirer des favoris
     */
    public function toggleFavorite(Request $request, $id)
    {
        $user = $request->user();

        $offer = MarketplaceOffer::findOrFail($id);
        if (!$offer->is_active) {
            return response()->json([
                'message' => 'Cette offre est indisponible. Impossible de l’ajouter aux favoris.'
            ], 409);
        }
        
        $favorite = MarketplaceFavorite::where('offer_id', $id)
            ->where('user_id', $user->id)
            ->first();
        
        if ($favorite) {
            $favorite->delete();
            return response()->json(['message' => 'Retiré des favoris', 'is_favorite' => false]);
        } else {
            MarketplaceFavorite::create([
                'offer_id' => $id,
                'user_id' => $user->id,
            ]);
            return response()->json(['message' => 'Ajouté aux favoris', 'is_favorite' => true]);
        }
    }

    /**
     * Ajouter un avis à une offre
     */
    public function addReview(Request $request, $id)
    {
        $user = $request->user();
        
        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'required|string|max:1000',
        ]);

        // Pro: autoriser seulement si l'utilisateur a acheté et que la réception est confirmée.
        $purchase = MarketplacePurchase::where('offer_id', $id)
            ->where('buyer_id', $user->id)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->first();

        if (! $purchase) {
            throw ValidationException::withMessages([
                'comment' => ['Vous devez avoir acheté cette offre pour laisser un avis.'],
            ]);
        }

        $fs = $purchase->fulfillment_status ?? MarketplacePurchase::FULFILLMENT_COMPLETED;
        if ($fs !== MarketplacePurchase::FULFILLMENT_COMPLETED) {
            throw ValidationException::withMessages([
                'comment' => ['Vous pouvez laisser un avis uniquement après confirmation de la réception.'],
            ]);
        }
        
        // Vérifier si l'utilisateur a déjà laissé un avis
        $existingReview = MarketplaceReview::where('offer_id', $id)
            ->where('user_id', $user->id)
            ->first();
        
        if ($existingReview) {
            throw ValidationException::withMessages([
                'comment' => ['Vous avez déjà laissé un avis pour cette offre.'],
            ]);
        }
        
        $review = MarketplaceReview::create([
            'offer_id' => $id,
            'user_id' => $user->id,
            'rating' => $validated['rating'],
            'comment' => $validated['comment'],
        ]);
        
        return response()->json($review, 201);
    }

    /**
     * Ajouter au panier (créer un achat en attente)
     */
    public function addToCart(Request $request, $id)
    {
        $user = $request->user();
        
        $offer = MarketplaceOffer::findOrFail($id);
        
        // Vérifier si l'utilisateur n'est pas le vendeur
        if ($offer->user_id === $user->id) {
            return response()->json([
                'message' => 'Vous ne pouvez pas acheter votre propre offre.'
            ], 403);
        }
        
        // TODO: Implémenter le système de panier
        // Pour l'instant, créer directement un achat en attente
        $purchase = MarketplacePurchase::create([
            'offer_id' => $id,
            'buyer_id' => $user->id,
            'price' => $offer->price,
            'currency' => $offer->currency,
            'status' => 'pending',
        ]);
        
        return response()->json([
            'message' => 'Offre ajoutée au panier',
            'purchase' => $purchase
        ], 201);
    }

    /**
     * Achat interne (via solde système) : débit acheteur -> crédit vendeur -> désactivation offre
     * Transaction atomique.
     */
    public function purchaseInternal(Request $request, $id)
    {
        $buyer = $request->user();
        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey && !is_string($idempotencyKey)) {
            $idempotencyKey = null;
        }
        // Le champ wallet_transactions.idempotency_key est un UUID (36 chars)
        if (is_string($idempotencyKey) && !preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $idempotencyKey)) {
            $idempotencyKey = null;
        }

        try {
            return DB::transaction(function () use ($buyer, $id, $idempotencyKey) {
                // Idempotence: si on a déjà une transaction avec cette clé, renvoyer succès
                if ($idempotencyKey) {
                    $existing = WalletTransaction::where('idempotency_key', $idempotencyKey)->first();
                    if ($existing && $existing->status === 'completed') {
                        return response()->json([
                            'message' => 'Transaction déjà traitée.',
                            'wallet_transaction_id' => $existing->id,
                        ], 200);
                    }
                }

                /** @var MarketplaceOffer $offer */
                $offer = MarketplaceOffer::where('id', $id)->lockForUpdate()->firstOrFail();

                if (!$offer->is_active) {
                    return response()->json(['message' => 'Cette offre est déjà indisponible.'], 409);
                }

                if ((int) $offer->user_id === (int) $buyer->id) {
                    return response()->json(['message' => 'Vous ne pouvez pas acheter votre propre offre.'], 403);
                }

                $currency = strtoupper($offer->currency ?: 'EUR');
                $amountMinor = $this->moneyToMinor($offer->price, $currency);
                if ($amountMinor <= 0) {
                    return response()->json(['message' => 'Montant invalide pour cette offre.'], 422);
                }

                $sellerId = (int) $offer->user_id;

                // Créer / récupérer wallets puis lock pour garantir la cohérence
                $buyerWallet = Wallet::firstOrCreate(
                    ['user_id' => $buyer->id, 'currency' => $currency],
                    ['balance_minor' => 0],
                );
                $sellerWallet = Wallet::firstOrCreate(
                    ['user_id' => $sellerId, 'currency' => $currency],
                    ['balance_minor' => 0],
                );

                $buyerWallet = Wallet::where('id', $buyerWallet->id)->lockForUpdate()->first();
                $sellerWallet = Wallet::where('id', $sellerWallet->id)->lockForUpdate()->first();

                if ((int) $buyerWallet->balance_minor < $amountMinor) {
                    return response()->json([
                        'message' => 'Solde insuffisant. Veuillez recharger votre solde.',
                        'required_minor' => $amountMinor,
                        'balance_minor' => (int) $buyerWallet->balance_minor,
                        'currency' => $currency,
                    ], 402);
                }

                // Créer l'achat "completed" (transaction interne immédiate)
                $purchase = MarketplacePurchase::create([
                    'offer_id' => $offer->id,
                    'buyer_id' => $buyer->id,
                    'price' => $offer->price,
                    'currency' => $currency,
                    'status' => 'completed',
                    'fulfillment_status' => MarketplacePurchase::FULFILLMENT_PENDING,
                ]);

                // Ledger: débit acheteur
                $buyerTx = WalletTransaction::create([
                    'wallet_id' => $buyerWallet->id,
                    'user_id' => $buyer->id,
                    'direction' => 'debit',
                    'type' => 'purchase_debit',
                    'amount_minor' => $amountMinor,
                    'currency' => $currency,
                    'status' => 'completed',
                    'marketplace_offer_id' => $offer->id,
                    'marketplace_purchase_id' => $purchase->id,
                    'idempotency_key' => $idempotencyKey,
                    'meta' => [
                        'seller_id' => $sellerId,
                        'offer_title' => $offer->title,
                    ],
                    'completed_at' => now(),
                ]);

                // Ledger: crédit vendeur
                // NB: idempotency_key est unique et au format UUID. On ne peut pas suffixer "-seller".
                $sellerIdempotencyKey = $idempotencyKey ? (string) Str::uuid() : null;
                $sellerTx = WalletTransaction::create([
                    'wallet_id' => $sellerWallet->id,
                    'user_id' => $sellerId,
                    'direction' => 'credit',
                    'type' => 'sale_credit',
                    'amount_minor' => $amountMinor,
                    'currency' => $currency,
                    'status' => 'completed',
                    'marketplace_offer_id' => $offer->id,
                    'marketplace_purchase_id' => $purchase->id,
                    'idempotency_key' => $sellerIdempotencyKey,
                    'meta' => [
                        'buyer_id' => (int) $buyer->id,
                        'offer_title' => $offer->title,
                        'buyer_idempotency_key' => $idempotencyKey,
                    ],
                    'completed_at' => now(),
                ]);

                // Mise à jour balances
                $buyerWallet->balance_minor = (int) $buyerWallet->balance_minor - $amountMinor;
                $sellerWallet->balance_minor = (int) $sellerWallet->balance_minor + $amountMinor;
                $buyerWallet->save();
                $sellerWallet->save();

                // Désactiver l'offre
                $offer->is_active = false;
                $offer->save();

                // Notifications simples (DB)
                $seller = User::find($sellerId);
                if ($seller) {
                    $seller->notify(new MarketplaceTransactionNotification(
                        message: 'Vous avez vendu: ' . $offer->title,
                        offerId: (int) $offer->id,
                        purchaseId: (int) $purchase->id,
                        otherUserId: (int) $buyer->id,
                    ));
                }
                $buyer->notify(new MarketplaceTransactionNotification(
                    message: 'Achat effectué: ' . $offer->title,
                    offerId: (int) $offer->id,
                    purchaseId: (int) $purchase->id,
                    otherUserId: (int) $sellerId,
                ));

                return response()->json([
                    'message' => 'Achat effectué avec succès.',
                    'purchase_id' => $purchase->id,
                    'buyer_wallet' => [
                        'currency' => $currency,
                        'balance_minor' => (int) $buyerWallet->balance_minor,
                    ],
                    'seller_wallet' => [
                        'currency' => $currency,
                        'balance_minor' => (int) $sellerWallet->balance_minor,
                    ],
                    'wallet_transactions' => [
                        'buyer' => $buyerTx->id,
                        'seller' => $sellerTx->id,
                    ],
                    'offer_id' => $offer->id,
                    'offer_is_active' => (bool) $offer->is_active,
                ], 200);
            }, 3);
        } catch (\Throwable $e) {
            Log::error('Erreur achat interne marketplace', [
                'offer_id' => $id,
                'buyer_id' => $buyer ? $buyer->id : null,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Erreur lors de la transaction.'], 500);
        }
    }

    /**
     * Envoyer un message à l'annonceur
     */
    public function sendMessage(Request $request)
    {
        $user = $request->user();
        
        $validated = $request->validate([
            'offer_id' => 'required|exists:marketplace_offers,id',
            'seller_id' => 'required|exists:users,id',
            'message' => 'required|string|max:1000',
        ]);
        
        // Vérifier que l'offre existe et appartient au vendeur
        $offer = MarketplaceOffer::findOrFail($validated['offer_id']);
        if ($offer->user_id != $validated['seller_id']) {
            return response()->json([
                'message' => 'Le vendeur ne correspond pas à l\'offre.'
            ], 400);
        }
        
        // Vérifier que l'utilisateur ne s'envoie pas un message à lui-même
        if ($user->id == $validated['seller_id']) {
            return response()->json([
                'message' => 'Vous ne pouvez pas vous envoyer un message à vous-même.'
            ], 400);
        }
        
        try {
            // Créer le message dans la base de données
            $marketplaceMessage = MarketplaceMessage::create([
                'offer_id' => $validated['offer_id'],
                'sender_id' => $user->id,
                'receiver_id' => $validated['seller_id'],
                'message' => $validated['message'],
                'is_read' => false,
            ]);
            
            // TODO: Envoyer une notification par email au vendeur
            // $seller = \App\Models\User::findOrFail($validated['seller_id']);
            // Mail::to($seller->email)->send(new MarketplaceMessageMail($user, $offer, $validated['message']));
            
            Log::info('Message marketplace créé', [
                'message_id' => $marketplaceMessage->id,
                'from_user_id' => $user->id,
                'to_user_id' => $validated['seller_id'],
                'offer_id' => $offer->id,
            ]);
            
            return response()->json([
                'message' => 'Message envoyé à l\'annonceur avec succès.',
                'data' => $marketplaceMessage
            ], 201);
            
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'envoi du message: ' . $e->getMessage());
            return response()->json([
                'message' => 'Erreur lors de l\'envoi du message.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mettre à jour une offre
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $offer = MarketplaceOffer::findOrFail($id);
        
        // Vérifier que l'utilisateur est le propriétaire de l'offre
        if ($offer->user_id !== $user->id) {
            return response()->json([
                'message' => 'Vous n\'êtes pas autorisé à modifier cette offre.'
            ], 403);
        }
        
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'type' => 'sometimes|in:offer,product,service',
            'category' => 'sometimes|nullable|string|in:'.implode(',', MarketplaceOffer::allowedCategoryKeys()),
            'price' => 'sometimes|numeric|min:0',
            'currency' => 'nullable|string|max:3',
            'is_active' => 'sometimes|boolean',
            'images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);
        
        // Mettre à jour les champs de base
        if (isset($validated['title'])) $offer->title = $validated['title'];
        if (isset($validated['description'])) $offer->description = $validated['description'];
        if (isset($validated['type'])) $offer->type = $validated['type'];
        if (array_key_exists('category', $validated)) {
            $offer->category = $validated['category'] && in_array($validated['category'], MarketplaceOffer::allowedCategoryKeys(), true)
                ? $validated['category']
                : 'autre';
        }
        if (isset($validated['price'])) $offer->price = $validated['price'];
        if (isset($validated['currency'])) $offer->currency = $validated['currency'];
        if (isset($validated['is_active'])) $offer->is_active = $validated['is_active'];
        
        // Gérer l'upload de nouvelles images si fournies
        if ($request->hasFile('images')) {
            try {
                $images = $request->file('images');
                $compressionService = new ImageCompressionService();
                $imageUrls = [];
                
                foreach ($images as $image) {
                    $result = $compressionService->compressImage($image, 'marketplace/offers');
                    $imageUrls[] = Storage::disk('public')->url($result['path']);
                }
                
                // Ajouter les nouvelles images
                foreach ($imageUrls as $index => $url) {
                    $existingImagesCount = MarketplaceOfferImage::where('marketplace_offer_id', $offer->id)->count();
                    MarketplaceOfferImage::create([
                        'marketplace_offer_id' => $offer->id,
                        'image_url' => $url,
                        'order' => $existingImagesCount + $index,
                        'is_primary' => false,
                    ]);
                }
                
                // Mettre à jour l'image principale si c'est la première image
                if (!empty($imageUrls) && !$offer->image_url) {
                    $offer->image_url = $imageUrls[0];
                    MarketplaceOfferImage::where('marketplace_offer_id', $offer->id)
                        ->where('image_url', $imageUrls[0])
                        ->update(['is_primary' => true]);
                }
            } catch (\Exception $e) {
                Log::error('Erreur lors de l\'upload des images: ' . $e->getMessage());
            }
        }
        
        $offer->save();
        
        // Recharger les relations
        try {
            $offer->load('images', 'seller:id,name');
        } catch (\Exception $e) {
            $offer->load('seller:id,name');
        }
        
        return response()->json($offer, 200);
    }

    /**
     * Supprimer une offre
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $offer = MarketplaceOffer::findOrFail($id);
        
        // Vérifier que l'utilisateur est le propriétaire de l'offre
        if ($offer->user_id !== $user->id) {
            return response()->json([
                'message' => 'Vous n\'êtes pas autorisé à supprimer cette offre.'
            ], 403);
        }
        
        // Supprimer les images associées
        try {
            $images = MarketplaceOfferImage::where('marketplace_offer_id', $offer->id)->get();
            foreach ($images as $image) {
                // Extraire le chemin du fichier depuis l'URL
                $path = str_replace(Storage::disk('public')->url(''), '', $image->image_url);
                if ($path && Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
                $image->delete();
            }
        } catch (\Exception $e) {
            Log::warning('Erreur lors de la suppression des images: ' . $e->getMessage());
        }
        
        // Supprimer l'image principale si elle existe
        if ($offer->image_url) {
            $path = str_replace(Storage::disk('public')->url(''), '', $offer->image_url);
            if ($path && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }
        
        $offer->delete();
        
        return response()->json([
            'message' => 'Offre supprimée avec succès.'
        ], 200);
    }

    /**
     * Récupérer les statistiques d'une offre
     */
    public function getStats(Request $request, $id)
    {
        $user = $request->user();
        $offer = MarketplaceOffer::findOrFail($id);
        
        // Vérifier que l'utilisateur est le propriétaire de l'offre
        if ($offer->user_id !== $user->id) {
            return response()->json([
                'message' => 'Vous n\'êtes pas autorisé à voir les statistiques de cette offre.'
            ], 403);
        }
        
        $stats = [
            'total_views' => 0, // TODO: Implémenter le système de vues
            'total_favorites' => MarketplaceFavorite::where('offer_id', $offer->id)->count(),
            'total_reviews' => MarketplaceReview::where('offer_id', $offer->id)->count(),
            // avg() peut retourner une string selon le driver MySQL -> caster et arrondir
            'average_rating' => round((float) (MarketplaceReview::where('offer_id', $offer->id)->avg('rating') ?? 0), 1),
            'total_purchases' => MarketplacePurchase::where('offer_id', $offer->id)
                ->where('status', 'completed')
                ->count(),
            'total_messages' => MarketplaceMessage::where('offer_id', $offer->id)->count(),
            'revenue' => MarketplacePurchase::where('offer_id', $offer->id)
                ->where('status', 'completed')
                ->sum('price'),
        ];
        
        return response()->json($stats, 200);
    }

    /**
     * Récupérer tous les messages d'une offre (pour le vendeur)
     */
    public function getOfferMessages(Request $request, $id)
    {
        $user = $request->user();
        $offer = MarketplaceOffer::findOrFail($id);
        
        // ✅ CORRECTION : Vérifier que l'utilisateur est soit le vendeur, soit un participant à la conversation
        $isSeller = $offer->user_id === $user->id;
        $hasMessages = MarketplaceMessage::where('offer_id', $offer->id)
            ->where(function($query) use ($user) {
                $query->where('sender_id', $user->id)
                      ->orWhere('receiver_id', $user->id);
            })
            ->exists();
        
        if (!$isSeller && !$hasMessages) {
            return response()->json([
                'message' => 'Vous n\'êtes pas autorisé à voir les messages de cette offre.'
            ], 403);
        }
        
        try {
            $messages = MarketplaceMessage::where('offer_id', $offer->id)
                ->with(['sender', 'receiver', 'offer'])
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(function ($message) use ($user) {
                    return [
                        'id' => $message->id,
                        'offer_id' => $message->offer_id,
                        'offer_title' => $message->offer->title ?? 'Offre supprimée',
                        'sender_id' => $message->sender_id,
                        'sender_name' => $message->sender->name ?? 'Anonyme',
                        'receiver_id' => $message->receiver_id,
                        'receiver_name' => $message->receiver->name ?? 'Anonyme',
                        'message' => $message->message,
                        'is_read' => $message->is_read,
                        'read_at' => $message->read_at,
                        'created_at' => $message->created_at,
                        'is_from_me' => $message->sender_id === $user->id,
                    ];
                });
            
            // ✅ NOUVEAU : Marquer tous les messages comme lus quand on les ouvre
            MarketplaceMessage::where('offer_id', $offer->id)
                ->where('receiver_id', $user->id)
                ->where('is_read', false)
                ->update([
                    'is_read' => true,
                    'read_at' => now(),
                ]);
            
            return response()->json($messages, 200);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des messages: ' . $e->getMessage());
            return response()->json([
                'message' => 'Erreur lors de la récupération des messages.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Répondre à un message
     */
    public function replyToMessage(Request $request, $messageId)
    {
        $user = $request->user();
        
        $validated = $request->validate([
            'message' => 'required|string|max:1000',
        ]);
        
        try {
            $originalMessage = MarketplaceMessage::with(['offer', 'sender', 'receiver'])->findOrFail($messageId);
            
            // Vérifier que l'utilisateur est soit le vendeur (receiver) soit l'acheteur (sender)
            $isSeller = $originalMessage->offer->user_id === $user->id;
            $isBuyer = $originalMessage->sender_id === $user->id;
            
            if (!$isSeller && !$isBuyer) {
                return response()->json([
                    'message' => 'Vous n\'êtes pas autorisé à répondre à ce message.'
                ], 403);
            }
            
            // Déterminer le destinataire de la réponse
            $receiverId = $isSeller ? $originalMessage->sender_id : $originalMessage->receiver_id;
            
            // Créer la réponse
            $reply = MarketplaceMessage::create([
                'offer_id' => $originalMessage->offer_id,
                'sender_id' => $user->id,
                'receiver_id' => $receiverId,
                'message' => $validated['message'],
                'is_read' => false,
            ]);
            
            // Marquer le message original comme lu
            $originalMessage->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
            
            // Charger les relations pour la réponse
            $reply->load(['sender', 'receiver', 'offer']);
            
            return response()->json([
                'message' => 'Réponse envoyée avec succès.',
                'data' => [
                    'id' => $reply->id,
                    'sender_id' => $reply->sender_id,
                    'sender_name' => $reply->sender->name ?? 'Anonyme',
                    'receiver_id' => $reply->receiver_id,
                    'receiver_name' => $reply->receiver->name ?? 'Anonyme',
                    'message' => $reply->message,
                    'is_read' => $reply->is_read,
                    'created_at' => $reply->created_at,
                ]
            ], 201);
            
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'envoi de la réponse: ' . $e->getMessage());
            return response()->json([
                'message' => 'Erreur lors de l\'envoi de la réponse.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer tous les messages de l'utilisateur (en tant que vendeur ou acheteur)
     * ✅ AMÉLIORATION : Groupés par offre avec le dernier message et le nombre de non lus
     */
    public function getUserMessages(Request $request)
    {
        $user = $request->user();
        
        try {
            // Récupérer tous les messages de l'utilisateur
            $allMessages = MarketplaceMessage::where(function($query) use ($user) {
                    $query->where('sender_id', $user->id)
                          ->orWhere('receiver_id', $user->id);
                })
                ->with(['offer', 'sender', 'receiver'])
                ->orderBy('created_at', 'desc')
                ->get();
            
            // Grouper par offre et récupérer le dernier message de chaque conversation
            $groupedMessages = $allMessages->groupBy('offer_id');
            $conversations = [];
            
            foreach ($groupedMessages as $offerId => $messages) {
                $lastMessage = $messages->first(); // Le plus récent (déjà trié par desc)
                $offer = $lastMessage->offer;
                
                // Compter les messages non lus pour cette offre
                $unreadCount = $messages->where('receiver_id', $user->id)
                    ->where('is_read', false)
                    ->count();
                
                // Déterminer l'autre utilisateur (celui avec qui on converse)
                $otherUser = $lastMessage->sender_id === $user->id 
                    ? $lastMessage->receiver 
                    : $lastMessage->sender;
                
                $conversations[] = [
                    'offer_id' => $offerId,
                    'offer_title' => $offer->title ?? 'Offre supprimée',
                    'offer_image_url' => $offer->image_url ?? null,
                    'other_user_id' => $otherUser->id ?? null,
                    'other_user_name' => $otherUser->name ?? 'Anonyme',
                    'last_message_id' => $lastMessage->id,
                    'last_message' => $lastMessage->message,
                    'last_message_sender_id' => $lastMessage->sender_id,
                    'last_message_sender_name' => $lastMessage->sender->name ?? 'Anonyme',
                    'last_message_created_at' => $lastMessage->created_at,
                    'unread_count' => $unreadCount,
                    'is_from_me' => $lastMessage->sender_id === $user->id,
                    'total_messages' => $messages->count(),
                ];
            }
            
            // Trier par date du dernier message (plus récent en premier)
            usort($conversations, function($a, $b) {
                return strtotime($b['last_message_created_at']) - strtotime($a['last_message_created_at']);
            });
            
            return response()->json($conversations, 200);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des messages utilisateur: ' . $e->getMessage());
            return response()->json([
                'message' => 'Erreur lors de la récupération des messages.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * ✅ NOUVEAU : Récupérer le nombre de messages non lus pour l'utilisateur
     */
    public function getUnreadMessagesCount(Request $request)
    {
        $user = $request->user();
        
        try {
            $unreadCount = MarketplaceMessage::where('receiver_id', $user->id)
                ->where('is_read', false)
                ->count();
            
            return response()->json([
                'unread_count' => $unreadCount
            ], 200);
        } catch (\Exception $e) {
            Log::error('Erreur lors du comptage des messages non lus: ' . $e->getMessage());
            return response()->json([
                'unread_count' => 0
            ], 200);
        }
    }

    /**
     * Détermine les besoins de l'utilisateur basés sur son titre/poste et son site web ou document
     * 
     * @param User|null $user
     * @return array
     */
    private function determineUserNeeds(?User $user): array
    {
        if (!$user) {
            return ['keywords' => []];
        }

        // Utiliser le cache pour éviter les appels API répétés (cache de 24h)
        $cacheKey = "user_needs_{$user->id}";
        $cachedNeeds = Cache::get($cacheKey);
        
        if ($cachedNeeds !== null) {
            return $cachedNeeds;
        }

        $needs = ['keywords' => []];
        $userTitle = $user->title;
        $websiteUrl = $user->website_url;

        // Pour les business_admin, récupérer aussi les services et company_website_url
        if ($user->role === 'business_admin') {
            $companyPage = CompanyPage::where('user_id', $user->id)->first();
            if ($companyPage) {
                if (empty($websiteUrl) && !empty($companyPage->company_website_url)) {
                    $websiteUrl = $companyPage->company_website_url;
                }
                
                // Extraire les mots-clés des services
                if (!empty($companyPage->services) && is_array($companyPage->services)) {
                    foreach ($companyPage->services as $service) {
                        $serviceTitle = $service['title'] ?? $service['name'] ?? '';
                        if (!empty($serviceTitle)) {
                            $needs['keywords'][] = strtolower($serviceTitle);
                        }
                    }
                }
            }
        }

        // Si l'utilisateur a un site web, utiliser Perplexity AI pour explorer
        if (!empty($websiteUrl)) {
            try {
                $perplexityService = new PerplexityService();
                $websiteNeeds = $perplexityService->exploreWebsiteAndDetermineNeeds($websiteUrl, $userTitle);
                
                if ($websiteNeeds && !empty($websiteNeeds['keywords'])) {
                    $needs['keywords'] = array_merge($needs['keywords'], $websiteNeeds['keywords']);
                }
            } catch (\Exception $e) {
                Log::warning('Erreur lors de l\'exploration du site web avec Perplexity', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Ajouter le titre/poste comme mot-clé
        if (!empty($userTitle)) {
            $needs['keywords'][] = strtolower($userTitle);
        }

        // Nettoyer et dédupliquer les mots-clés
        $needs['keywords'] = array_unique(array_filter($needs['keywords']));

        // Mettre en cache pour 24h
        Cache::put($cacheKey, $needs, now()->addHours(24));

        return $needs;
    }

    /**
     * Récupérer les notifications de matching pour l'utilisateur connecté
     */
    public function getMatchNotifications(Request $request)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['notifications' => [], 'unread_count' => 0], 200);
        }
        
        try {
            // Récupérer les notifications de matching non lues
            $notifications = $user->notifications()
                ->where('type', 'App\Notifications\MarketplaceMatchNotification')
                ->orderBy('created_at', 'desc')
                ->take(20)
                ->get()
                ->map(function ($notification) {
                    $data = $notification->data;
                    return [
                        'id' => $notification->id,
                        'type' => $data['type'] ?? 'marketplace_match',
                        'offer_id' => $data['offer_id'] ?? null,
                        'offer_title' => $data['offer_title'] ?? 'Offre',
                        'match_score' => $data['match_score'] ?? 0,
                        'message' => $data['message'] ?? 'Nouvelle offre correspondant à votre profil',
                        'url' => $data['url'] ?? null,
                        'read_at' => $notification->read_at,
                        'created_at' => $notification->created_at,
                    ];
                });
            
            // Compter les notifications non lues
            $unreadCount = $user->unreadNotifications()
                ->where('type', 'App\Notifications\MarketplaceMatchNotification')
                ->count();
            
            return response()->json([
                'notifications' => $notifications,
                'unread_count' => $unreadCount,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des notifications de matching', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            return response()->json(['notifications' => [], 'unread_count' => 0], 200);
        }
    }

    /**
     * Marquer une notification comme lue
     */
    public function markNotificationAsRead(Request $request, $notificationId)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['message' => 'Non autorisé'], 401);
        }
        
        try {
            $notification = $user->notifications()->find($notificationId);
            
            if ($notification) {
                $notification->markAsRead();
                return response()->json(['message' => 'Notification marquée comme lue'], 200);
            }
            
            return response()->json(['message' => 'Notification non trouvée'], 404);
        } catch (\Exception $e) {
            Log::error('Erreur lors du marquage de la notification comme lue', [
                'user_id' => $user->id,
                'notification_id' => $notificationId,
                'error' => $e->getMessage()
            ]);
            return response()->json(['message' => 'Erreur lors du traitement'], 500);
        }
    }

    /**
     * Vérifie si le profil d'un utilisateur est complet
     * Un profil complet doit avoir : photo, titre/poste, et au moins 3 réseaux sociaux
     * 
     * @param User|null $seller
     * @return bool
     */
    private function isProfileComplete(?User $seller): bool
    {
        if (!$seller) {
            return false;
        }

        $hasPhoto = !empty($seller->avatar_url);
        $hasTitle = !empty($seller->title);
        
        // Compter les réseaux sociaux
        $socialCount = 0;
        if (!empty($seller->whatsapp_url)) $socialCount++;
        if (!empty($seller->linkedin_url)) $socialCount++;
        if (!empty($seller->facebook_url)) $socialCount++;
        if (!empty($seller->twitter_url)) $socialCount++;
        if (!empty($seller->youtube_url)) $socialCount++;
        if (!empty($seller->tiktok_url)) $socialCount++;
        if (!empty($seller->threads_url)) $socialCount++;
        
        $hasThreeSocials = $socialCount >= 3;

        return $hasPhoto && $hasTitle && $hasThreeSocials;
    }
}
