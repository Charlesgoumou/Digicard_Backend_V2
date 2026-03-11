<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceOffer;
use App\Models\MarketplaceReview;
use App\Models\MarketplaceFavorite;
use App\Models\MarketplacePurchase;
use App\Models\MarketplaceMessage;
use App\Models\MarketplaceOfferImage;
use App\Models\CompanyPage;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\PerplexityService;
use App\Services\ImageCompressionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class MarketplaceController extends Controller
{
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
        
        $query = MarketplaceOffer::where('is_active', true)
            ->with($withRelations)
            ->withCount('reviews');
        
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
            // Mes ventes : offres créées par l'utilisateur
            $query->where('user_id', $user->id);
        } elseif ($filter === 'purchases' && $user) {
            // Mes achats : offres achetées par l'utilisateur
            $purchasedOfferIds = MarketplacePurchase::where('buyer_id', $user->id)
                ->where('status', '=', 'completed')
                ->pluck('offer_id');
            $query->whereIn('id', $purchasedOfferIds);
        } elseif ($filter === 'favorites' && $user) {
            // Mes favoris : offres ajoutées aux favoris par l'utilisateur
            $favoriteOfferIds = MarketplaceFavorite::where('user_id', $user->id)
                ->pluck('offer_id');
            $query->whereIn('id', $favoriteOfferIds);
        } elseif ($filter === 'all' && $user) {
            // Système de recommandation intelligent pour l'onglet "all"
            $userNeeds = $this->determineUserNeeds($user);
            if (!empty($userNeeds['keywords'])) {
                // Filtrer les offres selon les mots-clés identifiés
                $query->where(function($q) use ($userNeeds) {
                    foreach ($userNeeds['keywords'] as $keyword) {
                        $q->orWhere('title', 'like', "%{$keyword}%")
                          ->orWhere('description', 'like', "%{$keyword}%");
                    }
                });
            }
        }
        
        $offers = $query->get()
            ->map(function ($offer) use ($user) {
                // Calculer la note moyenne
                $averageRating = $offer->reviews()->avg('rating');
                
                // Vérifier si l'offre est dans les favoris de l'utilisateur
                $isFavorite = $user ? MarketplaceFavorite::where('offer_id', $offer->id)
                    ->where('user_id', $user->id)
                    ->exists() : false;
                
                // Vérifier si l'utilisateur est le vendeur
                $isSeller = $user && $offer->user_id === $user->id;
                
                // Vérifier si l'utilisateur a acheté cette offre
                $isPurchased = $user ? MarketplacePurchase::where('offer_id', $offer->id)
                    ->where('buyer_id', $user->id)
                    ->where('status', '=', 'completed')
                    ->exists() : false;
                
                // Vérifier si le profil du vendeur est complet
                $seller = $offer->seller;
                $isProfileComplete = $this->isProfileComplete($seller);
                
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
                
                return [
                    'id' => $offer->id,
                    'title' => $offer->title,
                    'description' => $offer->description,
                    'type' => $offer->type,
                    'price' => $offer->price,
                    'currency' => $offer->currency,
                    'image_url' => $offer->image_url, // Image principale (pour compatibilité)
                    'images' => $images, // Toutes les images
                    'seller_id' => $offer->user_id,
                    'seller_name' => $offer->seller->name ?? 'Anonyme',
                    'seller_title' => $offer->seller->title ?? null,
                    'seller_avatar' => $offer->seller->avatar_url ?? null,
                    'average_rating' => $averageRating ? round($averageRating, 1) : null,
                    'reviews_count' => $offer->reviews_count,
                    'is_favorite' => $isFavorite,
                    'is_seller' => $isSeller,
                    'is_purchased' => $isPurchased,
                    'is_profile_complete' => $isProfileComplete,
                    'created_at' => $offer->created_at,
                ];
            });
        
        // Tri des résultats : priorité aux offres avec le plus d'avis et profil complet
        $offers = $offers->sortByDesc(function ($offer) {
            $score = 0;
            // Priorité aux profils complets
            if ($offer['is_profile_complete']) {
                $score += 1000;
            }
            // Priorité aux offres avec le plus d'avis
            $score += ($offer['reviews_count'] ?? 0) * 10;
            // Priorité aux offres avec une meilleure note
            $score += ($offer['average_rating'] ?? 0) * 5;
            return $score;
        })->values();
        
        return response()->json($offers);
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
        
        return response()->json([
            'id' => $offer->id,
            'title' => $offer->title,
            'description' => $offer->description,
            'type' => $offer->type,
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
            'created_at' => $offer->created_at,
        ]);
    }

    /**
     * Créer une nouvelle offre
     */
    public function store(Request $request)
    {
        $user = $request->user();
        
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'type' => 'required|in:offer,product,service',
            'price' => 'required|numeric|min:0',
            'currency' => 'nullable|string|max:3',
            'images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120', // 5MB max par image
        ]);
        
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
        
        $offer = MarketplaceOffer::create([
            'user_id' => $user->id,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'type' => $validated['type'],
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
            'price' => 'sometimes|numeric|min:0',
            'currency' => 'nullable|string|max:3',
            'is_active' => 'sometimes|boolean',
            'images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);
        
        // Mettre à jour les champs de base
        if (isset($validated['title'])) $offer->title = $validated['title'];
        if (isset($validated['description'])) $offer->description = $validated['description'];
        if (isset($validated['type'])) $offer->type = $validated['type'];
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
        
        // Vérifier que l'utilisateur est le propriétaire de l'offre
        if ($offer->user_id !== $user->id) {
            return response()->json([
                'message' => 'Vous n\'êtes pas autorisé à voir les messages de cette offre.'
            ], 403);
        }
        
        try {
            $messages = MarketplaceMessage::where('offer_id', $offer->id)
                ->with(['sender', 'receiver'])
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(function ($message) {
                    return [
                        'id' => $message->id,
                        'sender_id' => $message->sender_id,
                        'sender_name' => $message->sender->name ?? 'Anonyme',
                        'receiver_id' => $message->receiver_id,
                        'receiver_name' => $message->receiver->name ?? 'Anonyme',
                        'message' => $message->message,
                        'is_read' => $message->is_read,
                        'read_at' => $message->read_at,
                        'created_at' => $message->created_at,
                    ];
                });
            
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
     */
    public function getUserMessages(Request $request)
    {
        $user = $request->user();
        
        try {
            $messages = MarketplaceMessage::where(function($query) use ($user) {
                    $query->where('sender_id', $user->id)
                          ->orWhere('receiver_id', $user->id);
                })
                ->with(['offer', 'sender', 'receiver'])
                ->orderBy('created_at', 'desc')
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
            
            return response()->json($messages, 200);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des messages utilisateur: ' . $e->getMessage());
            return response()->json([
                'message' => 'Erreur lors de la récupération des messages.',
                'error' => $e->getMessage()
            ], 500);
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
