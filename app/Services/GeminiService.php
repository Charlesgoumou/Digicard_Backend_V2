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
     * Génère le contenu portfolio utilisateur avec Gemini AI (améliorations : prompt formations exhaustives, max_output_tokens).
     */
    public function generateUserPortfolioContent($portfolioData, $promptTemplate)
    {
        try {
            if (empty($this->apiKey)) {
                Log::error('Clé API Gemini non définie');
                return null;
            }

            $prompt = $this->buildUserPortfolioPrompt($portfolioData, $promptTemplate);
            Log::info('Appel à Gemini API pour portfolio utilisateur');
            return $this->callGeminiPortfolioWithRepair($prompt);
        } catch (\Exception $e) {
            Log::error('Exception generateUserPortfolioContent: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Appel Gemini pour le portfolio avec réparation JSON si nécessaire.
     */
    private function callGeminiPortfolioWithRepair(string $prompt): ?array
    {
        $response = Http::timeout(120)->post($this->apiUrl . '?key=' . $this->apiKey, [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'temperature' => 0.7,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => config('gemini.max_output_tokens', 16384),
            ],
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $generatedText = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $generatedText = $this->cleanJsonResponse($generatedText);
            Log::info('Texte nettoyé pour portfolio, longueur: ' . strlen($generatedText));
            $generatedContent = json_decode($generatedText, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($generatedContent)) {
                Log::info('Contenu portfolio généré avec succès');
                return $generatedContent;
            } else {
                    $errorMsg = json_last_error_msg();
                    $errorCode = json_last_error();
                    Log::error('Erreur de parsing JSON depuis Gemini', [
                        'error' => $errorMsg,
                        'code' => $errorCode,
                        'text_preview' => substr($generatedText, 0, 500)
                    ]);
                    
                    // Essayer de réparer le JSON avec la même logique que extractPortfolioInfoFromText
                    $firstBrace = strpos($generatedText, '{');
                    if ($firstBrace !== false) {
                        $truncatedJson = substr($generatedText, $firstBrace);
                        
                        // Stratégie : trouver le dernier élément complet et supprimer tout ce qui suit
                        $lastCompleteElement = -1;
                        $depth = 0;
                        $inString = false;
                        $escapeNext = false;
                        
                        for ($i = 0; $i < strlen($truncatedJson); $i++) {
                            $char = $truncatedJson[$i];
                            
                            if ($escapeNext) {
                                $escapeNext = false;
                                continue;
                            }
                            
                            if ($char === '\\') {
                                $escapeNext = true;
                                continue;
                            }
                            
                            if ($char === '"' && !$escapeNext) {
                                $inString = !$inString;
                                continue;
                            }
                            
                            if (!$inString) {
                                if ($char === '{' || $char === '[') {
                                    $depth++;
                                } elseif ($char === '}' || $char === ']') {
                                    $depth--;
                                    if ($depth === 0) {
                                        $lastCompleteElement = $i;
                                    }
                                }
                            }
                        }
                        
                        // Si on a trouvé un élément complet, couper là
                        if ($lastCompleteElement > 0) {
                            $truncatedJson = substr($truncatedJson, 0, $lastCompleteElement + 1);
                            $generatedContent = json_decode($truncatedJson, true);
                            if (json_last_error() === JSON_ERROR_NONE && is_array($generatedContent)) {
                                Log::info('JSON réparé avec succès en supprimant les éléments incomplets');
                                return $generatedContent;
                            }
                        }
                        
                        // Si ça n'a pas marché, essayer de fermer proprement
                        $openBraces = substr_count($truncatedJson, '{');
                        $closeBraces = substr_count($truncatedJson, '}');
                        $openBrackets = substr_count($truncatedJson, '[');
                        $closeBrackets = substr_count($truncatedJson, ']');
                        
                        // Trouver la dernière position d'une chaîne
                        $lastQuote = strrpos($truncatedJson, '"');
                        $isInString = false;
                        
                        if ($lastQuote !== false) {
                            $beforeLastQuote = substr($truncatedJson, 0, $lastQuote);
                            $escapedQuotes = preg_match_all('/\\\\"/', $beforeLastQuote);
                            $totalQuotes = substr_count($beforeLastQuote, '"');
                            $unescapedQuotes = $totalQuotes - $escapedQuotes;
                            $isInString = ($unescapedQuotes % 2 === 1);
                        }
                        
                        // Si on est dans une chaîne, trouver où elle commence et la supprimer complètement
                        if ($isInString && $lastQuote !== false) {
                            $beforeLastQuote = substr($truncatedJson, 0, $lastQuote);
                            $openQuotePos = strrpos($beforeLastQuote, ':');
                            if ($openQuotePos !== false) {
                                $afterColon = substr($truncatedJson, $openQuotePos + 1);
                                $firstQuoteAfterColon = strpos($afterColon, '"');
                                if ($firstQuoteAfterColon !== false) {
                                    $actualOpenQuote = $openQuotePos + 1 + $firstQuoteAfterColon;
                                    $truncatedJson = substr($truncatedJson, 0, $actualOpenQuote) . '""';
                                }
                            }
                        }
                        
                        // Fermer les tableaux et objets ouverts
                        for ($i = 0; $i < ($openBrackets - $closeBrackets); $i++) {
                            $truncatedJson .= ']';
                        }
                        for ($i = 0; $i < ($openBraces - $closeBraces); $i++) {
                            $truncatedJson .= '}';
                        }
                        
                        $generatedContent = json_decode($truncatedJson, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($generatedContent)) {
                            Log::info('JSON réparé avec succès après fermeture automatique');
                            return $generatedContent;
                        } else {
                            Log::error('Échec de la réparation JSON avec fermeture automatique: ' . json_last_error_msg());
                        }
                    }
                    
                    return null;
                }
        } else {
            Log::error('Erreur lors de l\'appel à Gemini API');
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
        $menu = json_encode($portfolioData['menu'] ?? [], JSON_UNESCAPED_UNICODE);

        // Pour le profil restaurant, utiliser un prompt spécialisé
        if ($profileType === 'restaurant') {
            return <<<PROMPT
{$promptTemplate}

**Données du restaurant {$name}:**
- Type de profil: {$profileType}
- Nom du restaurant: {$heroHeadline}
- Description: {$bio}
- Menu (plats et boissons): {$menu}

RÈGLE ABSOLUE - PREMIÈRE PERSONNE DU PLURIEL:
TOUT le contenu généré (bio, descriptions de plats) DOIT être écrit à la PREMIÈRE PERSONNE DU PLURIEL :
- Utilise: nous, notre, nos, nous avons, nous proposons, nous offrons, etc.
- N'utilise JAMAIS: je, mon, ma, mes, j'ai, je suis, je propose, etc.
- Les descriptions doivent commencer par "Nous proposons...", "Notre restaurant...", "Nous offrons...", etc.

Génère un objet JSON valide uniquement, sans balises markdown ou texte supplémentaire:

{
  "bio": "Reformule et enrichis la description du restaurant en HTML pour un profil engageant. ÉCRIS À LA PREMIÈRE PERSONNE DU PLURIEL (nous, notre, nos, nous avons, nous proposons, nous offrons, etc.). Exemple: 'Nous proposons une cuisine...' ou 'Notre restaurant se spécialise dans...'",
  "hero_headline": "Reformule le nom du restaurant ou la spécialité culinaire de manière accrocheuse",
  "menu": {
    "dishes": [Pour chaque plat du menu, reformule et enrichis la description si nécessaire. Garde tous les champs existants (name, price, description, image, available, hasSides, sides). Améliore les descriptions à la PREMIÈRE PERSONNE DU PLURIEL si elles sont vides ou trop courtes],
    "drinks": [Pour chaque boisson du menu, reformule et enrichis la description si nécessaire. Garde tous les champs existants (name, price, image, available). Améliore les descriptions à la PREMIÈRE PERSONNE DU PLURIEL si elles sont vides ou trop courtes]
  }
}
PROMPT;
        }

        // Labels adaptatifs selon le profil (seulement pour les projets)
        $profileLabels = [
            'student' => ['projects' => 'Mes Projets Académiques'],
            'teacher' => ['projects' => 'Mes Réalisations & Projets'],
            'freelance' => ['projects' => 'Mon Portfolio / Projets Clients'],
            'pharmacist' => ['projects' => 'Mes Réalisations & Projets'],
            'doctor' => ['projects' => 'Mes Réalisations & Projets'],
            'lawyer' => ['projects' => 'Mes Réalisations & Projets'],
            'notary' => ['projects' => 'Mes Réalisations & Projets'],
            'bailiff' => ['projects' => 'Mes Réalisations & Projets'],
            'architect' => ['projects' => 'Mes Réalisations & Projets'],
            'engineer' => ['projects' => 'Mes Réalisations & Projets'],
            'consultant' => ['projects' => 'Mes Réalisations & Projets'],
            'accountant' => ['projects' => 'Mes Réalisations & Projets'],
            'financial_analyst' => ['projects' => 'Mes Réalisations & Projets'],
            'photographer' => ['projects' => 'Mes Réalisations & Projets'],
            'graphic_designer' => ['projects' => 'Mes Réalisations & Projets'],
            'developer' => ['projects' => 'Mes Réalisations & Projets'],
            'banker' => ['projects' => 'Mes Réalisations & Projets'],
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
- Données brutes (formations et expériences professionnelles): {$timeline}

RÈGLE ABSOLUE - PREMIÈRE PERSONNE DU SINGULIER:
TOUT le contenu généré (bio, projets, formations, timeline) DOIT être écrit à la PREMIÈRE PERSONNE DU SINGULIER :
- Utilise: je, mon, ma, mes, j'ai, je suis, j'ai réalisé, j'ai développé, j'ai géré, etc.
- N'utilise JAMAIS: il, elle, son, sa, ses, il a, elle est, il a réalisé, elle a développé, etc.
- Les descriptions doivent commencer par "J'ai...", "Je suis...", "Mon...", "Ma...", "Mes...", etc.

IMPORTANT - SÉPARATION FORMATIONS / EXPÉRIENCES PROFESSIONNELLES:
Tu dois analyser les données brutes et les séparer en DEUX sections distinctes :
1. **formations** : TOUTES les formations (scolaire, universitaire, formations professionnelles, certifications, séminaires, etc.) - INCLUS TOUTES, même les plus anciennes (Baccalauréat, Licence, Master, etc.)
2. **timeline** : Toutes les expériences professionnelles (stages, emplois, missions, etc.)

RÈGLE CRITIQUE - FORMATIONS EXHAUSTIVES (PRIORITÉ ABSOLUE):
- Tu DOIS lister CHAQUE formation présente dans les données. Aucune omission.
- Compte d'abord mentalement toutes les formations (Bac, Licence, Master, BTS, DUT, certifications, séminaires, etc.) puis génère exactement ce nombre d'entrées dans le tableau "formations".
- Si les données contiennent 5 formations, le JSON doit contenir exactement 5 objets dans "formations". Pas 2, pas 3 : toutes.
- Ne tronque jamais le tableau formations pour gagner de la place : les formations passent AVANT tout (priorité sur la longueur des autres champs si besoin).
- Inclus : Baccalauréat, Licence, Master, Doctorat, BTS, DUT, Certifications, Séminaires, Formations professionnelles, etc.
- Pour chaque formation : title, organization, date (OBLIGATOIRE - jamais null), description (courte, première personne). Classe par ordre chronologique décroissant.

Génère un objet JSON valide uniquement, sans balises markdown ou texte supplémentaire:

{
  "bio": "Reformule et enrichis la biographie en HTML pour un profil professionnel engageant. ÉCRIS À LA PREMIÈRE PERSONNE DU SINGULIER (je, mon, ma, mes, j'ai, je suis, etc.). Exemple: 'Je suis un professionnel...' ou 'Mon parcours m'a permis de...'",
  "skills_title": "Mes Compétences",
  "projects_title": "{$labels['projects']}",
  "formations_title": "Mes Formations",
  "timeline_title": "Mon Parcours Professionnel",
  "projects": [Pour chaque projet, reformule le title et short_description de manière professionnelle et impactante. Dans details_html, utilise la PREMIÈRE PERSONNE (ex: 'J'ai développé...', 'J'ai géré...', 'Mon rôle consistait à...'). Enrichis avec des puces de compétences et résultats à la première personne],
  "formations": [OBLIGATOIRE: une entrée pour CHAQUE formation dans les données. Pour chacune: title (ex: "Master 2 en...", "Licence en...", "Baccalauréat..."), organization (établissement), date (OBLIGATOIRE - format "2016-2019", "2024", etc. - JAMAIS null), description (courte, première personne). NE PAS EN OMETTRE. Classe par ordre chronologique décroissant],
  "timeline": [Pour chaque expérience professionnelle (stage, emploi, mission), crée un objet avec: title, organization, date (si disponible), description (première personne). Classe par ordre chronologique décroissant]
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
        
        // Supprimer les caractères de contrôle (sauf les caractères de nouvelle ligne et tabulation dans les chaînes)
        // On va utiliser une approche plus sûre : nettoyer caractère par caractère
        $cleaned = '';
        $inString = false;
        $escapeNext = false;
        
        for ($i = 0; $i < strlen($text); $i++) {
            $char = $text[$i];
            
            if ($escapeNext) {
                // Caractère échappé, le garder tel quel
                $cleaned .= $char;
                $escapeNext = false;
                continue;
            }
            
            if ($char === '\\') {
                $escapeNext = true;
                $cleaned .= $char;
                continue;
            }
            
            if ($char === '"') {
                $inString = !$inString;
                $cleaned .= $char;
                continue;
            }
            
            if ($inString) {
                // Dans une chaîne, garder les caractères normaux mais supprimer les caractères de contrôle
                // (sauf \n, \r, \t qui sont valides dans JSON)
                if (ord($char) >= 32 || in_array($char, ["\n", "\r", "\t"])) {
                    $cleaned .= $char;
                }
            } else {
                // En dehors d'une chaîne, supprimer tous les caractères de contrôle
                if (ord($char) >= 32 || in_array($char, ["\n", "\r", "\t", " "])) {
                    $cleaned .= $char;
                }
            }
        }
        
        // Enlever tout texte avant le premier {
        $firstBrace = strpos($cleaned, '{');
        if ($firstBrace !== false && $firstBrace > 0) {
            $cleaned = substr($cleaned, $firstBrace);
        }
        
        // Enlever tout texte après le dernier }
        $lastBrace = strrpos($cleaned, '}');
        if ($lastBrace !== false && $lastBrace < strlen($cleaned) - 1) {
            $cleaned = substr($cleaned, 0, $lastBrace + 1);
        }
        
        return trim($cleaned);
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

    /**
     * Extrait les informations d'un document de banquier
     */
    public function extractBankerInfoFromText($text)
    {
        try {
            if (empty($this->apiKey)) {
                Log::error('Clé API Gemini non définie');
                return null;
            }

            $prompt = <<<PROMPT
Tu es un expert en analyse de documents professionnels pour un banquier. Analyse le texte suivant extrait d'un CV, lettre de motivation ou document professionnel et retourne UNIQUEMENT un objet JSON valide avec les informations structurées.

Texte à analyser:
{$text}

RÈGLE ABSOLUE - PREMIÈRE PERSONNE DU SINGULIER:
TOUT le contenu généré (bio, projects, timeline) DOIT être écrit à la PREMIÈRE PERSONNE DU SINGULIER :
- Utilise: je, mon, ma, mes, j'ai, je suis, j'ai réalisé, j'ai développé, j'ai géré, etc.
- N'utilise JAMAIS: il, elle, son, sa, ses, il a, elle est, il a réalisé, elle a développé, etc.
- Les descriptions doivent commencer par "J'ai...", "Je suis...", "Mon...", "Ma...", "Mes...", etc.

IMPORTANT - SÉPARATION FORMATIONS / EXPÉRIENCES PROFESSIONNELLES:
Tu dois analyser le texte et séparer les informations en DEUX sections distinctes :
1. **formations** : Toutes les formations (scolaire, universitaire, formations professionnelles, certifications, etc.)
2. **timeline** : Toutes les expériences professionnelles (stages, emplois, missions, etc.)

Pour chaque élément, extrais la date du texte si elle est mentionnée, et utilise-la dans le format approprié.

Retourne UN SEUL objet JSON avec cette structure EXACTE (sans markdown, sans commentaires):
{
  "bio": "Une biographie professionnelle basée sur le document (2-3 phrases) ÉCRITE À LA PREMIÈRE PERSONNE. Exemple: 'Je suis un banquier...' ou 'Mon parcours m'a permis de...'",
  "hero_headline": "Un titre professionnel accrocheur (ex: 'Banquier d'affaires', 'Conseiller financier')",
  "formations_title": "Mes Formations",
  "timeline_title": "Mon Parcours Professionnel",
  "skills": [
    {
      "icon": "🏦",
      "name": "Nom de la compétence"
    }
  ],
  "projects": [
    {
      "title": "Titre du projet/réalisation",
      "short_description": "Description courte à la première personne (ex: 'J'ai développé...')",
      "full_description": "Description détaillée à la première personne (ex: 'J'ai géré...', 'Mon rôle consistait à...')"
    }
  ],
      "formations": [
        {
          "title": "Titre de la formation (ex: 'Master 2 en...', 'Licence en...', 'Baccalauréat...', 'Formation en...', 'Séminaire en...')",
          "organization": "Établissement ou organisme de formation",
          "date": "Date ou période OBLIGATOIRE (extrait du texte ou déduis du contexte, format: '2016-2019', '2024', 'Depuis 2024', etc. - NE METS JAMAIS null)",
          "description": "Description à la première personne (ex: 'J'ai suivi une formation en...', 'J'ai obtenu mon diplôme de...', 'J'ai acquis des compétences en...')"
        }
      ],
      "timeline": [
        {
          "title": "Titre du poste/stage (ex: 'Assistant Chargé d'Affaires', 'Stagiaire en...')",
          "organization": "Nom de l'entreprise ou organisation",
          "date": "Date ou période (extrait du texte si disponible, sinon null)",
      "description": "Description de l'expérience à la première personne (ex: 'J'ai occupé le poste de...', 'Mes responsabilités incluaient...', 'J'ai géré...')"
    }
  ],
  "email": "Email si trouvé, sinon null",
  "phone": "Téléphone si trouvé, sinon null",
  "linkedin_url": "URL LinkedIn si trouvée, sinon null"
}

IMPORTANT: 
- Retourne UNIQUEMENT le JSON, sans balises markdown, sans texte avant ou après.
- Les compétences doivent être pertinentes pour le secteur bancaire/financier.
- Les projets peuvent être des réalisations professionnelles, des financements, des conseils, etc.
- **formations** : Inclut UNIQUEMENT les formations (scolaire, universitaire, formations professionnelles, certifications). Classe par ordre chronologique décroissant (plus récent en premier)
- **timeline** : Inclut UNIQUEMENT les expériences professionnelles (stages, emplois, missions). Classe par ordre chronologique décroissant (plus récent en premier)
- TOUT le contenu textuel (bio, projects.full_description, formations.description, timeline.description) DOIT être à la PREMIÈRE PERSONNE DU SINGULIER
- Extrais les dates du texte et place-les dans le champ "date" de chaque élément (formations et timeline)
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

                Log::info('Texte généré par Gemini pour extraction banquier: ' . $generatedText);

                $extractedData = json_decode($generatedText, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    return $extractedData;
                } else {
                    Log::error('Erreur JSON lors de l\'extraction banquier: ' . json_last_error_msg());
                    return null;
                }
            } else {
                Log::error('Erreur API Gemini lors de l\'analyse du texte banquier: ' . $response->body());
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Exception lors de l\'analyse du texte banquier: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Retourne l'instruction spécifique au profil métier pour affiner la génération JSON par Gemini.
     * (Profil 'restaurant' exclu : géré à part.)
     */
    private function getProfileSpecificInstruction(string $profileType): string
    {
        $instructions = [
            'student' => "Mets l'accent sur la formation, les projets académiques, les stages et les soft skills (apprentissage, motivation). Si l'expérience est faible, valorise les projets scolaires comme des expériences.",
            'teacher' => "Concentre-toi sur la pédagogie, les matières enseignées, le type de public (étudiants, adultes), et la création de supports didactiques.",
            'freelance' => "Valorise l'autonomie, la diversité des clients, la gestion de projet de A à Z et la capacité à livrer des résultats concrets.",
            'pharmacist' => "Mets en avant les spécialités médicales, les compétences cliniques, la gestion des patients, et les certifications/diplômes précis.",
            'doctor' => "Mets en avant les spécialités médicales, les compétences cliniques, la gestion des patients, et les certifications/diplômes précis.",
            'lawyer' => "Focalise-toi sur les domaines de droit (pénal, affaires, etc.), la rédaction d'actes, le conseil juridique et la gestion de contentieux.",
            'notary' => "Focalise-toi sur les domaines de droit (pénal, affaires, etc.), la rédaction d'actes, le conseil juridique et la gestion de contentieux.",
            'bailiff' => "Focalise-toi sur les domaines de droit (pénal, affaires, etc.), la rédaction d'actes, le conseil juridique et la gestion de contentieux.",
            'architect' => "Mets l'accent sur les logiciels de CAO (AutoCAD, Revit), le style architectural, la gestion de chantier et les types de bâtiments conçus.",
            'engineer' => "Identifie les compétences techniques pointues, les outils d'ingénierie, la gestion de projets complexes et l'innovation.",
            'consultant' => "Valorise l'analyse stratégique, l'optimisation de processus, la conduite du changement et les résultats chiffrés (ROI) chez les clients.",
            'accountant' => "Concentre-toi sur les normes comptables, les outils de gestion (SAP, Excel avancé), l'audit et l'analyse de bilans.",
            'financial_analyst' => "Concentre-toi sur les normes comptables, les outils de gestion (SAP, Excel avancé), l'audit et l'analyse de bilans.",
            'photographer' => "Mets en avant les logiciels créatifs (Adobe Suite), le style artistique, et les types de projets (branding, éditorial, événementiel).",
            'graphic_designer' => "Mets en avant les logiciels créatifs (Adobe Suite), le style artistique, et les types de projets (branding, éditorial, événementiel).",
            'developer' => "LISTE IMPÉRATIVEMENT les langages, frameworks et outils (Git, Docker) dans les compétences. Pour chaque projet, tente d'identifier la stack technique utilisée.",
            'banker' => "Mets l'accent sur la gestion de portefeuille, les types de produits financiers vendus (crédits, placements), la conformité et la relation client B2B/B2C.",
        ];

        return $instructions[$profileType] ?? "Adopte un ton professionnel standard et synthétique.";
    }

    /**
     * Extrait les informations d'un document selon le type de profil
     */
    public function extractPortfolioInfoFromText($text, $profileType = 'student')
    {
        try {
            if (empty($this->apiKey)) {
                Log::error('Clé API Gemini non définie');
                return null;
            }

            // Définir les labels de profil (pour le prompt)
            $profilePrompts = [
                'student' => 'étudiant ou jeune diplômé',
                'teacher' => 'enseignant ou formateur',
                'freelance' => 'freelance ou indépendant',
                'pharmacist' => 'pharmacien',
                'doctor' => 'médecin',
                'lawyer' => 'avocat',
                'notary' => 'notaire',
                'bailiff' => 'huissier de justice',
                'architect' => 'architecte',
                'engineer' => 'ingénieur',
                'consultant' => 'consultant',
                'accountant' => 'expert-comptable',
                'financial_analyst' => 'analyste financier',
                'photographer' => 'photographe',
                'graphic_designer' => 'graphiste',
                'developer' => 'développeur',
                'banker' => 'banquier',
                'restaurant' => 'restaurateur',
            ];

            $profileLabel = $profilePrompts[$profileType] ?? 'professionnel';
            
            // Pour le profil restaurant, utiliser un prompt spécialisé pour extraire le menu
            if ($profileType === 'restaurant') {
                return $this->extractRestaurantMenuFromText($text);
            }

            $profileInstruction = $this->getProfileSpecificInstruction($profileType);

            $prompt = <<<PROMPT
Tu es un expert en analyse de documents professionnels pour un {$profileLabel}.

INSTRUCTION SPÉCIFIQUE AU PROFIL : {$profileInstruction}

Analyse le texte suivant extrait d'un CV, lettre de motivation ou document professionnel et retourne UNIQUEMENT un objet JSON valide avec les informations structurées.

Voici le contenu TEXTUEL BRUT du CV (ou document) à analyser :

{$text}

RÈGLE ABSOLUE - PREMIÈRE PERSONNE DU SINGULIER:
TOUT le contenu généré (bio, projects, timeline) DOIT être écrit à la PREMIÈRE PERSONNE DU SINGULIER :
- Utilise: je, mon, ma, mes, j'ai, je suis, j'ai réalisé, j'ai développé, j'ai géré, etc.
- N'utilise JAMAIS: il, elle, son, sa, ses, il a, elle est, il a réalisé, elle a développé, etc.
- Les descriptions doivent commencer par "J'ai...", "Je suis...", "Mon...", "Ma...", "Mes...", etc.

IMPORTANT - SÉPARATION FORMATIONS / EXPÉRIENCES PROFESSIONNELLES:
Tu dois analyser le texte et séparer les informations en DEUX sections distinctes :
1. **formations** : Toutes les formations (scolaire, universitaire, formations professionnelles, certifications, etc.)
2. **timeline** : Toutes les expériences professionnelles (stages, emplois, missions, etc.)

Pour chaque élément, extrais la date du texte si elle est mentionnée, et utilise-la dans le format approprié.

Retourne UNIQUEMENT un objet JSON valide avec cette structure EXACTE. Ne retourne AUCUN texte avant ou après le JSON, AUCUNE balise markdown, AUCUN commentaire:

{
  "bio": "Une biographie professionnelle basée sur le document (2-3 phrases) ÉCRITE À LA PREMIÈRE PERSONNE. Exemple: 'Je suis un {$profileLabel}...' ou 'Mon parcours m'a permis de...'",
  "hero_headline": "Un titre professionnel accrocheur adapté au profil",
  "formations_title": "Mes Formations",
  "timeline_title": "Mon Parcours Professionnel",
  "skills": [
    {
      "icon": "🏷️",
      "name": "Nom de la compétence"
    }
  ],
  "projects": [
    {
      "title": "Titre du projet/réalisation",
      "short_description": "Description courte à la première personne (ex: 'J'ai développé...')",
      "full_description": "Description détaillée à la première personne (ex: 'J'ai géré...', 'Mon rôle consistait à...')"
    }
  ],
  "formations": [
    {
      "title": "Titre de la formation (ex: 'Master 2 en...', 'Licence en...', 'Baccalauréat...', 'Formation en...', 'Séminaire en...')",
      "organization": "Établissement ou organisme de formation",
      "date": "Date ou période OBLIGATOIRE (extrait du texte ou déduis du contexte, format: '2016-2019', '2024', 'Depuis 2024', etc. - NE METS JAMAIS null)",
      "description": "Description à la première personne (ex: 'J'ai suivi une formation en...', 'J'ai obtenu mon diplôme de...', 'J'ai acquis des compétences en...')"
    }
  ],
  "timeline": [
    {
      "title": "Titre du poste/stage (ex: 'Assistant Chargé d'Affaires', 'Stagiaire en...')",
      "organization": "Nom de l'entreprise ou organisation",
      "date": "Date ou période (extrait du texte si disponible, sinon null)",
      "description": "Description de l'expérience à la première personne (ex: 'J'ai occupé le poste de...', 'Mes responsabilités incluaient...', 'J'ai géré...')"
    }
  ],
  "email": "Email si trouvé, sinon null",
  "phone": "Téléphone si trouvé, sinon null",
  "linkedin_url": "URL LinkedIn si trouvée, sinon null"
}

RÈGLES STRICTES:
1. Retourne UNIQUEMENT le JSON brut, sans markdown, sans texte avant/après
2. Utilise des guillemets doubles pour toutes les chaînes
3. Échappe correctement les caractères spéciaux dans les chaînes (\\, ", etc.)
4. Les compétences doivent être pertinentes pour le profil {$profileLabel}
5. Les projets peuvent être des réalisations professionnelles, des projets académiques, des missions, etc. selon le profil
6. **formations** (PRIORITÉ ABSOLUE - AUCUNE OMISSION): Compte toutes les formations dans le texte (Bac, Licence, Master, BTS, DUT, certifications, séminaires, etc.) puis retourne EXACTEMENT ce nombre d'entrées dans "formations". Si le texte mentionne 6 formations, le JSON doit contenir 6 objets. Ne tronque jamais ce tableau. Pour chaque formation: "date" OBLIGATOIRE (jamais null). Classe par ordre chronologique décroissant.
7. **timeline** : Inclus UNIQUEMENT les expériences professionnelles (stages, emplois, missions). Pour chaque expérience, "date" si disponible. Classe par ordre chronologique décroissant.
8. Adapte le vocabulaire et les termes au contexte du profil {$profileLabel}
9. Si une information n'est pas trouvée (sauf pour les dates de formations qui sont obligatoires), utilise null (pas de chaîne vide)
10. LIMITE les descriptions à 300 caractères maximum pour éviter les troncatures
11. Assure-toi que le JSON est COMPLET et VALIDE avant de le retourner
12. TOUT le contenu textuel (bio, projects.full_description, formations.description, timeline.description) DOIT être à la PREMIÈRE PERSONNE DU SINGULIER
13. Extrais TOUTES les dates du texte et place-les dans le champ "date" de chaque élément (formations et timeline). Pour les formations, la date est OBLIGATOIRE - cherche dans tout le texte même si elle est séparée du titre
14. SOIS EXHAUSTIF : Extrais TOUTES les formations mentionnées, même si elles sont anciennes ou mentionnées brièvement
15. RÈGLE FORMATIONS : Avant de fermer le JSON, vérifie que le nombre d'éléments dans "formations" correspond à toutes les formations listées dans le texte. Aucune formation ne doit manquer.
PROMPT;

            $maxTokens = config('gemini.max_output_tokens', 16384);
            $response = Http::timeout(90)->post($this->apiUrl . '?key=' . $this->apiKey, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.3, // Réduire pour plus de cohérence
                    'topK' => 40,
                    'topP' => 0.95,
                    'maxOutputTokens' => $maxTokens, // Limite élevée pour ne pas tronquer les formations
                ]
            ]);

            if ($response->successful()) {
                $result = $response->json();
                $generatedText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

                // Nettoyer le JSON de manière plus agressive
                // Enlever les balises markdown
                $generatedText = preg_replace('/```json\s*/i', '', $generatedText);
                $generatedText = preg_replace('/```\s*$/i', '', $generatedText);
                $generatedText = preg_replace('/^```\s*/i', '', $generatedText);
                
                // Enlever tout texte avant le premier {
                $firstBrace = strpos($generatedText, '{');
                if ($firstBrace !== false && $firstBrace > 0) {
                    $generatedText = substr($generatedText, $firstBrace);
                }
                
                // Enlever tout texte après le dernier }
                $lastBrace = strrpos($generatedText, '}');
                if ($lastBrace !== false && $lastBrace < strlen($generatedText) - 1) {
                    $generatedText = substr($generatedText, 0, $lastBrace + 1);
                }
                
                $generatedText = trim($generatedText);

                // Logger seulement la longueur pour éviter les logs trop longs
                Log::info('Texte généré par Gemini pour extraction portfolio (' . $profileType . '), longueur: ' . strlen($generatedText));

                $extractedData = json_decode($generatedText, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($extractedData)) {
                    return $extractedData;
                } else {
                    $errorMsg = json_last_error_msg();
                    $errorCode = json_last_error();
                    Log::error('Erreur JSON lors de l\'extraction portfolio: ' . $errorMsg . ' (code: ' . $errorCode . ')');
                    Log::error('JSON reçu (premiers 2000 caractères): ' . substr($generatedText, 0, 2000));
                    if (strlen($generatedText) > 2000) {
                        Log::error('JSON reçu (derniers 500 caractères): ' . substr($generatedText, -500));
                    }
                    
                    // Essayer de réparer le JSON en extrayant seulement la partie valide avec regex
                    if (preg_match('/\{[\s\S]*\}/', $generatedText, $matches)) {
                        $cleanedJson = $matches[0];
                        $extractedData = json_decode($cleanedJson, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($extractedData)) {
                            Log::info('JSON réparé avec succès après extraction regex');
                            return $extractedData;
                        } else {
                            Log::error('Échec de la réparation JSON regex: ' . json_last_error_msg());
                        }
                    }
                    
                    // Dernière tentative : essayer de trouver le JSON valide en cherchant le premier { et dernier }
                    $firstBrace = strpos($generatedText, '{');
                    $lastBrace = strrpos($generatedText, '}');
                    if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
                        $potentialJson = substr($generatedText, $firstBrace, $lastBrace - $firstBrace + 1);
                        $extractedData = json_decode($potentialJson, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($extractedData)) {
                            Log::info('JSON réparé avec succès après extraction manuelle');
                            return $extractedData;
                        } else {
                            Log::error('Échec de la réparation JSON manuelle: ' . json_last_error_msg());
                        }
                    }
                    
                    // Tentative finale : réparer le JSON tronqué en supprimant les éléments incomplets
                    if ($firstBrace !== false) {
                        $truncatedJson = substr($generatedText, $firstBrace);
                        
                        // Stratégie : trouver le dernier élément complet et supprimer tout ce qui suit
                        // Chercher le dernier }, ] qui ferme un élément complet
                        $lastCompleteElement = -1;
                        $depth = 0;
                        $inString = false;
                        $escapeNext = false;
                        
                        for ($i = 0; $i < strlen($truncatedJson); $i++) {
                            $char = $truncatedJson[$i];
                            
                            if ($escapeNext) {
                                $escapeNext = false;
                                continue;
                            }
                            
                            if ($char === '\\') {
                                $escapeNext = true;
                                continue;
                            }
                            
                            if ($char === '"' && !$escapeNext) {
                                $inString = !$inString;
                                continue;
                            }
                            
                            if (!$inString) {
                                if ($char === '{' || $char === '[') {
                                    $depth++;
                                } elseif ($char === '}' || $char === ']') {
                                    $depth--;
                                    if ($depth === 0) {
                                        // On a trouvé la fin d'un élément de niveau racine
                                        $lastCompleteElement = $i;
                                    }
                                }
                            }
                        }
                        
                        // Si on a trouvé un élément complet, couper là
                        if ($lastCompleteElement > 0) {
                            $truncatedJson = substr($truncatedJson, 0, $lastCompleteElement + 1);
                            $extractedData = json_decode($truncatedJson, true);
                            if (json_last_error() === JSON_ERROR_NONE && is_array($extractedData)) {
                                Log::info('JSON réparé avec succès en supprimant les éléments incomplets');
                                return $extractedData;
                            }
                        }
                        
                        // Si ça n'a pas marché, essayer de fermer proprement
                        // Compter les accolades ouvertes/fermées
                        $openBraces = substr_count($truncatedJson, '{');
                        $closeBraces = substr_count($truncatedJson, '}');
                        $openBrackets = substr_count($truncatedJson, '[');
                        $closeBrackets = substr_count($truncatedJson, ']');
                        
                        // Trouver la dernière position d'une chaîne
                        $lastQuote = strrpos($truncatedJson, '"');
                        $isInString = false;
                        
                        if ($lastQuote !== false) {
                            $beforeLastQuote = substr($truncatedJson, 0, $lastQuote);
                            $escapedQuotes = preg_match_all('/\\\\"/', $beforeLastQuote);
                            $totalQuotes = substr_count($beforeLastQuote, '"');
                            $unescapedQuotes = $totalQuotes - $escapedQuotes;
                            $isInString = ($unescapedQuotes % 2 === 1);
                        }
                        
                        // Si on est dans une chaîne, trouver où elle commence et la supprimer complètement
                        if ($isInString && $lastQuote !== false) {
                            // Chercher le guillemet ouvrant correspondant
                            $beforeLastQuote = substr($truncatedJson, 0, $lastQuote);
                            $openQuotePos = strrpos($beforeLastQuote, ':');
                            if ($openQuotePos !== false) {
                                // Chercher le guillemet après le :
                                $afterColon = substr($truncatedJson, $openQuotePos + 1);
                                $firstQuoteAfterColon = strpos($afterColon, '"');
                                if ($firstQuoteAfterColon !== false) {
                                    $actualOpenQuote = $openQuotePos + 1 + $firstQuoteAfterColon;
                                    // Supprimer toute la chaîne incomplète
                                    $truncatedJson = substr($truncatedJson, 0, $actualOpenQuote) . '""';
                                }
                            }
                        }
                        
                        // Fermer les tableaux et objets ouverts
                        for ($i = 0; $i < ($openBrackets - $closeBrackets); $i++) {
                            $truncatedJson .= ']';
                        }
                        for ($i = 0; $i < ($openBraces - $closeBraces); $i++) {
                            $truncatedJson .= '}';
                        }
                        
                        $extractedData = json_decode($truncatedJson, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($extractedData)) {
                            Log::info('JSON réparé avec succès après fermeture automatique');
                            return $extractedData;
                        } else {
                            Log::error('Échec de la réparation JSON avec fermeture automatique: ' . json_last_error_msg());
                        }
                    }
                    
                    return null;
                }
            } else {
                Log::error('Erreur API Gemini lors de l\'analyse du texte portfolio: ' . $response->body());
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Exception lors de l\'analyse du texte portfolio: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'profile_type' => $profileType
            ]);
            return null;
        }
    }

    /**
     * Extrait le menu d'un restaurant depuis un texte (image/PDF de menu)
     */
    public function extractRestaurantMenuFromText($text)
    {
        try {
            if (empty($this->apiKey)) {
                Log::error('Clé API Gemini non définie');
                return null;
            }

            $prompt = <<<PROMPT
Tu es un expert en analyse de menus de restaurant. Analyse le texte suivant extrait d'une image ou PDF de menu de restaurant et retourne UNIQUEMENT un objet JSON valide avec les plats et boissons structurés.

Texte à analyser:
{$text}

Retourne UNIQUEMENT un objet JSON valide avec cette structure EXACTE. Ne retourne AUCUN texte avant ou après le JSON, AUCUNE balise markdown, AUCUN commentaire:

{
  "bio": "Une description du restaurant basée sur le menu (2-3 phrases) ÉCRITE À LA PREMIÈRE PERSONNE. Exemple: 'Je propose une cuisine...' ou 'Mon restaurant se spécialise dans...'",
  "hero_headline": "Nom du restaurant ou spécialité culinaire",
  "menu": {
    "dishes": [
      {
        "name": "Nom du plat (ex: 'Poulet Yassa', 'Riz au gras', 'Mafé')",
        "price": 15000,
        "description": "Description du plat si disponible",
        "available": true,
        "hasSides": false,
        "sides": []
      }
    ],
    "drinks": [
      {
        "name": "Nom de la boisson (ex: 'Jus de Bissap', 'Coca-Cola', 'Eau minérale')",
        "price": 3000,
        "available": true
      }
    ]
  }
}

RÈGLES STRICTES:
1. Retourne UNIQUEMENT le JSON brut, sans markdown, sans texte avant/après
2. Extrais TOUS les plats et boissons mentionnés dans le menu
3. Pour chaque plat/boisson, extrais le prix si mentionné (en GNF ou convertis en GNF)
4. Si un prix n'est pas trouvé, utilise 0
5. Si un plat a des accompagnements mentionnés (ex: "avec riz", "avec frites"), mets hasSides à true et liste-les dans sides
6. Les descriptions sont optionnelles - utilise une chaîne vide si non disponible
7. Par défaut, tous les plats et boissons sont disponibles (available: true)
8. Utilise des guillemets doubles pour toutes les chaînes
9. Échappe correctement les caractères spéciaux dans les chaînes
10. La bio doit être à la PREMIÈRE PERSONNE DU SINGULIER (je, mon, ma, mes, j'ai, je suis, etc.)
11. Si le menu contient des catégories (Entrées, Plats principaux, Desserts, Boissons), organise-les correctement
12. Extrais TOUS les prix même s'ils sont dans différents formats (ex: "15 000", "15.000", "15,000", "15k")
13. Assure-toi que le JSON est COMPLET et VALIDE avant de le retourner
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
                    'temperature' => 0.3,
                    'topK' => 40,
                    'topP' => 0.95,
                    'maxOutputTokens' => 8192,
                ]
            ]);

            if ($response->successful()) {
                $result = $response->json();
                $generatedText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

                // Nettoyer le JSON de manière agressive (même logique que extractPortfolioInfoFromText)
                $generatedText = preg_replace('/```json\s*/i', '', $generatedText);
                $generatedText = preg_replace('/```\s*$/i', '', $generatedText);
                $generatedText = preg_replace('/^```\s*/i', '', $generatedText);
                
                $firstBrace = strpos($generatedText, '{');
                if ($firstBrace !== false && $firstBrace > 0) {
                    $generatedText = substr($generatedText, $firstBrace);
                }
                
                $lastBrace = strrpos($generatedText, '}');
                if ($lastBrace !== false && $lastBrace < strlen($generatedText) - 1) {
                    $generatedText = substr($generatedText, 0, $lastBrace + 1);
                }
                
                $generatedText = trim($generatedText);

                Log::info('Texte généré par Gemini pour extraction menu restaurant, longueur: ' . strlen($generatedText));

                $extractedData = json_decode($generatedText, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($extractedData)) {
                    return $extractedData;
                } else {
                    Log::error('Erreur JSON lors de l\'extraction menu restaurant: ' . json_last_error_msg());
                    Log::error('JSON reçu (premiers 2000 caractères): ' . substr($generatedText, 0, 2000));
                    
                    // Essayer de réparer le JSON (même logique que extractPortfolioInfoFromText)
                    if (preg_match('/\{[\s\S]*\}/', $generatedText, $matches)) {
                        $cleanedJson = $matches[0];
                        $extractedData = json_decode($cleanedJson, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($extractedData)) {
                            Log::info('JSON réparé avec succès après extraction regex (menu restaurant)');
                            return $extractedData;
                        }
                    }
                    
                    return null;
                }
            } else {
                Log::error('Erreur API Gemini lors de l\'analyse du menu restaurant: ' . $response->body());
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Exception lors de l\'analyse du menu restaurant: ' . $e->getMessage());
            return null;
        }
    }
}

