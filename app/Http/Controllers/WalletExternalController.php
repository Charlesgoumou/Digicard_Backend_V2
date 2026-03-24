<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\ChapChapPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WalletExternalController extends Controller
{
    private function currencyFactor(string $currency): int
    {
        $c = strtoupper($currency);
        if (in_array($c, ['GNF', 'XOF'], true)) return 1;
        return 100;
    }

    private function moneyToMinor($amount, string $currency = 'EUR'): int
    {
        return (int) round(((float) $amount) * $this->currencyFactor($currency));
    }

    private function verifyChapChapWebhookSignature(Request $request): bool
    {
        $secret = env('CHAP_CHAP_SECRET_KEY', '');
        if (empty($secret)) {
            // Sans secret, on ne peut pas vérifier.
            return false;
        }

        $provided = $request->header('CCP-HMAC-Signature');
        if (!$provided) {
            return false;
        }

        $raw = $request->getContent();
        $expected = hash_hmac('sha256', $raw, $secret);

        return hash_equals((string) $expected, (string) $provided);
    }

    /**
     * Initier une recharge via Chap Chap Pay.
     * Retourne un payment_url à ouvrir côté frontend.
     */
    public function initiateChapChapDeposit(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.5',
            'currency' => 'sometimes|string|size:3',
        ]);

        $currency = strtoupper($validated['currency'] ?? 'EUR');
        $amountMinor = $this->moneyToMinor($validated['amount'], $currency);
        if ($amountMinor <= 0) {
            return response()->json(['message' => 'Montant invalide.'], 422);
        }

        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user->id, 'currency' => $currency],
            ['balance_minor' => 0],
        );

        $reference = 'wallet_deposit_' . $user->id . '_' . Str::uuid()->toString();

        // Créer la transaction en pending (idempotence par external_reference)
        $tx = WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'direction' => 'credit',
            'type' => 'deposit_external',
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'status' => 'pending',
            'external_provider' => 'chapchappay',
            'external_reference' => $reference,
            'meta' => [
                'initiated_by' => 'user',
            ],
        ]);

        $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');
        $notifyUrl = url('/') . '/api/wallet/webhook/chapchappay';
        $returnUrl = $frontendUrl . '/mon-solde?deposit=return&ref=' . urlencode($reference);

        // ChapChapPay attend un montant entier (selon leur intégration e-commerce).
        // Ici on envoie en "minor units" pour rester cohérent.
        $service = new ChapChapPayService();
        $payload = [
            'amount' => $amountMinor,
            'description' => "Recharge solde DigiCard ({$currency})",
            'order_id' => $reference,
            'return_url' => $returnUrl,
            'notify_url' => $notifyUrl,
            'options' => ['auto-redirect' => true],
        ];

        $resp = $service->createPaymentLink($payload);
        if (!$resp || empty($resp['payment_url'])) {
            $tx->status = 'failed';
            $tx->save();
            return response()->json(['message' => 'Impossible d’initier le paiement.'], 502);
        }

        // Conserver des infos utiles si présentes
        $tx->meta = array_merge($tx->meta ?? [], [
            'chapchappay_response' => $resp,
        ]);
        $tx->save();

        return response()->json([
            'reference' => $reference,
            'payment_url' => $resp['payment_url'],
        ], 200);
    }

    /**
     * Webhook Chap Chap Pay pour valider une recharge.
     * Route publique (Chap Chap Pay appelle depuis l'extérieur).
     */
    public function chapChapWebhook(Request $request)
    {
        try {
            // ✅ Sécurité webhook: signature HMAC (en production obligatoire)
            $signatureOk = $this->verifyChapChapWebhookSignature($request);
            $isProd = app()->environment('production');
            if ($isProd && !$signatureOk) {
                Log::warning('Wallet webhook: signature HMAC invalide', [
                    'has_signature' => (bool) $request->header('CCP-HMAC-Signature'),
                    'content_type' => $request->header('Content-Type'),
                ]);
                return response()->json(['message' => 'Signature invalide.'], 401);
            }
            if (!$isProd && !$signatureOk) {
                Log::info('Wallet webhook: signature non vérifiée (env non-prod)', [
                    'has_secret' => !empty(env('CHAP_CHAP_SECRET_KEY', '')),
                    'has_signature' => (bool) $request->header('CCP-HMAC-Signature'),
                ]);
            }

            // Reprendre la stratégie robuste de parsing déjà utilisée dans OrderController
            $data = $request->all();

            $needsJsonDecode = false;
            if (is_array($data) && count($data) === 1 && isset($data[0]) && is_string($data[0])) {
                $testDecode = json_decode($data[0], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($testDecode)) {
                    $needsJsonDecode = true;
                }
            }

            if (empty($data) || $needsJsonDecode) {
                $content = $request->getContent();
                if ($needsJsonDecode && isset($data[0])) {
                    $content = $data[0];
                }
                $decoded = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $data = $decoded;
                } else if (empty($data)) {
                    $data = [];
                }
            }

            if (empty($data)) {
                parse_str($request->getContent(), $parsed);
                if (!empty($parsed)) $data = $parsed;
            }

            $reference = $data['order_id'] ?? null;
            $rawStatus = $data['status'] ?? null;
            $status = null;
            if (is_array($rawStatus) && isset($rawStatus['code'])) $status = $rawStatus['code'];
            else if (is_string($rawStatus)) $status = $rawStatus;

            if (!$reference || !$status) {
                Log::error('Wallet webhook: données incomplètes', ['data' => $data]);
                return response()->json(['message' => 'Données incomplètes.'], 400);
            }

            $ref = (string) $reference;
            $isDeposit = str_starts_with($ref, 'wallet_deposit_');
            $isWithdraw = str_starts_with($ref, 'wallet_withdraw_');
            if (!$isDeposit && !$isWithdraw) {
                return response()->json(['message' => 'Ignoré.'], 200);
            }

            return DB::transaction(function () use ($reference, $status, $data) {
                $tx = WalletTransaction::where('external_provider', 'chapchappay')
                    ->where('external_reference', $reference)
                    ->lockForUpdate()
                    ->first();

                if (!$tx) {
                    Log::error('Wallet webhook: transaction introuvable', ['reference' => $reference]);
                    return response()->json(['message' => 'Transaction non trouvée.'], 404);
                }

                // Idempotent: si déjà completed, OK
                if (in_array($tx->status, ['completed', 'failed', 'cancelled'], true)) {
                    return response()->json(['message' => 'Déjà traité.'], 200);
                }

                if ($status === 'success' || $status === 'paid' || $status === 'completed') {
                    // Deposit: créditer le wallet. Withdraw: le wallet a déjà été débité à l’initiation.
                    if ($tx->type === 'deposit_external') {
                        $wallet = Wallet::where('id', $tx->wallet_id)->lockForUpdate()->first();
                        if (!$wallet) {
                            return response()->json(['message' => 'Wallet non trouvé.'], 404);
                        }

                        $wallet->balance_minor = (int) $wallet->balance_minor + (int) $tx->amount_minor;
                        $wallet->save();
                    }

                    $tx->status = 'completed';
                    $tx->completed_at = now();
                    $tx->meta = array_merge($tx->meta ?? [], [
                        'webhook' => $data,
                    ]);
                    $tx->save();

                    return response()->json(['message' => 'OK'], 200);
                }

                // échec / annulation
                $finalStatus = in_array($status, ['cancelled', 'canceled', 'expired'], true) ? 'cancelled' : 'failed';

                // En cas d’échec d’un retrait, rembourser automatiquement via un crédit d’ajustement
                if ($tx->type === 'withdraw_external') {
                    $wallet = Wallet::where('id', $tx->wallet_id)->lockForUpdate()->first();
                    if ($wallet) {
                        $alreadyRefunded = isset(($tx->meta ?? [])['refunded_tx_id']);
                        if (!$alreadyRefunded) {
                            $wallet->balance_minor = (int) $wallet->balance_minor + (int) $tx->amount_minor;
                            $wallet->save();

                            $refundTx = WalletTransaction::create([
                                'wallet_id' => $wallet->id,
                                'user_id' => $tx->user_id,
                                'direction' => 'credit',
                                'type' => 'adjustment',
                                'amount_minor' => (int) $tx->amount_minor,
                                'currency' => $tx->currency,
                                'status' => 'completed',
                                'external_provider' => 'system',
                                'external_reference' => 'refund_' . $tx->id,
                                'meta' => [
                                    'reason' => 'withdraw_failed_refund',
                                    'refund_for_wallet_transaction_id' => $tx->id,
                                    'provider_status' => $status,
                                ],
                                'completed_at' => now(),
                            ]);

                            $tx->meta = array_merge($tx->meta ?? [], [
                                'refunded_tx_id' => $refundTx->id,
                            ]);
                        }
                    }
                }

                $tx->status = $finalStatus;
                $tx->meta = array_merge($tx->meta ?? [], [
                    'webhook' => $data,
                ]);
                $tx->save();

                return response()->json(['message' => 'OK'], 200);
            }, 3);
        } catch (\Throwable $e) {
            Log::error('Wallet webhook: erreur', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Erreur webhook.'], 500);
        }
    }

    /**
     * Initier une demande de retrait.
     * (Préparé pour intégration provider: transaction en pending, à compléter par payout provider + webhook.)
     */
    public function initiateWithdraw(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.5',
            'currency' => 'sometimes|string|size:3',
            'destination' => 'required|string|max:64', // numéro ou identifiant Mobile Money
            'provider' => 'required|string|max:32', // opérateur/méthode: mtn/orange/wave...
        ]);

        $currency = strtoupper($validated['currency'] ?? 'EUR');
        $amountMinor = $this->moneyToMinor($validated['amount'], $currency);
        if ($amountMinor <= 0) {
            return response()->json(['message' => 'Montant invalide.'], 422);
        }

        return DB::transaction(function () use ($user, $currency, $amountMinor, $validated) {
            $wallet = Wallet::firstOrCreate(
                ['user_id' => $user->id, 'currency' => $currency],
                ['balance_minor' => 0],
            );
            $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

            if ((int) $wallet->balance_minor < $amountMinor) {
                return response()->json([
                    'message' => 'Solde insuffisant. Veuillez recharger votre solde.',
                    'required_minor' => $amountMinor,
                    'balance_minor' => (int) $wallet->balance_minor,
                    'currency' => $currency,
                ], 402);
            }

            // Débiter immédiatement et mettre en pending (sécurise le solde).
            // En cas d'échec provider, on créditera via un tx d'ajustement.
            $wallet->balance_minor = (int) $wallet->balance_minor - $amountMinor;
            $wallet->save();

            $reference = 'wallet_withdraw_' . $user->id . '_' . Str::uuid()->toString();

            $tx = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'direction' => 'debit',
                'type' => 'withdraw_external',
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'status' => 'pending',
                // Provider de paiement (Chap Chap Pay) + opérateur dans meta
                'external_provider' => 'chapchappay',
                'external_reference' => $reference,
                'meta' => [
                    'destination' => $validated['destination'],
                    'operator' => $validated['provider'],
                ],
            ]);

            // Déclencher le payout via Chap Chap Pay PUSH API
            $notifyUrl = url('/') . '/api/wallet/webhook/chapchappay';
            $service = new ChapChapPayService();
                $resp = $service->createPushOperation([
                    'amount' => $amountMinor,
                'description' => "Retrait solde DigiCard ({$currency})",
                'order_id' => $reference,
                'notify_url' => $notifyUrl,
                'provider' => $validated['provider'],
                'destination' => $validated['destination'],
            ]);

            if (!$resp) {
                // Échec immédiat -> remboursement via adjustment
                $wallet->balance_minor = (int) $wallet->balance_minor + $amountMinor;
                $wallet->save();

                $refundTx = WalletTransaction::create([
                    'wallet_id' => $wallet->id,
                    'user_id' => $user->id,
                    'direction' => 'credit',
                    'type' => 'adjustment',
                    'amount_minor' => $amountMinor,
                    'currency' => $currency,
                    'status' => 'completed',
                    'external_provider' => 'system',
                    'external_reference' => 'refund_' . $tx->id,
                    'meta' => [
                        'reason' => 'withdraw_push_initiation_failed',
                        'refund_for_wallet_transaction_id' => $tx->id,
                    ],
                    'completed_at' => now(),
                ]);

                $tx->status = 'failed';
                $tx->meta = array_merge($tx->meta ?? [], [
                    'push_response' => $resp,
                    'refunded_tx_id' => $refundTx->id,
                ]);
                $tx->save();

                return response()->json([
                    'message' => 'Impossible d’initier le retrait. Remboursement effectué.',
                ], 502);
            }

            $tx->meta = array_merge($tx->meta ?? [], [
                'push_response' => $resp,
            ]);
            $tx->save();

            return response()->json([
                'message' => 'Demande de retrait créée. Traitement en cours.',
                'reference' => $reference,
                'transaction_id' => $tx->id,
            ], 200);
        }, 3);
    }
}

