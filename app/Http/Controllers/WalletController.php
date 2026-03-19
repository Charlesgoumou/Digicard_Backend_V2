<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    private function currencyFactor(string $currency): int
    {
        $c = strtoupper($currency);
        // Monnaies sans décimales (pas de centimes)
        if (in_array($c, ['GNF', 'XOF'], true)) return 1;
        return 100;
    }

    public function show(Request $request)
    {
        $user = $request->user();
        $currency = strtoupper($request->query('currency', 'EUR'));
        $factor = $this->currencyFactor($currency);

        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user->id, 'currency' => $currency],
            ['balance_minor' => 0],
        );

        return response()->json([
            'currency' => $wallet->currency,
            'balance_minor' => (int) $wallet->balance_minor,
            'balance' => $factor === 1
                ? (int) $wallet->balance_minor
                : round(((int) $wallet->balance_minor) / $factor, 2),
        ], 200);
    }

    public function transactions(Request $request)
    {
        $user = $request->user();
        $currency = strtoupper($request->query('currency', 'EUR'));
        $factor = $this->currencyFactor($currency);
        $limit = (int) $request->query('limit', 30);
        if ($limit < 1) $limit = 1;
        if ($limit > 100) $limit = 100;
        $beforeId = $request->query('before_id'); // pagination "load more"
        $type = $request->query('type'); // optional
        $status = $request->query('status'); // optional
        $q = trim((string) $request->query('q', '')); // optional search

        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user->id, 'currency' => $currency],
            ['balance_minor' => 0],
        );

        $query = WalletTransaction::where('wallet_id', $wallet->id);

        if ($beforeId !== null && is_numeric($beforeId)) {
            $query->where('id', '<', (int) $beforeId);
        }

        if (!empty($type) && is_string($type)) {
            $query->where('type', $type);
        }

        if (!empty($status) && is_string($status)) {
            $query->where('status', $status);
        }

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('external_reference', 'like', '%' . $q . '%')
                    ->orWhere('external_provider', 'like', '%' . $q . '%')
                    ->orWhere('type', 'like', '%' . $q . '%')
                    ->orWhere('status', 'like', '%' . $q . '%');
            });
        }

        $items = $query
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (WalletTransaction $tx) use ($factor) {
                return [
                    'id' => $tx->id,
                    'direction' => $tx->direction,
                    'type' => $tx->type,
                    'status' => $tx->status,
                    'amount_minor' => (int) $tx->amount_minor,
                    'amount' => $factor === 1
                        ? (int) $tx->amount_minor
                        : round(((int) $tx->amount_minor) / $factor, 2),
                    'currency' => $tx->currency,
                    'marketplace_offer_id' => $tx->marketplace_offer_id,
                    'marketplace_purchase_id' => $tx->marketplace_purchase_id,
                    'external_provider' => $tx->external_provider,
                    'external_reference' => $tx->external_reference,
                    'meta' => $tx->meta,
                    'created_at' => $tx->created_at,
                ];
            });

        $nextBeforeId = null;
        if ($items->count() > 0) {
            $nextBeforeId = $items->last()['id'] ?? null;
        }

        return response()->json([
            'currency' => $wallet->currency,
            'balance_minor' => (int) $wallet->balance_minor,
            'balance' => $factor === 1
                ? (int) $wallet->balance_minor
                : round(((int) $wallet->balance_minor) / $factor, 2),
            'transactions' => $items,
            'next_before_id' => $nextBeforeId,
        ], 200);
    }
}

