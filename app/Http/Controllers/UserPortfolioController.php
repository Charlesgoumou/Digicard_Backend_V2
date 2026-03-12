<?php

namespace App\Http\Controllers;

use App\Models\UserPortfolio;
use App\Models\Order;
use App\Services\GeminiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class UserPortfolioController extends Controller
{
    /**
     * Récupère ou crée le portfolio de l'utilisateur
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Seuls les utilisateurs avec role 'individual' peuvent avoir un portfolio
        if ($user->role !== 'individual') {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        // Récupérer ou créer le portfolio
        $portfolio = UserPortfolio::firstOrCreate(
            ['user_id' => $user->id],
            [
                'name' => $user->name,
                'hero_headline' => $user->title,
                'primary_color' => $user->profile_border_color ?? '#6366f1',
                'secondary_color' => '#ffffff',
                'skills' => [],
                'projects' => [],
                'timeline' => [],
                'formations' => [],
                'formations_title' => 'Mes Formations',
                'timeline_title' => 'Mon Parcours Professionnel',
            ]
        );

        // Toujours mettre à jour le nom, headline et photo avec les valeurs les plus récentes de la section "Ma Carte"
        $portfolio->name = $user->name;
        $portfolio->hero_headline = $user->title;

        // Si formations est vide mais timeline contient des données, essayer de séparer automatiquement
        // (pour les portfolios créés avant la séparation formations/timeline)
        if (empty($portfolio->formations) && !empty($portfolio->timeline) && is_array($portfolio->timeline)) {
            $formations = [];
            $professionalExperiences = [];

            foreach ($portfolio->timeline as $item) {
                $title = strtolower($item['title'] ?? '');
                $description = strtolower($item['description'] ?? '');

                // Mots-clés indiquant une formation
                $formationKeywords = [
                    'master', 'licence', 'baccalauréat', 'bac', 'diplôme', 'diploma',
                    'formation', 'certification', 'certificat', 'université', 'universite',
                    'école', 'ecole', 'lycée', 'lycee', 'collège', 'college', 'académie',
                    'academie', 'institut', 'bts', 'dut', 'deug', 'maîtrise', 'maitrise',
                    'doctorat', 'phd', 'thèse', 'these', 'séminaire', 'seminaire', 'cours'
                ];

                // Mots-clés indiquant une expérience professionnelle
                $professionalKeywords = [
                    'stage', 'stagiare', 'emploi', 'travail', 'poste', 'mission',
                    'assistant', 'chargé', 'charge', 'gestionnaire', 'responsable',
                    'directeur', 'manager', 'consultant', 'freelance', 'indépendant',
                    'independant', 'entreprise', 'société', 'societe', 'banque', 'agence'
                ];

                $isFormation = false;
                $isProfessional = false;

                // Vérifier les mots-clés dans le titre
                foreach ($formationKeywords as $keyword) {
                    if (strpos($title, $keyword) !== false) {
                        $isFormation = true;
                        break;
                    }
                }

                foreach ($professionalKeywords as $keyword) {
                    if (strpos($title, $keyword) !== false) {
                        $isProfessional = true;
                        break;
                    }
                }

                // Si ambigu, vérifier la description
                if (!$isFormation && !$isProfessional) {
                    foreach ($formationKeywords as $keyword) {
                        if (strpos($description, $keyword) !== false) {
                            $isFormation = true;
                            break;
                        }
                    }
                }

                // Classer l'élément
                // Vérifier aussi si c'est un stage (qui peut être considéré comme formation ou expérience)
                $isStage = strpos($title, 'stage') !== false || strpos($title, 'stagiare') !== false || strpos($title, 'stagiaire') !== false;

                if ($isFormation && !$isProfessional) {
                    // C'est clairement une formation
                    $formations[] = [
                        'title' => $item['title'] ?? '',
                        'organization' => $item['organization'] ?? '',
                        'date' => $item['date'] ?? $item['dates'] ?? null,
                        'description' => $item['description'] ?? $item['details'] ?? '',
                    ];
                } elseif ($isStage && !$isFormation) {
                    // Les stages vont dans les expériences professionnelles
                    $professionalExperiences[] = $item;
                } elseif (!$isFormation) {
                    // Tout le reste va dans les expériences professionnelles
                    $professionalExperiences[] = $item;
                } else {
                    // Si ambigu (formation ET professionnel), mettre dans les deux ou prioriser selon le contexte
                    // Par défaut, si ça contient des mots de formation, c'est une formation
                    $formations[] = [
                        'title' => $item['title'] ?? '',
                        'organization' => $item['organization'] ?? '',
                        'date' => $item['date'] ?? $item['dates'] ?? null,
                        'description' => $item['description'] ?? $item['details'] ?? '',
                    ];
                }
            }

            // Sauvegarder la séparation (même si formations est vide, on met à jour pour nettoyer)
            $portfolio->formations = $formations;
            $portfolio->timeline = $professionalExperiences;

            // Toujours s'assurer que les titres sont corrects
            if (empty($portfolio->formations_title)) {
                $portfolio->formations_title = 'Mes Formations';
            }
            if (empty($portfolio->timeline_title) || $portfolio->timeline_title === 'Mon Parcours' || $portfolio->timeline_title === 'Ma Formation & Stages') {
                $portfolio->timeline_title = 'Mon Parcours Professionnel';
            }

            $portfolio->save();
        }

        // Récupérer la photo depuis la dernière commande configurée
        $order = Order::where('user_id', $user->id)
            ->where('status', '!=', 'cancelled')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($order && $order->order_avatar_url) {
            $portfolio->photo_url = $order->order_avatar_url;
        }

        // Récupérer les couleurs depuis la carte utilisateur
        if ($user->profile_border_color) {
            $portfolio->primary_color = $user->profile_border_color;
        }
        if ($user->save_contact_button_color) {
            $portfolio->secondary_color = $user->save_contact_button_color;
        }

        // Corriger les titres selon le type de profil si nécessaire
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

        if ($portfolio->profile_type && isset($profileLabels[$portfolio->profile_type])) {
            $expectedLabels = $profileLabels[$portfolio->profile_type];

            // Corriger les titres s'ils ne correspondent pas au profil
            if ($portfolio->projects_title !== $expectedLabels['projects']) {
                $portfolio->projects_title = $expectedLabels['projects'];
            }
        }

        // TOUJOURS définir les titres standards pour formations et parcours professionnel pour TOUS les profils
        // "Mes Formations" est obligatoire pour tous les profils
        if (empty($portfolio->formations_title) || $portfolio->formations_title !== 'Mes Formations') {
            $portfolio->formations_title = 'Mes Formations';
        }

        // "Mon Parcours Professionnel" est obligatoire pour tous les profils
        // Corriger les anciens titres qui ne correspondent plus
        $oldTimelineTitles = ['Mon Parcours', 'Ma Formation & Stages', 'Mon Parcours & Clients', 'Mes Formations'];
        if (empty($portfolio->timeline_title) || in_array($portfolio->timeline_title, $oldTimelineTitles)) {
            $portfolio->timeline_title = 'Mon Parcours Professionnel';
        }

        // Sauvegarder les mises à jour
        $portfolio->save();

        return response()->json($portfolio);
    }

    /**
     * Met à jour le portfolio utilisateur
     */
    public function update(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'individual') {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        $validated = $request->validate([
            'profile_type' => 'nullable|in:student,teacher,freelance,pharmacist,doctor,lawyer,notary,bailiff,architect,engineer,consultant,accountant,financial_analyst,photographer,graphic_designer,developer,banker,restaurant',
            'menu' => 'nullable|array',
            'menu.dishes' => 'nullable|array',
            'menu.dishes.*.name' => 'required_with:menu.dishes|string',
            'menu.dishes.*.price' => 'nullable|numeric|min:0',
            'menu.dishes.*.description' => 'nullable|string',
            'menu.dishes.*.image' => 'nullable|string',
            'menu.dishes.*.available' => 'nullable|boolean',
            'menu.dishes.*.hasSides' => 'nullable|boolean',
            'menu.dishes.*.sides' => 'nullable|array',
            'menu.drinks' => 'nullable|array',
            'menu.drinks.*.name' => 'required_with:menu.drinks|string',
            'menu.drinks.*.price' => 'nullable|numeric|min:0',
            'menu.drinks.*.image' => 'nullable|string',
            'menu.drinks.*.available' => 'nullable|boolean',
            'name' => 'nullable|string|max:255',
            'hero_headline' => 'nullable|string|max:255',
            'bio' => 'nullable|string',
            'skills' => 'nullable|array',
            'skills.*.icon' => 'required_with:skills|string',
            'skills.*.name' => 'required_with:skills|string',
            'projects' => 'nullable|array',
            'projects.*.title' => 'required_with:projects|string',
            'projects.*.short_description' => 'nullable|string',
            'projects.*.details_html' => 'nullable|string',
            'projects.*.link' => 'nullable|url',
            'projects.*.icon' => 'nullable|string',
            'formations' => 'nullable|array',
            'formations.*.title' => 'required_with:formations|string',
            'formations.*.organization' => 'nullable|string',
            'formations.*.date' => 'nullable|string',
            'formations.*.description' => 'nullable|string',
            'timeline' => 'nullable|array',
            'timeline.*.title' => 'required_with:timeline|string',
            'timeline.*.organization' => 'nullable|string',
            'timeline.*.date' => 'nullable|string',
            'timeline.*.description' => 'nullable|string',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'linkedin_url' => 'nullable|url',
            'github_url' => 'nullable|url',
            'primary_color' => 'nullable|string|max:7',
            'secondary_color' => 'nullable|string|max:7',
            'skills_title' => 'nullable|string',
            'projects_title' => 'nullable|string',
            'formations_title' => 'nullable|string',
            'timeline_title' => 'nullable|string',
        ]);

        // Dédupliquer le menu avant de sauvegarder si c'est un profil restaurant
        if (isset($validated['menu']) && is_array($validated['menu']) && isset($validated['profile_type']) && $validated['profile_type'] === 'restaurant') {
            $validated['menu'] = $this->deduplicateMenu($validated['menu']);
        }

        $portfolio = UserPortfolio::updateOrCreate(
            ['user_id' => $user->id],
            $validated
        );

        // Récupérer la photo depuis la dernière commande configurée
        $order = Order::where('user_id', $user->id)
            ->where('status', '!=', 'cancelled')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($order && $order->order_avatar_url && !$request->has('photo_url')) {
            $portfolio->photo_url = $order->order_avatar_url;
            $portfolio->save();
        }

        return response()->json([
            'message' => 'Portfolio mis à jour avec succès.',
            'portfolio' => $portfolio,
        ]);
    }

    /**
     * Upload de photo (utilise la photo de la carte utilisateur)
     */
    public function uploadPhoto(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'individual') {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        // Au lieu d'uploader une nouvelle photo, on récupère celle de la commande
        $order = Order::where('user_id', $user->id)
            ->where('status', '!=', 'cancelled')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$order || !$order->order_avatar_url) {
            return response()->json([
                'message' => 'Aucune photo disponible. Veuillez d\'abord configurer votre carte.',
            ], 404);
        }

        // Mettre à jour le portfolio avec la photo
        $portfolio = UserPortfolio::updateOrCreate(
            ['user_id' => $user->id],
            ['photo_url' => $order->order_avatar_url]
        );

        return response()->json([
            'message' => 'Photo récupérée avec succès depuis votre carte.',
            'photo_url' => url($portfolio->photo_url),
        ]);
    }

    /**
     * Génère automatiquement le contenu portfolio avec Gemini AI
     */
    public function generateContent(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'individual') {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        // Récupérer les données nécessaires
        $profileType = $request->input('profile_type');
        $name = $request->input('name');
        $heroHeadline = $request->input('hero_headline');
        $bio = $request->input('bio');
        $skills = $request->input('skills', []);
        $projects = $request->input('projects', []);
        $timeline = $request->input('timeline', []);
        $menu = $request->input('menu', []);
        $primaryColor = $request->input('primary_color', '#6366f1');
        $promptTemplate = $request->input('prompt_template');

        // Vérifier que les données minimales sont présentes
        if (empty($profileType)) {
            return response()->json([
                'message' => 'Le type de profil est requis pour générer le contenu.'
            ], 400);
        }

        if (empty($name)) {
            return response()->json([
                'message' => 'Le nom est requis pour générer le contenu.'
            ], 400);
        }

        // Pour le profil restaurant, vérifier que le menu est présent
        if ($profileType === 'restaurant') {
            if (empty($menu) || (empty($menu['dishes']) && empty($menu['drinks']))) {
                return response()->json([
                    'message' => 'Le menu (plats ou boissons) est requis pour générer le contenu du restaurant.'
                ], 400);
            }
        } else {
            // Pour les autres profils, vérifier projets ou timeline
            if ((empty($projects) || count($projects) === 0) && (empty($timeline) || count($timeline) === 0)) {
                return response()->json([
                    'message' => 'Au moins un projet ou un événement est requis pour générer le contenu.'
                ], 400);
            }
        }

        try {
            // Préparer les données pour Gemini
            $geminiService = new GeminiService();

            // Pour le profil restaurant, ne pas inclure le menu dans companyData
            // car on veut que Gemini génère uniquement le contenu textuel (bio, hero_headline)
            // Le menu sera préservé séparément
            $companyData = [
                'profile_type' => (string) $profileType,
                'name' => $name,
                'hero_headline' => $heroHeadline,
                'bio' => $bio,
                'skills' => $skills,
                'projects' => $projects,
                'timeline' => $timeline,
                'primary_color' => $primaryColor,
            ];

            // Pour le restaurant, ajouter le menu séparément pour que Gemini puisse l'utiliser comme contexte
            if ($profileType === 'restaurant' && !empty($menu)) {
                $companyData['menu'] = $menu;
            }

            // Appeler Gemini avec le prompt adaptatif
            $generatedContent = $geminiService->generateUserPortfolioContent($companyData, $promptTemplate);

            if (!$generatedContent) {
                return response()->json([
                    'message' => 'Erreur lors de la génération du contenu avec l\'IA.',
                ], 500);
            }

            // Récupérer le portfolio existant s'il existe
            $portfolio = UserPortfolio::where('user_id', $user->id)->first();

            // Fusionner uniquement les données de base avec le contenu généré
            // S'assurer que profile_type n'est pas écrasé par le contenu généré
            $dataToSave = array_merge($generatedContent, $companyData);

            // Pour le profil restaurant, s'assurer que le menu est préservé SANS duplication
            if ($profileType === 'restaurant') {
                // Toujours utiliser le menu existant (celui envoyé par l'utilisateur)
                // Ne pas fusionner avec le menu généré par Gemini pour éviter les doublons
                if (!empty($menu)) {
                    // Dédupliquer le menu existant avant de le sauvegarder
                    $dataToSave['menu'] = $this->deduplicateMenu($menu);
                } elseif (isset($generatedContent['menu']) && is_array($generatedContent['menu'])) {
                    // Si pas de menu existant mais qu'il y en a un dans le contenu généré, l'utiliser
                    // Mais d'abord, dédupliquer les plats et boissons par nom
                    $dataToSave['menu'] = $this->deduplicateMenu($generatedContent['menu']);
                }
            }

            // Forcer profile_type pour éviter les problèmes d'échappement SQL
            // S'assurer que c'est toujours une chaîne valide
            $dataToSave['profile_type'] = (string) $profileType;

            // S'assurer que user_id est présent
            $dataToSave['user_id'] = $user->id;

            // TOUJOURS définir les titres standards pour formations et parcours professionnel
            // Ces titres sont obligatoires pour TOUS les profils
            $dataToSave['formations_title'] = 'Mes Formations';
            $dataToSave['timeline_title'] = 'Mon Parcours Professionnel';

            // Filtrer les clés pour ne garder que celles qui sont dans fillable
            $fillable = (new \App\Models\UserPortfolio)->getFillable();
            $dataToSave = array_intersect_key($dataToSave, array_flip($fillable));

            if ($portfolio) {
                // Mettre à jour le portfolio
                $portfolio->update($dataToSave);
            } else {
                // Créer le portfolio
                $portfolio = UserPortfolio::create($dataToSave);
            }

            // Récupérer la photo depuis la commande
            $order = Order::where('user_id', $user->id)
                ->where('status', '!=', 'cancelled')
                ->orderBy('created_at', 'desc')
                ->first();

            if ($order && $order->order_avatar_url) {
                $portfolio->photo_url = $order->order_avatar_url;
                $portfolio->save();
            }

            return response()->json([
                'message' => 'Contenu généré avec succès.',
                'portfolio' => $portfolio->fresh(),
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la génération du portfolio: ' . $e->getMessage());
            return response()->json([
                'message' => 'Erreur lors de la génération du contenu.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Affiche le portfolio public d'un utilisateur
     */
    public function show($username)
    {
        // Trouver l'utilisateur par username
        $user = \App\Models\User::where('username', $username)
            ->where('role', 'individual')
            ->firstOrFail();

        // Récupérer son portfolio
        $portfolio = UserPortfolio::where('user_id', $user->id)
            ->firstOrFail();

        // Récupérer la configuration des rendez-vous
        // Pour les portfolios, on récupère la première commande configurée pour les rendez-vous
        $order = Order::where('user_id', $user->id)
            ->where('status', '!=', 'cancelled')
            ->orderBy('created_at', 'desc')
            ->first();

        $appointmentSetting = null;
        $appointmentOrderId = null;

        if ($order) {
            $appointmentOrderId = $order->id;
            $appointmentSetting = \App\Models\AppointmentSetting::where('user_id', $user->id)
                ->where('order_id', $order->id)
                ->first();
        }

        // Retourner la vue Blade au lieu du JSON
        return view('portfolio.public', [
            'user' => $user,
            'portfolio' => $portfolio,
            'appointmentSetting' => $appointmentSetting,
            'appointmentOrderId' => $appointmentOrderId,
            'profileType' => $portfolio->profile_type ?? 'student', // Passer le type de profil à la vue
        ]);
    }

    /**
     * Affiche le menu du restaurant d'un utilisateur
     */
    public function showMenu($username)
    {
        // Trouver l'utilisateur par username
        $user = \App\Models\User::where('username', $username)
            ->where('role', 'individual')
            ->firstOrFail();

        // Récupérer son portfolio
        $portfolio = UserPortfolio::where('user_id', $user->id)
            ->firstOrFail();

        // Vérifier que c'est un profil restaurant
        if ($portfolio->profile_type !== 'restaurant') {
            abort(404, 'Ce profil n\'est pas un restaurant.');
        }

        // Vérifier que le menu existe
        if (!$portfolio->menu || (empty($portfolio->menu['dishes']) && empty($portfolio->menu['drinks']))) {
            abort(404, 'Aucun menu disponible pour ce restaurant.');
        }

        // Dédupliquer le menu avant de l'afficher (au cas où il y aurait des doublons existants)
        $deduplicatedMenu = $this->deduplicateMenu($portfolio->menu);

        // Si le menu a été modifié (doublons supprimés), le sauvegarder
        if ($deduplicatedMenu !== $portfolio->menu) {
            $portfolio->menu = $deduplicatedMenu;
            $portfolio->save();
        }

        // Retourner la vue Blade du menu
        return view('portfolio.menu', [
            'user' => $user,
            'portfolio' => $portfolio,
        ]);
    }

    /**
     * Déduplique les plats et boissons d'un menu en se basant sur le nom
     * Conserve les éléments avec description en priorité
     */
    private function deduplicateMenu($menu)
    {
        if (!is_array($menu)) {
            return $menu;
        }

        $deduplicated = [];

        // Dédupliquer les plats
        if (isset($menu['dishes']) && is_array($menu['dishes'])) {
            $seenDishes = [];
            $deduplicated['dishes'] = [];

            foreach ($menu['dishes'] as $dish) {
                $dishName = strtolower(trim($dish['name'] ?? ''));
                if (!empty($dishName)) {
                    if (!isset($seenDishes[$dishName])) {
                        // Premier plat avec ce nom, l'ajouter
                        $seenDishes[$dishName] = count($deduplicated['dishes']);
                        $deduplicated['dishes'][] = $dish;
                    } else {
                        // Doublon trouvé, vérifier si on doit remplacer
                        $existingIndex = $seenDishes[$dishName];
                        $existingDish = $deduplicated['dishes'][$existingIndex];

                        // Conserver celui qui a une description
                        $existingHasDescription = !empty($existingDish['description'] ?? '');
                        $newHasDescription = !empty($dish['description'] ?? '');

                        if ($newHasDescription && !$existingHasDescription) {
                            // Le nouveau a une description, remplacer l'ancien
                            $deduplicated['dishes'][$existingIndex] = $dish;
                        }
                        // Sinon, garder l'existant (qui a déjà une description ou qui était le premier)
                    }
                }
            }
        }

        // Dédupliquer les boissons
        if (isset($menu['drinks']) && is_array($menu['drinks'])) {
            $seenDrinks = [];
            $deduplicated['drinks'] = [];

            foreach ($menu['drinks'] as $drink) {
                $drinkName = strtolower(trim($drink['name'] ?? ''));
                if (!empty($drinkName)) {
                    if (!isset($seenDrinks[$drinkName])) {
                        // Première boisson avec ce nom, l'ajouter
                        $seenDrinks[$drinkName] = count($deduplicated['drinks']);
                        $deduplicated['drinks'][] = $drink;
                    } else {
                        // Doublon trouvé, vérifier si on doit remplacer
                        $existingIndex = $seenDrinks[$drinkName];
                        $existingDrink = $deduplicated['drinks'][$existingIndex];

                        // Conserver celui qui a une description
                        $existingHasDescription = !empty($existingDrink['description'] ?? '');
                        $newHasDescription = !empty($drink['description'] ?? '');

                        if ($newHasDescription && !$existingHasDescription) {
                            // Le nouveau a une description, remplacer l'ancien
                            $deduplicated['drinks'][$existingIndex] = $drink;
                        }
                        // Sinon, garder l'existant (qui a déjà une description ou qui était le premier)
                    }
                }
            }
        }

        return $deduplicated;
    }

    /**
     * Extrait les informations d'un document pour le profil Banquier
     */
    public function extractDocument(Request $request)
    {
        // Augmenter les limites de temps d'exécution pour les gros fichiers
        set_time_limit(180); // 3 minutes
        ini_set('max_execution_time', 180);

        $user = $request->user();

        if ($user->role !== 'individual') {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        $request->validate([
            'document' => 'required|file|mimes:pdf,jpeg,png,jpg|max:2048', // Max 2MB
        ]);

        // profile_type : query (?profile_type=developer) ou body FormData (formData.append('profile_type', ...))
        $profileType = trim((string) ($request->input('profile_type') ?? $request->query('profile_type', 'student'))) ?: 'student';

        try {
            $file = $request->file('document');
            $extension = $file->getClientOriginalExtension();
            $fileSize = $file->getSize();
            $extractedText = '';

            // --- PROFILAGE : Vérification de la configuration ---
            $pdftotextPath = config('poppler.bin_path', 'pdftotext');
            $geminiApiUrl = config('gemini.api_url', '');
            $geminiApiKeySet = !empty(config('gemini.api_key'));
            Log::info('[PROFILAGE extractDocument] Configuration', [
                'poppler.bin_path' => $pdftotextPath,
                'gemini.api_url' => $geminiApiUrl,
                'gemini.api_key_set' => $geminiApiKeySet,
            ]);

            Log::info('Tentative d\'extraction de document pour portfolio', [
                'extension' => $extension,
                'size' => $fileSize,
                'mime' => $file->getMimeType(),
                'user_id' => $user->id,
                'profile_type' => $profileType
            ]);

            // --- Workflow "Smart Parse" : Text-First pour les PDF ---
            $extractedText = '';
            $isFromPdfText = false;
            $tStartTotal = microtime(true);
            $tPdfText = 0.0;
            $tVision = 0.0;
            $tGemini = 0.0;

            if ($file->getMimeType() === 'application/pdf') {
                // Étape A : Extraction pdftotext "Fichier vers Fichier" (Windows + Linux)
                $tStartPdfText = microtime(true);
                $buffer = file_get_contents($file->getRealPath());

                $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
                $binPath = config('poppler.bin_path');
                if (empty($binPath)) {
                    $binPath = $isWindows
                        ? 'C:\\poppler\\Library\\bin\\pdftotext.exe'
                        : '/usr/bin/pdftotext';
                }

                $tempDir = sys_get_temp_dir();
                $id = uniqid('cv_');
                $tempPdf = $tempDir . DIRECTORY_SEPARATOR . $id . '.pdf';
                $tempTxt = $tempDir . DIRECTORY_SEPARATOR . $id . '.txt';

                file_put_contents($tempPdf, $buffer);

                $cmd = sprintf('"%s" -layout -enc UTF-8 "%s" "%s"', $binPath, $tempPdf, $tempTxt);
                $output = [];
                $returnVar = 0;
                exec($cmd . ' 2>&1', $output, $returnVar);

                if ($returnVar === 0 && file_exists($tempTxt)) {
                    $extractedText = trim(file_get_contents($tempTxt));
                } else {
                    $extractedText = '';
                }

                @unlink($tempPdf);
                @unlink($tempTxt);

                $tPdfText = round(microtime(true) - $tStartPdfText, 3);

                // --- PROFILAGE : Inspection du texte extrait par pdftotext ---
                $textLen = strlen($extractedText);
                Log::info('[PROFILAGE extractDocument] Après pdftotext', [
                    'strlen_texte' => $textLen,
                    'duree_secondes' => $tPdfText,
                ]);

                if ($textLen === 0 || $textLen < 50) {
                    Log::warning('[PROFILAGE extractDocument] ÉCHEC PDFTOTEXT DETECTÉ -> BASCULE VISION (texte vide ou < 50 caractères)', [
                        'strlen' => $textLen,
                    ]);

                    // PDF scanné ou illisible : bascule sur Gemini Vision (avec retry sur timeout)
                    $tStartVision = microtime(true);
                    $extractedText = '';
                    $visionAttempts = 0;
                    $maxVisionAttempts = 2;
                    while ($visionAttempts < $maxVisionAttempts) {
                        try {
                            $extractedText = $this->extractTextFromPdfWithGemini($file);
                            break;
                        } catch (\Exception $visionEx) {
                            $visionAttempts++;
                            if ($this->isTimeoutException($visionEx) && $visionAttempts < $maxVisionAttempts) {
                                Log::warning('[PROFILAGE extractDocument] Timeout Gemini Vision, retry ' . $visionAttempts . '/' . $maxVisionAttempts);
                                continue;
                            }
                            if ($this->isTimeoutException($visionEx)) {
                                Log::error('Exception lors de l\'extraction du document (timeout après retry): ' . $visionEx->getMessage());
                                return response()->json([
                                    'message' => 'L\'extraction a pris trop de temps. Veuillez réessayer dans quelques instants ou utiliser un fichier plus léger.',
                                ], 503);
                            }
                            throw $visionEx;
                        }
                    }
                    $tVision = round(microtime(true) - $tStartVision, 3);
                    Log::info('PDF scanné ou vide, extraction via Gemini Vision', [
                        'length' => strlen($extractedText),
                        'duree_vision_secondes' => $tVision,
                    ]);
                } else {
                    $isFromPdfText = true;
                    Log::info('PDF natif détecté (pdftotext), utilisation du texte pour Gemini', [
                        'length' => $textLen,
                        'duree_pdftotext_secondes' => $tPdfText,
                    ]);
                }
            } else {
                // Image (JPG, PNG) : extraction via Gemini Vision
                $tStartVision = microtime(true);
                $extractedText = $this->extractTextFromImageWithGemini($file);
                $tVision = round(microtime(true) - $tStartVision, 3);
            }

            Log::info('Texte extrait pour portfolio', [
                'length' => strlen($extractedText),
                'source' => $isFromPdfText ? 'pdftotext' : 'gemini_vision',
            ]);

            if (empty($extractedText)) {
                return response()->json([
                    'message' => 'Impossible d\'extraire le texte du fichier. L\'API Gemini est peut-être temporairement indisponible. Veuillez réessayer dans quelques instants.'
                ], 503);
            }

            // Étape B : Appel IA (Gemini) pour structurer le texte
            $tStartGemini = microtime(true);
            $geminiService = new GeminiService();
            $extractedData = $geminiService->extractPortfolioInfoFromText($extractedText, $profileType);
            $tGemini = round(microtime(true) - $tStartGemini, 3);
            $tTotal = round(microtime(true) - $tStartTotal, 3);

            // --- PROFILAGE : Synthèse des temps ---
            Log::info('[PROFILAGE extractDocument] Chronométrage', [
                'duree_pdftotext_s' => $tPdfText,
                'duree_vision_s' => $tVision,
                'duree_gemini_structuration_s' => $tGemini,
                'duree_totale_s' => $tTotal,
                'source_texte' => $isFromPdfText ? 'pdftotext' : 'gemini_vision',
            ]);

            if (!$extractedData || !is_array($extractedData)) {
                Log::error('Données extraites invalides ou vides', [
                    'extracted_data' => $extractedData,
                    'profile_type' => $profileType
                ]);
                return response()->json([
                    'message' => 'Erreur lors de l\'analyse du texte. Le document n\'a pas pu être analysé correctement. Veuillez réessayer ou vérifier que le document contient des informations exploitables.'
                ], 500);
            }

            return response()->json([
                'message' => 'Document analysé avec succès ! Les informations ont été extraites.',
                'extractedData' => $extractedData,
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'extraction du document: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $user->id,
                'profile_type' => $profileType ?? 'unknown'
            ]);
            $userMessage = 'Erreur lors du traitement du document.';
            if ($this->isTimeoutException($e)) {
                $userMessage = 'L\'extraction a pris trop de temps. Veuillez réessayer dans quelques instants ou utiliser un fichier plus léger.';
            } elseif (!empty($e->getMessage())) {
                $userMessage = 'Erreur lors du traitement du document: ' . $e->getMessage();
            }
            return response()->json([
                'message' => $userMessage,
                'error' => config('app.debug') ? $e->getMessage() : 'Erreur lors du traitement du document.',
            ], $this->isTimeoutException($e) ? 503 : 500);
        }
    }

    /**
     * Extrait le texte d'une image en utilisant Gemini Vision API
     */
    private function extractTextFromImageWithGemini($file)
    {
        try {
            $imageData = base64_encode(file_get_contents($file->getRealPath()));
            $mimeType = $file->getMimeType();

            $geminiService = new GeminiService();
            return $geminiService->extractTextFromImage($imageData, $mimeType);
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'extraction du texte de l\'image: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Extrait le texte d'un PDF en utilisant Gemini Vision API
     */
    /**
     * Indique si l'exception correspond à un timeout (cURL 28 / 10004).
     */
    private function isTimeoutException(\Exception $e): bool
    {
        $msg = $e->getMessage();
        return (stripos($msg, 'timeout') !== false || strpos($msg, '10004') !== false);
    }

    /**
     * Extrait le texte d'un PDF en utilisant Gemini Vision API.
     * En cas de timeout, l'exception est propagée pour permettre un retry par l'appelant.
     */
    private function extractTextFromPdfWithGemini($file)
    {
        try {
            $pdfData = base64_encode(file_get_contents($file->getRealPath()));

            $geminiService = new GeminiService();
            return $geminiService->extractTextFromPdf($pdfData);
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'extraction du texte du PDF: ' . $e->getMessage());
            if ($this->isTimeoutException($e)) {
                throw $e;
            }
            return '';
        }
    }
}

