<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class ChapChapPayService
{
    protected $baseUrl;
    protected $apiKey;
    protected $hmacKey;

    public function __construct()
    {
        // ✅ MODIFICATION: Charger directement depuis env() si config() retourne null
        // Car config() peut retourner null si la clé n'existe pas dans services.php
        $configBaseUrl = config('services.chapchappay.base_url');
        $configApiKey = config('services.chapchappay.public_key');
        
        $this->baseUrl = $configBaseUrl ?: env('CHAP_CHAP_BASE_URL', 'https://api.chapchappay.com/api');
        $this->apiKey = $configApiKey ?: env('CHAP_CHAP_PUBLIC_KEY', '');
        $this->hmacKey = env('CHAP_CHAP_SECRET_KEY', '');
        
        // Log pour déboguer la configuration
        Log::debug('Chap Chap Pay Service: Configuration initialisée', [
            'base_url' => $this->baseUrl,
            'api_key_present' => !empty($this->apiKey),
            'api_key_length' => $this->apiKey ? strlen($this->apiKey) : 0,
            'env_chap_chap_base_url' => env('CHAP_CHAP_BASE_URL'),
            'env_chap_chap_public_key' => env('CHAP_CHAP_PUBLIC_KEY') ? 'present (length: ' . strlen(env('CHAP_CHAP_PUBLIC_KEY')) . ')' : 'missing',
            'config_base_url' => $configBaseUrl,
            'config_public_key' => $configApiKey ? 'present (length: ' . strlen($configApiKey) . ')' : 'missing',
            'final_base_url' => $this->baseUrl,
            'final_api_key_present' => !empty($this->apiKey),
        ]);
        
        if (empty($this->apiKey)) {
            Log::error('Chap Chap Pay Service: Clé API manquante ! Vérifiez votre fichier .env (CHAP_CHAP_PUBLIC_KEY)', [
                'instructions' => 'Ajoutez CHAP_CHAP_PUBLIC_KEY=votre_clé_api dans votre fichier .env et redémarrez le serveur Laravel',
                'env_file_location' => base_path('.env'),
            ]);
        }
    }

    /**
     * Créer un lien de paiement via l'API Chap Chap Pay
     *
     * @param array $data
     * @return array|null
     */
    public function createPaymentLink(array $data)
    {
        // ✅ VALIDATION: Vérifier que la clé API est présente
        if (empty($this->apiKey)) {
            Log::error('Chap Chap Pay: Clé API manquante ! Impossible de créer le lien de paiement', [
                'order_id' => $data['order_id'] ?? null,
                'amount' => $data['amount'] ?? null,
                'base_url' => $this->baseUrl,
                'env_chap_chap_public_key' => env('CHAP_CHAP_PUBLIC_KEY') ? 'present but empty' : 'missing from .env',
                'config_public_key' => config('services.chapchappay.public_key') ? 'present but empty' : 'missing from config',
            ]);
            return null;
        }
        
        try {
            Log::info('Chap Chap Pay: Début de la création du lien de paiement', [
                'order_id' => $data['order_id'] ?? null,
                'amount' => $data['amount'] ?? null,
                'base_url' => $this->baseUrl,
                'api_key_present' => !empty($this->apiKey),
                'api_key_length' => strlen($this->apiKey),
            ]);

            // ✅ MODIFICATION: Désactiver la vérification SSL pour localhost
            // ✅ MODIFICATION: Construire le payload sans fee_handling (non disponible pour votre entreprise)
            $payload = [
                'amount' => $data['amount'], // Montant en entier (ex: 180000 pour 1800.00 GNF)
                'description' => $data['description'],
                'order_id' => $data['order_id'], // Référence unique de la commande
                // ✅ fee_handling supprimé car non disponible pour votre entreprise
                'return_url' => $data['return_url'], // URL de retour après paiement
                'notify_url' => $data['notify_url'], // URL de webhook pour les notifications
                'options' => [
                    'auto-redirect' => $data['options']['auto-redirect'] ?? true,
                ],
            ];
            
            // ✅ AJOUT: Logger le payload pour vérifier que le return_url est correctement configuré
            Log::info('Chap Chap Pay: Payload envoyé à l\'API', [
                'order_id' => $data['order_id'] ?? null,
                'return_url' => $payload['return_url'],
                'notify_url' => $payload['notify_url'],
                'amount' => $payload['amount'],
                'description' => $payload['description'],
            ]);
            
            $response = Http::withoutVerifying()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'CCP-Api-Key' => $this->apiKey,
                ])
                ->timeout(30) // Timeout de 30 secondes
                ->post($this->baseUrl . '/ecommerce/create', $payload);

            Log::info('Chap Chap Pay: Réponse reçue de l\'API', [
                'order_id' => $data['order_id'] ?? null,
                'status_code' => $response->status(),
                'response_body' => $response->body(),
                'response_headers' => $response->headers(),
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                Log::info('Chap Chap Pay: Lien de paiement créé avec succès', [
                    'order_id' => $data['order_id'],
                    'amount' => $data['amount'],
                    'payment_url' => $responseData['payment_url'] ?? null,
                    'response' => $responseData,
                ]);
                return $responseData;
            } else {
                // ✅ MODIFICATION: Ajouter des logs détaillés pour le débogage
                $errorBody = $response->body();
                $errorJson = $response->json();
                
                Log::error('Chap Chap Pay: Erreur lors de la création du lien de paiement', [
                    'order_id' => $data['order_id'] ?? null,
                    'amount' => $data['amount'] ?? null,
                    'status_code' => $response->status(),
                    'response_body' => $errorBody,
                    'response_json' => $errorJson,
                    'request_url' => $this->baseUrl . '/ecommerce/create',
                    'request_data' => [
                        'amount' => $data['amount'] ?? null,
                        'description' => $data['description'] ?? null,
                        'order_id' => $data['order_id'] ?? null,
                        'return_url' => $data['return_url'] ?? null,
                        'notify_url' => $data['notify_url'] ?? null,
                    ],
                ]);
                
                // Log séparé avec juste le body pour faciliter le débogage
                Log::error('Chap Chap Pay: Corps de la réponse d\'erreur: ' . $errorBody);
                
                return null;
            }
        } catch (\Exception $e) {
            // ✅ MODIFICATION: Ajouter des logs détaillés pour les exceptions
            Log::error('Chap Chap Pay: Exception lors de la création du lien de paiement', [
                'order_id' => $data['order_id'] ?? null,
                'amount' => $data['amount'] ?? null,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'base_url' => $this->baseUrl,
                'api_key_present' => !empty($this->apiKey),
            ]);
            
            // Log séparé avec le message d'erreur pour faciliter le débogage
            Log::error('Chap Chap Pay: Message d\'erreur exception: ' . $e->getMessage());
            Log::error('Chap Chap Pay: Trace complète: ' . $e->getTraceAsString());
            
            return null;
        }
    }

    /**
     * Vérifier le statut d'un paiement
     *
     * @param string $transactionId
     * @return array|null
     */
    public function checkPaymentStatus(string $transactionId)
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'CCP-Api-Key' => $this->apiKey,
            ])->get($this->baseUrl . '/ecommerce/check/' . $transactionId);

            if ($response->successful()) {
                return $response->json();
            } else {
                Log::error('Chap Chap Pay: Erreur lors de la vérification du statut', [
                    'transaction_id' => $transactionId,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Chap Chap Pay: Exception lors de la vérification du statut', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Déclencher une opération PUSH (payout) via Chap Chap Pay.
     * Endpoint supposé cohérent avec la doc publique (PUSH API) et les patterns E-Commerce.
     */
    public function createPushOperation(array $data)
    {
        if (empty($this->apiKey)) {
            Log::error('Chap Chap Pay PUSH: Clé API manquante');
            return null;
        }

        try {
            $payload = [
                'amount' => $data['amount'],
                'description' => $data['description'] ?? 'Payout',
                'order_id' => $data['order_id'],
                'notify_url' => $data['notify_url'],
                'provider' => $data['provider'] ?? null,
                'destination' => $data['destination'] ?? null,
            ];

            $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $headers = [
                'Content-Type' => 'application/json',
                'CCP-Api-Key' => $this->apiKey,
            ];

            if (!empty($this->hmacKey)) {
                $headers['CCP-HMAC-Signature'] = hash_hmac('sha256', $body, $this->hmacKey);
            } else {
                Log::warning('Chap Chap Pay PUSH: CHAP_CHAP_SECRET_KEY manquante, signature HMAC non envoyée');
            }

            $endpoint = $this->baseUrl . '/push/operation';

            Log::info('Chap Chap Pay PUSH: Envoi opération', [
                'order_id' => $payload['order_id'] ?? null,
                'amount' => $payload['amount'] ?? null,
                'endpoint' => $endpoint,
                'has_hmac' => !empty($this->hmacKey),
            ]);

            $response = Http::withoutVerifying()
                ->withHeaders($headers)
                ->timeout(30)
                ->post($endpoint, $payload);

            Log::info('Chap Chap Pay PUSH: Réponse', [
                'status_code' => $response->status(),
                'response_body' => $response->body(),
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Chap Chap Pay PUSH: Exception', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}

