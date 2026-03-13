<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\MarketplaceOffer;
use App\Models\MarketplaceMatchScore;
use App\Services\PerplexityService;
use App\Services\GeminiService;
use App\Models\CompanyPage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Notifications\MarketplaceMatchNotification;

class ProcessMarketplaceMatching implements ShouldQueue
{
    use Queueable;

    protected $userId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $userId)
    {
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $user = User::find($this->userId);
        
        if (!$user) {
            Log::warning('ProcessMarketplaceMatching: Utilisateur non trouvé', ['user_id' => $this->userId]);
            return;
        }

        Log::info('ProcessMarketplaceMatching: Début du traitement', ['user_id' => $this->userId]);

        // 1. Analyser les besoins de l'utilisateur
        $userNeeds = $this->analyzeUserNeeds($user);
        
        if (empty($userNeeds['keywords'])) {
            Log::info('ProcessMarketplaceMatching: Aucun besoin identifié', ['user_id' => $this->userId]);
            return;
        }

        // 2. Récupérer toutes les offres actives
        $offers = MarketplaceOffer::where('is_active', true)
            ->where('user_id', '!=', $user->id) // Exclure les offres de l'utilisateur lui-même
            ->with(['seller', 'reviews'])
            ->get();

        Log::info('ProcessMarketplaceMatching: Offres à traiter', [
            'user_id' => $this->userId,
            'offers_count' => $offers->count()
        ]);

        // 3. Calculer le score de matching pour chaque offre
        foreach ($offers as $offer) {
            $matchScore = $this->calculateMatchScore($user, $offer, $userNeeds);
            
            // Sauvegarder ou mettre à jour le score
            MarketplaceMatchScore::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'offer_id' => $offer->id,
                ],
                [
                    'match_score' => $matchScore['score'],
                    'match_details' => $matchScore['details'],
                    'last_calculated_at' => now(),
                ]
            );

            // 4. Envoyer une notification si le score >= 10 et que l'utilisateur n'a pas encore été notifié
            if ($matchScore['score'] >= 10) {
                $matchScoreRecord = MarketplaceMatchScore::where('user_id', $user->id)
                    ->where('offer_id', $offer->id)
                    ->first();

                if ($matchScoreRecord && !$matchScoreRecord->notified) {
                    // Envoyer la notification
                    try {
                        $user->notify(new MarketplaceMatchNotification($offer, $matchScore['score']));
                        
                        // Marquer comme notifié
                        $matchScoreRecord->update([
                            'notified' => true,
                            'notified_at' => now(),
                        ]);

                        Log::info('ProcessMarketplaceMatching: Notification envoyée', [
                            'user_id' => $user->id,
                            'offer_id' => $offer->id,
                            'score' => $matchScore['score']
                        ]);
                    } catch (\Exception $e) {
                        Log::error('ProcessMarketplaceMatching: Erreur lors de l\'envoi de la notification', [
                            'user_id' => $user->id,
                            'offer_id' => $offer->id,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }
        }

        // 5. Invalider le cache des besoins utilisateur pour forcer le recalcul
        Cache::forget("user_needs_{$user->id}");

        Log::info('ProcessMarketplaceMatching: Traitement terminé', ['user_id' => $this->userId]);
    }

    /**
     * Analyse les besoins de l'utilisateur en utilisant Perplexity AI et Gemini
     */
    private function analyzeUserNeeds(User $user): array
    {
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
                Log::warning('ProcessMarketplaceMatching: Erreur lors de l\'exploration du site web', [
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

        return $needs;
    }

    /**
     * Calcule le score de matching entre un utilisateur et une offre
     */
    private function calculateMatchScore(User $user, MarketplaceOffer $offer, array $userNeeds): array
    {
        $score = 0;
        $matchedKeywords = [];
        $details = [];

        // 1. Matching par mots-clés (60 points max)
        $offerText = strtolower($offer->title . ' ' . $offer->description);
        $keywordMatches = 0;
        
        foreach ($userNeeds['keywords'] as $keyword) {
            if (stripos($offerText, $keyword) !== false) {
                $keywordMatches++;
                $matchedKeywords[] = $keyword;
            }
        }

        if (count($userNeeds['keywords']) > 0) {
            $keywordScore = min(60, ($keywordMatches / count($userNeeds['keywords'])) * 60);
            $score += $keywordScore;
            $details['keyword_matches'] = $matchedKeywords;
            $details['keyword_score'] = round($keywordScore, 2);
        }

        // 2. Profil complet du vendeur (20 points max)
        $seller = $offer->seller;
        $profileScore = 0;
        
        if ($seller) {
            $hasPhoto = !empty($seller->avatar_url) ? 5 : 0;
            $hasTitle = !empty($seller->title) ? 5 : 0;
            
            $socialCount = 0;
            if (!empty($seller->whatsapp_url)) $socialCount++;
            if (!empty($seller->linkedin_url)) $socialCount++;
            if (!empty($seller->facebook_url)) $socialCount++;
            if (!empty($seller->twitter_url)) $socialCount++;
            if (!empty($seller->youtube_url)) $socialCount++;
            if (!empty($seller->tiktok_url)) $socialCount++;
            if (!empty($seller->threads_url)) $socialCount++;
            
            $hasThreeSocials = $socialCount >= 3 ? 10 : ($socialCount * 3.33);
            $profileScore = $hasPhoto + $hasTitle + $hasThreeSocials;
        }
        
        $score += $profileScore;
        $details['profile_score'] = round($profileScore, 2);

        // 3. Nombre d'avis positifs (20 points max)
        $reviewsCount = $offer->reviews()->count();
        $averageRating = $offer->reviews()->avg('rating') ?? 0;
        
        $reviewsScore = 0;
        if ($reviewsCount > 0) {
            // Score basé sur le nombre d'avis (max 10 points)
            $countScore = min(10, ($reviewsCount / 10) * 10);
            // Score basé sur la note moyenne (max 10 points)
            $ratingScore = ($averageRating / 5) * 10;
            $reviewsScore = ($countScore + $ratingScore) / 2;
        }
        
        $score += $reviewsScore;
        $details['reviews_score'] = round($reviewsScore, 2);
        $details['reviews_count'] = $reviewsCount;
        $details['average_rating'] = round($averageRating, 2);

        // Arrondir le score final
        $finalScore = round($score, 2);
        $details['total_score'] = $finalScore;

        return [
            'score' => $finalScore,
            'details' => $details
        ];
    }
}
