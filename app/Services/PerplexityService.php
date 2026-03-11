<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PerplexityService
{
    private $apiKey;
    private $apiUrl;
    private $model;

    public function __construct()
    {
        $this->apiKey = config('perplexity.api_key');
        $this->apiUrl = config('perplexity.api_url');
        $this->model = config('perplexity.model', 'llama-3.1-sonar-large-128k-online');
    }

    /**
     * Explore le contenu d'un site web et détermine les besoins de l'utilisateur
     * 
     * @param string $websiteUrl L'URL du site web à explorer
     * @param string|null $userTitle Le titre/poste de l'utilisateur (optionnel)
     * @return array|null Tableau contenant les besoins identifiés ou null en cas d'erreur
     */
    public function exploreWebsiteAndDetermineNeeds(string $websiteUrl, ?string $userTitle = null): ?array
    {
        try {
            if (empty($this->apiKey)) {
                Log::error('Clé API Perplexity non définie - Vérifiez votre fichier .env');
                return null;
            }

            // Construire le prompt pour analyser le site web
            $prompt = $this->buildAnalysisPrompt($websiteUrl, $userTitle);

            Log::info('Appel à Perplexity API pour explorer le site web', [
                'website_url' => $websiteUrl,
                'has_api_key' => !empty($this->apiKey),
                'model' => $this->model
            ]);

            // Appel à l'API Perplexity
            $response = Http::timeout(120)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($this->apiUrl, [
                    'model' => $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Tu es un expert en analyse de besoins business. Tu analyses les sites web pour identifier les besoins en approvisionnement, partenariats, expertise et opportunités commerciales.'
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt
                        ]
                    ],
                    'temperature' => 0.3,
                    'max_tokens' => 2000,
                ]);

            if ($response->successful()) {
                $result = $response->json();
                $content = $result['choices'][0]['message']['content'] ?? '';

                if (empty($content)) {
                    Log::warning('API Perplexity a retourné un contenu vide', [
                        'response' => $result
                    ]);
                    return null;
                }

                // Parser le contenu pour extraire les besoins structurés
                $needs = $this->parseNeedsFromResponse($content);

                Log::info('Besoins identifiés avec succès par Perplexity', [
                    'website_url' => $websiteUrl,
                    'needs_count' => count($needs)
                ]);

                return $needs;
            } else {
                Log::error('Erreur lors de l\'appel à Perplexity API', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Exception lors de l\'exploration du site web: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Construit le prompt pour analyser le site web
     */
    private function buildAnalysisPrompt(string $websiteUrl, ?string $userTitle): string
    {
        $prompt = "Analyse le site web suivant : {$websiteUrl}\n\n";
        
        if ($userTitle) {
            $prompt .= "Le propriétaire du site a le titre/poste suivant : {$userTitle}\n\n";
        }

        $prompt .= "Identifie les services, produits et activités de ce site web. ";
        $prompt .= "Ensuite, détermine les besoins de cet utilisateur pour délivrer ses services ou compléter son offre. ";
        $prompt .= "Les besoins peuvent inclure :\n";
        $prompt .= "1. Approvisionnement et Stock (fournisseurs, grossistes, distributeurs)\n";
        $prompt .= "2. Partenariats et Sous-traitance (installateurs, techniciens, freelances)\n";
        $prompt .= "3. Expertise de niche (licences logiciels, certifications, outils spécialisés)\n";
        $prompt .= "4. Appels d'offres (opportunités de business, clients cherchant des prestataires)\n\n";
        $prompt .= "Retourne une réponse structurée en JSON avec les champs suivants :\n";
        $prompt .= "{\n";
        $prompt .= "  \"services_identified\": [\"liste des services identifiés\"],\n";
        $prompt .= "  \"needs\": {\n";
        $prompt .= "    \"supply_chain\": [\"besoins en approvisionnement\"],\n";
        $prompt .= "    \"partnerships\": [\"besoins en partenariats\"],\n";
        $prompt .= "    \"expertise\": [\"besoins en expertise/outils\"],\n";
        $prompt .= "    \"opportunities\": [\"opportunités commerciales\"]\n";
        $prompt .= "  },\n";
        $prompt .= "  \"keywords\": [\"mots-clés pertinents pour la recherche\"]\n";
        $prompt .= "}\n\n";
        $prompt .= "Retourne UNIQUEMENT le JSON valide, sans balises markdown, sans commentaires.";

        return $prompt;
    }

    /**
     * Parse la réponse de Perplexity pour extraire les besoins structurés
     */
    private function parseNeedsFromResponse(string $content): array
    {
        $needs = [
            'services_identified' => [],
            'needs' => [
                'supply_chain' => [],
                'partnerships' => [],
                'expertise' => [],
                'opportunities' => []
            ],
            'keywords' => []
        ];

        try {
            // Essayer d'extraire le JSON de la réponse
            // La réponse peut contenir du texte avant/après le JSON
            $jsonStart = strpos($content, '{');
            $jsonEnd = strrpos($content, '}');
            
            if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd > $jsonStart) {
                $jsonString = substr($content, $jsonStart, $jsonEnd - $jsonStart + 1);
                $parsed = json_decode($jsonString, true);
                
                if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                    $needs = array_merge($needs, $parsed);
                }
            }

            // Si le parsing JSON échoue, essayer d'extraire les informations du texte
            if (empty($needs['keywords'])) {
                $needs['keywords'] = $this->extractKeywordsFromText($content);
            }
        } catch (\Exception $e) {
            Log::warning('Erreur lors du parsing de la réponse Perplexity', [
                'error' => $e->getMessage(),
                'content_preview' => substr($content, 0, 200)
            ]);
            
            // Fallback : extraire les mots-clés du texte brut
            $needs['keywords'] = $this->extractKeywordsFromText($content);
        }

        return $needs;
    }

    /**
     * Extrait les mots-clés pertinents du texte
     */
    private function extractKeywordsFromText(string $text): array
    {
        // Mots-clés communs à rechercher
        $commonKeywords = [
            'informatique', 'réseau', 'sécurité', 'cybersécurité', 'formation',
            'installation', 'matériel', 'logiciel', 'service', 'prestation',
            'fournisseur', 'distributeur', 'grossiste', 'partenaire', 'sous-traitance',
            'certification', 'licence', 'outil', 'équipement', 'appel d\'offres'
        ];

        $keywords = [];
        $textLower = mb_strtolower($text, 'UTF-8');

        foreach ($commonKeywords as $keyword) {
            if (stripos($textLower, $keyword) !== false) {
                $keywords[] = $keyword;
            }
        }

        return array_unique($keywords);
    }
}
