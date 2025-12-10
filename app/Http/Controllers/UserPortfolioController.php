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
            ]
        );

        // Toujours mettre à jour le nom, headline et photo avec les valeurs les plus récentes de la section "Ma Carte"
        $portfolio->name = $user->name;
        $portfolio->hero_headline = $user->title;

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
            'profile_type' => 'nullable|in:student,teacher,freelance',
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
            'timeline' => 'nullable|array',
            'timeline.*.title' => 'required_with:timeline|string',
            'timeline.*.organization' => 'nullable|string',
            'timeline.*.dates' => 'nullable|string',
            'timeline.*.details' => 'nullable|string',
            'timeline.*.icon' => 'nullable|string',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'linkedin_url' => 'nullable|url',
            'github_url' => 'nullable|url',
            'primary_color' => 'nullable|string|max:7',
            'secondary_color' => 'nullable|string|max:7',
            'skills_title' => 'nullable|string',
            'projects_title' => 'nullable|string',
            'timeline_title' => 'nullable|string',
        ]);

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

        if ((empty($projects) || count($projects) === 0) && (empty($timeline) || count($timeline) === 0)) {
            return response()->json([
                'message' => 'Au moins un projet ou un événement est requis pour générer le contenu.'
            ], 400);
        }

        try {
            // Préparer les données pour Gemini
            $geminiService = new GeminiService();
            
            $companyData = [
                'profile_type' => $profileType,
                'name' => $name,
                'hero_headline' => $heroHeadline,
                'bio' => $bio,
                'skills' => $skills,
                'projects' => $projects,
                'timeline' => $timeline,
                'primary_color' => $primaryColor,
            ];

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
            $dataToSave = array_merge($companyData, $generatedContent);
            
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
        ]);
    }
}

