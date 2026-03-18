<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceOffer;
use App\Models\MarketplacePurchase;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\MarketplaceTransactionNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MarketplacePurchaseController extends Controller
{
    public function index(Request $request)
    {
        $query = MarketplacePurchase::query()
            ->with([
                'buyer:id,name,email',
                'offer:id,title,user_id,price,currency,is_active',
                'offer.seller:id,name,email',
            ]);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('currency')) {
            $query->where('currency', strtoupper((string) $request->query('currency')));
        }

        if ($request->filled('buyer_id') && is_numeric($request->query('buyer_id'))) {
            $query->where('buyer_id', (int) $request->query('buyer_id'));
        }

        if ($request->filled('offer_id') && is_numeric($request->query('offer_id'))) {
            $query->where('offer_id', (int) $request->query('offer_id'));
        }

        $query->orderByDesc('id');

        $perPage = (int) $request->query('per_page', 20);
        if ($perPage < 1) $perPage = 1;
        if ($perPage > 100) $perPage = 100;

        return response()->json($query->paginate($perPage));
    }

    public function show(MarketplacePurchase $purchase)
    {
        $purchase->load([
            'buyer:id,name,email',
            'offer',
            'offer.seller:id,name,email',
        ]);

        return response()->json(['purchase' => $purchase], 200);
    }

    /**
     * Admin : approuver le remboursement après litige.
     * - Débite le wallet vendeur
     * - Crédite le wallet acheteur
     * - Met à jour le statut fulfillment_status
     */
    public function approveRefund(Request $request, MarketplacePurchase $purchase)
    {
        $validated = $request->validate([
            'refund_reason' => 'nullable|string|max:1000',
        ]);

        $purchase->loadMissing('offer');
        $offer = $purchase->offer;
        if (! $offer) {
            return response()->json(['message' => 'Offre introuvable pour cette commande.'], 404);
        }

        $fs = (string) ($purchase->fulfillment_status ?? MarketplacePurchase::FULFILLMENT_COMPLETED);
        if ($fs !== MarketplacePurchase::FULFILLMENT_DISPUTE_REQUESTED) {
            return response()->json(['message' => 'Remboursement impossible : litige non ouvert.'], 422);
        }

        $currency = strtoupper((string) $purchase->currency ?: 'EUR');
        $amountMinor = $this->moneyToMinor($purchase->price, $currency);
        if ($amountMinor <= 0) {
            return response()->json(['message' => 'Montant invalide.'], 422);
        }

        $buyerId = (int) $purchase->buyer_id;
        $sellerId = (int) $offer->user_id;

        try {
            return DB::transaction(function () use ($purchase, $offer, $buyerId, $sellerId, $currency, $amountMinor, $validated) {
                $purchase = $purchase->fresh(['offer']);
                $fsNow = (string) ($purchase->fulfillment_status ?? MarketplacePurchase::FULFILLMENT_COMPLETED);
                if ($fsNow !== MarketplacePurchase::FULFILLMENT_DISPUTE_REQUESTED) {
                    return response()->json(['message' => 'État modifié entre temps.'], 409);
                }

                $buyerWallet = Wallet::firstOrCreate(
                    ['user_id' => $buyerId, 'currency' => $currency],
                    ['balance_minor' => 0],
                );
                $sellerWallet = Wallet::firstOrCreate(
                    ['user_id' => $sellerId, 'currency' => $currency],
                    ['balance_minor' => 0],
                );

                $buyerWallet = Wallet::where('id', $buyerWallet->id)->lockForUpdate()->first();
                $sellerWallet = Wallet::where('id', $sellerWallet->id)->lockForUpdate()->first();

                if ((int) $sellerWallet->balance_minor < $amountMinor) {
                    return response()->json([
                        'message' => 'Solde vendeur insuffisant pour effectuer le remboursement.',
                        'seller_balance_minor' => (int) $sellerWallet->balance_minor,
                        'required_minor' => $amountMinor,
                    ], 402);
                }

                $refundIdempotencyKey = (string) Str::uuid();

                // Ledger : débit vendeur
                WalletTransaction::create([
                    'wallet_id' => $sellerWallet->id,
                    'user_id' => $sellerId,
                    'direction' => 'debit',
                    'type' => 'adjustment',
                    'amount_minor' => $amountMinor,
                    'currency' => $currency,
                    'status' => 'completed',
                    'marketplace_offer_id' => $offer->id,
                    'marketplace_purchase_id' => $purchase->id,
                    'idempotency_key' => $refundIdempotencyKey,
                    'meta' => [
                        'refund_for_purchase_id' => $purchase->id,
                        'refund_to_buyer_id' => $buyerId,
                        'action' => 'refund_debit_seller',
                    ],
                    'completed_at' => now(),
                ]);

                // Ledger : crédit acheteur (même idempotency key corrélée => unique constraint, donc on génère une 2e clé)
                WalletTransaction::create([
                    'wallet_id' => $buyerWallet->id,
                    'user_id' => $buyerId,
                    'direction' => 'credit',
                    'type' => 'adjustment',
                    'amount_minor' => $amountMinor,
                    'currency' => $currency,
                    'status' => 'completed',
                    'marketplace_offer_id' => $offer->id,
                    'marketplace_purchase_id' => $purchase->id,
                    'idempotency_key' => (string) Str::uuid(),
                    'meta' => [
                        'refund_for_purchase_id' => $purchase->id,
                        'refund_from_seller_id' => $sellerId,
                        'action' => 'refund_credit_buyer',
                    ],
                    'completed_at' => now(),
                ]);

                $sellerWallet->balance_minor = (int) $sellerWallet->balance_minor - $amountMinor;
                $buyerWallet->balance_minor = (int) $buyerWallet->balance_minor + $amountMinor;
                $sellerWallet->save();
                $buyerWallet->save();

                $purchase->fulfillment_status = MarketplacePurchase::FULFILLMENT_REFUNDED;
                $purchase->admin_decided_at = now();
                $purchase->refund_processed_at = now();
                $purchase->refund_reason = $validated['refund_reason'] ?? null;
                $purchase->save();

                // On rend l'offre à nouveau disponible (si tu veux un comportement différent, on adapte).
                $offer->is_active = true;
                $offer->save();

                $buyer = User::find($buyerId);
                if ($buyer) {
                    $buyer->notify(new MarketplaceTransactionNotification(
                        message: 'Votre litige a été accepté. Remboursement effectué : '.$offer->title,
                        offerId: (int) $offer->id,
                        purchaseId: (int) $purchase->id,
                        otherUserId: (int) $sellerId,
                    ));
                }
                $seller = User::find($sellerId);
                if ($seller) {
                    $seller->notify(new MarketplaceTransactionNotification(
                        message: 'Un remboursement a été effectué pour une commande : '.$offer->title,
                        offerId: (int) $offer->id,
                        purchaseId: (int) $purchase->id,
                        otherUserId: (int) $buyerId,
                    ));
                }

                return response()->json([
                    'message' => 'Remboursement effectué avec succès.',
                    'purchase' => $purchase->fresh(),
                ], 200);
            });
        } catch (\Throwable $e) {
            Log::error('Admin: Erreur approveRefund marketplace purchase', [
                'purchase_id' => $purchase->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Erreur lors du remboursement.'], 500);
        }
    }

    /**
     * Admin : refuser le litige (on remet une étape cohérente).
     */
    public function denyDispute(Request $request, MarketplacePurchase $purchase)
    {
        $validated = $request->validate([
            'refund_reason' => 'nullable|string|max:1000',
        ]);

        $purchase->loadMissing('offer');
        $offer = $purchase->offer;
        if (! $offer) {
            return response()->json(['message' => 'Offre introuvable pour cette commande.'], 404);
        }

        $fs = (string) ($purchase->fulfillment_status ?? MarketplacePurchase::FULFILLMENT_COMPLETED);
        if ($fs !== MarketplacePurchase::FULFILLMENT_DISPUTE_REQUESTED) {
            return response()->json(['message' => 'Action impossible : litige non ouvert.'], 422);
        }

        $purchase->fulfillment_status = $purchase->seller_fulfilled_at ? MarketplacePurchase::FULFILLMENT_AWAITING_BUYER : MarketplacePurchase::FULFILLMENT_PENDING;
        $purchase->admin_decided_at = now();
        $purchase->refund_reason = $validated['refund_reason'] ?? null;
        $purchase->save();

        return response()->json([
            'message' => 'Litige refusé. Statut mis à jour.',
            'purchase' => $purchase->fresh(),
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
        $factor = $this->currencyFactor($currency);
        return (int) round(((float) $amount) * $factor);
    }
}

