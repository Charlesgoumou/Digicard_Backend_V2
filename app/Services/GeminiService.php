<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private $apiKey;
    private $apiUrl;

    public function __construct()
    {
        $this->apiKey = config('gemini.api_key');
        $this->apiUrl = config('gemini.api_url');
    }

    /**
     * Génère le contenu marketing pour une page entreprise
     */
    public function generateCompanyPageContent($companyData)
    {
        // Extraire les données nécessaires
        $companyName = $companyData['company_name'] ?? 'Mon Entreprise';
        $companyNameShort = $companyData['company_name_short'] ?? '';
        $mainColorHex = $companyData['primary_color'] ?? '#3b82f6';

        // Convertir le hex en nom de couleur Tailwind (approximation)
        $tailwindColorName = $this->hexToTailwindColor($mainColorHex);

        // Récupérer la liste des services
        $userServicesList = $companyData['services'] ?? [];
        $userServicesJson = json_encode($userServicesList, JSON_UNESCAPED_UNICODE);

        // Construire le prompt
        $prompt = $this->buildPrompt(
            $companyName,
            $companyNameShort,
            $tailwindColorName,
            $mainColorHex,
            $userServicesJson
        );

        try {
            // Vérifier que la clé API est définie
            if (empty($this->apiKey)) {
                Log::error('Clé API Gemini non définie');
                return null;
            }

            Log::info('Appel à Gemini API', [
                'has_api_key' => !empty($this->apiKey),
                'api_url' => $this->apiUrl
            ]);

            // Appel à l'API Gemini (timeout augmenté pour la génération de contenu)
            $response = Http::timeout(120)->post($this->apiUrl . '?key=' . $this->apiKey, [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => $prompt
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'topK' => 40,
                    'topP' => 0.95,
                    'maxOutputTokens' => 8192,
                ]
            ]);

            if ($response->successful()) {
                $data = $response->json();

                // Extraire le texte généré
                $generatedText = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

                // Nettoyer le texte (enlever les balises markdown si présentes)
                $generatedText = $this->cleanJsonResponse($generatedText);

                // Parser le JSON
                $generatedContent = json_decode($generatedText, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    Log::info('Contenu généré avec succès par Gemini', [
                        'company_name' => $companyName
                    ]);
                    return $generatedContent;
                } else {
                    Log::error('Erreur de parsing JSON depuis Gemini', [
                        'error' => json_last_error_msg(),
                        'response' => $generatedText
                    ]);
                    return null;
                }
            } else {
                Log::error('Erreur lors de l\'appel à Gemini API', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Exception lors de l\'appel à Gemini', [
                'message' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Génère le contenu portfolio utilisateur avec Gemini AI
     */
    public function generateUserPortfolioContent($portfolioData, $promptTemplate)
    {
        try {
            if (empty($this->apiKey)) {
                Log::error('Clé API Gemini non définie');
                return null;
            }

            // Construire le prompt adaptatif
            $prompt = $this->buildUserPortfolioPrompt($portfolioData, $promptTemplate);

            Log::info('Appel à Gemini API pour portfolio utilisateur');

            // Appel à l'API Gemini
            $response = Http::timeout(120)->post($this->apiUrl . '?key=' . $this->apiKey, [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => $prompt
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'topK' => 40,
                    'topP' => 0.95,
                    'maxOutputTokens' => 8192,
                ]
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $generatedText = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
                $generatedText = $this->cleanJsonResponse($generatedText);
                $generatedContent = json_decode($generatedText, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    Log::info('Contenu portfolio généré avec succès');
                    return $generatedContent;
                } else {
                    Log::error('Erreur de parsing JSON depuis Gemini', [
                        'error' => json_last_error_msg()
                    ]);
                    return null;
                }
            } else {
                Log::error('Erreur lors de l\'appel à Gemini API');
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Exception lors de l\'appel à Gemini: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Construit le prompt pour le portfolio utilisateur
     */
    private function buildUserPortfolioPrompt($portfolioData, $promptTemplate)
    {
        $name = $portfolioData['name'] ?? 'Utilisateur';
        $profileType = $portfolioData['profile_type'] ?? 'student';
        $heroHeadline = $portfolioData['hero_headline'] ?? '';
        $bio = $portfolioData['bio'] ?? '';
        $skills = json_encode($portfolioData['skills'] ?? [], JSON_UNESCAPED_UNICODE);
        $projects = json_encode($portfolioData['projects'] ?? [], JSON_UNESCAPED_UNICODE);
        $timeline = json_encode($portfolioData['timeline'] ?? [], JSON_UNESCAPED_UNICODE);

        // Labels adaptatifs selon le profil
        $profileLabels = [
            'student' => ['projects' => 'Mes Projets Académiques', 'timeline' => 'Ma Formation & Stages'],
            'teacher' => ['projects' => 'Mes Réalisations & Projets', 'timeline' => 'Mon Parcours Professionnel'],
            'freelance' => ['projects' => 'Mon Portfolio / Projets Clients', 'timeline' => 'Mon Parcours & Clients'],
            'pharmacist' => ['projects' => 'Mes Réalisations & Projets', 'timeline' => 'Mon Parcours Professionnel'],
            'doctor' => ['projects' => 'Mes Réalisations & Projets', 'timeline' => 'Mon Parcours Professionnel'],
            'lawyer' => ['projects' => 'Mes Réalisations & Projets', 'timeline' => 'Mon Parcours Professionnel'],
            'notary' => ['projects' => 'Mes Réalisations & Projets', 'timeline' => 'Mon Parcours Professionnel'],
            'bailiff' => ['projects' => 'Mes Réalisations & Projets', 'timeline' => 'Mon Parcours Professionnel'],
            'architect' => ['projects' => 'Mes Réalisations & Projets', 'timeline' => 'Mon Parcours Professionnel'],
            'engineer' => ['projects' => 'Mes Réalisations & Projets', 'timeline' => 'Mon Parcours Professionnel'],
            'consultant' => ['projects' => 'Mes Réalisations & Projets', 'timeline' => 'Mon Parcours Professionnel'],
            'accountant' => ['projects' => 'Mes Réalisations & Projets', 'timeline' => 'Mon Parcours Professionnel'],
            'financial_analyst' => ['projects' => 'Mes Réalisations & Projets', 'timeline' => 'Mon Parcours Professionnel'],
            'photographer' => ['projects' => 'Mes Réalisations & Projets', 'timeline' => 'Mon Parcours Professionnel'],
            'graphic_designer' => ['projects' => 'Mes Réalisations & Projets', 'timeline' => 'Mon Parcours Professionnel'],
            'developer' => ['projects' => 'Mes Réalisations & Projets', 'timeline' => 'Mon Parcours Professionnel'],
        ];
        
        $labels = $profileLabels[$profileType] ?? $profileLabels['student'];

        return <<<PROMPT
{$promptTemplate}

**Données de {$name}:**
- Type de profil: {$profileType}
- Titre/Rôle: {$heroHeadline}
- Bio: {$bio}
- Compétences: {$skills}
- {$labels['projects']}: {$projects}
- {$labels['timeline']}: {$timeline}

Génère un objet JSON valide uniquement, sans balises markdown ou texte supplémentaire:

{
  "bio": "Reformule et enrichis la biographie en HTML pour un profil professionnel engageant. IMPORTANT: La biographie doit être écrite à la PREMIÈRE PERSONNE DU SINGULIER (je, mon, ma, mes, j'ai, je suis, etc.). Ne jamais utiliser la troisième personne (il, elle, son, sa, ses, il a, elle est, etc.).",
  "skills_title": "Mes Compétences",
  "projects_title": "{$labels['projects']}",
  "timeline_title": "{$labels['timeline']}",
  "projects": [Pour chaque projet, reformule le title et short_description de manière professionnelle et impactante, enrichis details_html avec des puces de compétences et résultats],
  "timeline": [Pour chaque événement, reformule le title et organization, enrichis details avec des accomplissements et responsabilités]
}
PROMPT;
    }

    /**
     * Construit le prompt pour Gemini
     */
    private function buildPrompt($companyName, $companyNameShort, $tailwindColorName, $mainColorHex, $userServicesJson)
    {
        return <<<PROMPT
Vous êtes un stratège de marque expert, un rédacteur marketing et un assistant de conception, parlant un français professionnel et engageant.

Votre tâche est de générer uniquement un objet JSON valide, sans aucune explication, formatage markdown (```json) ou texte conversationnel.

Ce JSON contiendra tout le contenu marketing nécessaire pour un site web d'une page, basé sur les informations minimales fournies par l'utilisateur.

**Données Fournies par l'Utilisateur :**
- Nom de l'entreprise : {$companyName}
- Nom court/Acronyme : {$companyNameShort}
- Couleur de base (Nom Tailwind) : {$tailwindColorName}
- Couleur de base (Code HEX) : {$mainColorHex}
- Liste des services (JSON brut) : {$userServicesJson}

**JSON de Sortie Requis (Structure Exacte) :**

{
  "hero_headline": "Un titre H1 très accrocheur et professionnel pour l'entreprise (ex: 'Votre Partenaire de Confiance').",
  "hero_subheadline": "Un sous-titre clair décrivant l'activité principale (ex: 'Vente de Matériels de Construction').",
  "hero_description": "Une description marketing de 1 à 2 phrases qui résume la valeur ajoutée de l'entreprise.",
  "products_button_text": "Un texte court pour le bouton principal (ex: 'Nos Produits', 'Nos Services').",
  "products_button_icon": "Une seule classe d'icône Font Awesome 6 (ex: 'fa-solid fa-boxes-stacked') correspondant aux services.",

  "text_color_500": "text-{$tailwindColorName}-500",
  "text_color_700": "text-{$tailwindColorName}-700",
  "text_color_600": "text-{$tailwindColorName}-600",
  "bg_color_500": "bg-{$tailwindColorName}-500",
  "bg_color_600": "bg-{$tailwindColorName}-600",
  "bg_color_700": "bg-{$tailwindColorName}-700",
  "hover_bg_color_800": "hover:bg-{$tailwindColorName}-800",
  "hover_bg_color_600": "hover:bg-{$tailwindColorName}-600",
  "hover_text_color_500": "hover:text-{$tailwindColorName}-500",

  "chart_title": "Un titre pour le graphique en anneau des services (ex: 'Nos Catégories de Produits').",
  "chart_description": "Une courte phrase décrivant le graphique.",
  "chart_labels": ["REPRENDRE les titres exacts de la liste des services"],
  "chart_data": ["DISTRIBUER 100% de manière inégale entre les services pour un look réaliste (ex: 30, 25, 20, 15, 10). Le nombre d'éléments DOIT correspondre à chart_labels."],
  "chart_colors": ["GÉNÉRER un tableau de codes HEX. Le premier doit être '{$mainColorHex}'. Les suivants doivent être des nuances complémentaires ou des gris neutres. Le nombre d'éléments DOIT correspondre à chart_labels."],

  "pillars_title": "Un titre pour la section des 3 atouts (ex: 'Nos Atouts', 'Pourquoi nous choisir ?').",
  "pillars": [
    {
      "title": "Titre du premier atout (ex: 'Qualité Garantie')",
      "description": "Description de 1-2 phrases pour cet atout.",
      "icon": "Une classe d'icône fa-solid (ex: 'fa-solid fa-check-double') pertinente."
    },
    {
      "title": "Titre du deuxième atout (ex: 'Gamme Complète')",
      "description": "Description de 1-2 phrases pour cet atout.",
      "icon": "Une classe d'icône fa-solid (ex: 'fa-solid fa-boxes-stacked') pertinente."
    },
    {
      "title": "Titre du troisième atout (ex: 'Service Fiable')",
      "description": "Description de 1-2 phrases pour cet atout.",
      "icon": "Une classe d'icône fa-solid (ex: 'fa-solid fa-truck-fast') pertinente."
    }
  ],

  "engagement_description": "Une phrase marketing de 1-2 phrases pour la section d'engagement finale.",
  "products_modal_title": "Titre pour la modale (pop-up) des services (ex: 'Nos Produits Détaillés').",

  "processes_title": "Un titre pour la section des processus (ex: 'Nos Processus Simplifiés').",
  "process_order_title": "Titre pour le processus de commande (ex: 'Processus de Commande').",
  "process_order_description": "Une courte phrase décrivant le processus de commande.",
  "process_order_steps": ["Tableau de 5 étapes claires décrivant le processus de commande de l'entreprise"],
  "process_logistics_title": "Titre pour le processus logistique (ex: 'Logistique & Livraison').",
  "process_logistics_description": "Une courte phrase décrivant le processus de livraison.",
  "process_logistics_steps": ["Tableau de 5 étapes claires décrivant le processus de livraison/logistique"],

  "services": [
    "ITÉRER sur chaque service de la liste fournie et le transformer. Pour chaque service, créer un objet avec 'title' (le titre original du service), 'icon' (une classe d'icône fa-solid pertinente), et 'details' (la description du service reformulée de manière professionnelle ET formatée en HTML simple avec des paragraphes <p> et listes <ul><li>...</li></ul> si approprié)."
  ]
}

IMPORTANT : Retournez UNIQUEMENT le JSON valide, sans balises markdown, sans commentaires, sans texte avant ou après.
PROMPT;
    }

    /**
     * Nettoie la réponse JSON en enlevant les balises markdown
     */
    private function cleanJsonResponse($text)
    {
        // Enlever les balises ```json et ```
        $text = preg_replace('/^```json\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim($text);

        return $text;
    }

    /**
     * Convertit un code HEX en nom de couleur Tailwind (approximation)
     */
    private function hexToTailwindColor($hex)
    {
        // Enlever le # si présent
        $hex = ltrim($hex, '#');

        // Convertir en RGB
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        // Déterminer la couleur dominante
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);

        // Si la couleur est proche du gris
        if ($max - $min < 30) {
            return 'gray';
        }

        // Déterminer la teinte
        if ($r >= $g && $r >= $b) {
            if ($g > $b) {
                return 'orange'; // Rouge-orangé
            } else {
                return 'red';
            }
        } elseif ($g >= $r && $g >= $b) {
            if ($r > $b) {
                return 'lime'; // Vert-jaune
            } else {
                return 'green';
            }
        } else {
            if ($r > $g) {
                return 'purple'; // Bleu-violet
            } else {
                return 'blue';
            }
        }
    }

    /**
     * Extrait le texte d'une image en utilisant Gemini Vision API
     */
    public function extractTextFromImage($imageData, $mimeType)
    {
        try {
            if (empty($this->apiKey)) {
                Log::error('Clé API Gemini non définie - Vérifiez votre fichier .env');
                return '';
            }

            Log::info('Extraction de texte depuis une image', [
                'mime_type' => $mimeType,
                'api_url' => $this->apiUrl
            ]);

            $response = Http::timeout(120)->post($this->apiUrl . '?key=' . $this->apiKey, [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => 'Extrait tout le texte visible de cette image en français. Si c\'est une présentation d\'entreprise, inclus le nom de l\'entreprise, ses services, sa description, etc. Retourne UNIQUEMENT le texte extrait, sans commentaires supplémentaires.'
                            ],
                            [
                                'inline_data' => [
                                    'mime_type' => $mimeType,
                                    'data' => $imageData
                                ]
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.4,
                    'topK' => 32,
                    'topP' => 1,
                    'maxOutputTokens' => 4096,
                ]
            ]);

            if ($response->successful()) {
                $result = $response->json();
                $extractedText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

                if (empty($extractedText)) {
                    Log::warning('API Gemini a retourné un texte vide', [
                        'response' => $result
                    ]);
                }

                return trim($extractedText);
            } else {
                Log::error('Erreur API Gemini lors de l\'extraction d\'image', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return '';
            }
        } catch (\Exception $e) {
            Log::error('Exception lors de l\'extraction d\'image: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Extrait le texte d'un PDF en utilisant Gemini Vision API
     */
    public function extractTextFromPdf($pdfData)
    {
        try {
            if (empty($this->apiKey)) {
                Log::error('Clé API Gemini non définie - Vérifiez votre fichier .env');
                return '';
            }

            Log::info('Extraction de texte depuis un PDF');

            $response = Http::timeout(120)->post($this->apiUrl . '?key=' . $this->apiKey, [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => 'Extrait tout le texte de ce document PDF en français. Si c\'est une présentation d\'entreprise, inclus le nom de l\'entreprise, ses services, sa description, etc. Retourne UNIQUEMENT le texte extrait, sans commentaires supplémentaires.'
                            ],
                            [
                                'inline_data' => [
                                    'mime_type' => 'application/pdf',
                                    'data' => $pdfData
                                ]
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.4,
                    'topK' => 32,
                    'topP' => 1,
                    'maxOutputTokens' => 8192,
                ]
            ]);

            if ($response->successful()) {
                $result = $response->json();
                $extractedText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

                if (empty($extractedText)) {
                    Log::warning('API Gemini a retourné un texte vide pour le PDF', [
                        'response' => $result
                    ]);
                }

                return trim($extractedText);
            } else {
                Log::error('Erreur API Gemini lors de l\'extraction de PDF', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return '';
            }
        } catch (\Exception $e) {
            Log::error('Exception lors de l\'extraction de PDF: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Analyse le texte extrait et retourne les informations structurées
     */
    public function extractCompanyInfoFromText($text)
    {
        try {
            if (empty($this->apiKey)) {
                Log::error('Clé API Gemini non définie');
                return null;
            }

            $prompt = <<<PROMPT
Tu es un expert en analyse de documents d'entreprise. Analyse le texte suivant extrait d'une présentation d'entreprise et retourne UNIQUEMENT un objet JSON valide avec les informations structurées.

Texte à analyser:
{$text}

Retourne UN SEUL objet JSON avec cette structure EXACTE (sans markdown, sans commentaires):
{
  "company_name": "Nom complet de l'entreprise si trouvé, sinon null",
  "company_name_short": "Acronyme ou nom court si trouvé, sinon null",
  "services": [
    {
      "title": "Titre du service 1",
      "description": "Description détaillée du service 1"
    }
  ],
  "hero_headline": "Un titre accrocheur pour la page d'accueil (max 10 mots)",
  "hero_subheadline": "Un sous-titre descriptif (max 20 mots)",
  "hero_description": "Une description marketing (2-3 phrases)",
  "engagement_description": "Une phrase d'engagement finale (1-2 phrases)"
}

IMPORTANT: Retourne UNIQUEMENT le JSON, sans balises markdown, sans texte avant ou après.
PROMPT;

            $response = Http::timeout(90)->post($this->apiUrl . '?key=' . $this->apiKey, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
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

            if ($response->successful()) {
                $result = $response->json();
                $generatedText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

                // Nettoyer le JSON (enlever les balises markdown si présentes)
                $generatedText = preg_replace('/```json\s*/', '', $generatedText);
                $generatedText = preg_replace('/```\s*$/', '', $generatedText);
                $generatedText = trim($generatedText);

                Log::info('Texte généré par Gemini pour extraction: ' . $generatedText);

                $extractedData = json_decode($generatedText, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    return $extractedData;
                } else {
                    Log::error('Erreur JSON lors de l\'extraction: ' . json_last_error_msg());
                    return null;
                }
            } else {
                Log::error('Erreur API Gemini lors de l\'analyse du texte: ' . $response->body());
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Exception lors de l\'analyse du texte: ' . $e->getMessage());
            return null;
        }
    }
}

