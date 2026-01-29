<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ImageSearchController extends Controller
{
    /**
     * Recherche une image sur Unsplash basée sur un terme de recherche
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function searchImage(Request $request)
    {
        $request->validate([
            'query' => 'required|string|max:255',
            'type' => 'nullable|string|in:dish,drink,food', // Type pour affiner la recherche
        ]);

        $query = $request->input('query');
        $type = $request->input('type', 'food');
        
        // Construire la requête de recherche optimisée
        // Pour les plats, on ajoute "food" ou "dish" à la recherche
        $searchQuery = $query;
        if ($type === 'dish') {
            $searchQuery = "food {$query} dish";
        } elseif ($type === 'drink') {
            $searchQuery = "drink {$query} beverage";
        } else {
            $searchQuery = "food {$query}";
        }

        try {
            // Utiliser l'API Unsplash (gratuite, 50 requêtes/heure)
            // Note: Pour la production, vous devriez ajouter UNSPLASH_ACCESS_KEY dans .env
            $accessKey = env('UNSPLASH_ACCESS_KEY', 'YOUR_UNSPLASH_ACCESS_KEY');
            
            // Si pas de clé API, utiliser une alternative gratuite (Pexels)
            if ($accessKey === 'YOUR_UNSPLASH_ACCESS_KEY') {
                return $this->searchPexels($searchQuery);
            }

            $response = Http::timeout(10)->get('https://api.unsplash.com/search/photos', [
                'query' => $searchQuery,
                'per_page' => 1,
                'orientation' => 'landscape',
                'client_id' => $accessKey,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                if (isset($data['results']) && count($data['results']) > 0) {
                    $photo = $data['results'][0];
                    $imageUrl = $photo['urls']['regular'] ?? $photo['urls']['small'] ?? null;
                    
                    if ($imageUrl) {
                        return response()->json([
                            'success' => true,
                            'image_url' => $imageUrl,
                            'source' => 'unsplash',
                            'photographer' => $photo['user']['name'] ?? 'Unknown',
                        ]);
                    }
                }
            }

            // Si Unsplash échoue, essayer Pexels en fallback
            return $this->searchPexels($searchQuery);
            
        } catch (\Exception $e) {
            Log::error('Erreur lors de la recherche d\'image', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            // Essayer Pexels en fallback
            return $this->searchPexels($searchQuery);
        }
    }

    /**
     * Recherche une image sur Pexels (alternative gratuite)
     * 
     * @param string $query
     * @return \Illuminate\Http\JsonResponse
     */
    private function searchPexels($query)
    {
        try {
            $apiKey = env('PEXELS_API_KEY', 'YOUR_PEXELS_API_KEY');
            
            // Si pas de clé API Pexels, utiliser une image placeholder
            if ($apiKey === 'YOUR_PEXELS_API_KEY') {
                return $this->getPlaceholderImage($query);
            }

            $response = Http::timeout(10)->withHeaders([
                'Authorization' => $apiKey,
            ])->get('https://api.pexels.com/v1/search', [
                'query' => $query,
                'per_page' => 1,
                'orientation' => 'landscape',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                if (isset($data['photos']) && count($data['photos']) > 0) {
                    $photo = $data['photos'][0];
                    $imageUrl = $photo['src']['large'] ?? $photo['src']['medium'] ?? null;
                    
                    if ($imageUrl) {
                        return response()->json([
                            'success' => true,
                            'image_url' => $imageUrl,
                            'source' => 'pexels',
                            'photographer' => $photo['photographer'] ?? 'Unknown',
                        ]);
                    }
                }
            }

            // Si Pexels échoue aussi, retourner une image placeholder
            return $this->getPlaceholderImage($query);
            
        } catch (\Exception $e) {
            Log::error('Erreur lors de la recherche d\'image Pexels', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return $this->getPlaceholderImage($query);
        }
    }

    /**
     * Retourne une image placeholder basée sur le terme de recherche
     * Utilise un service de placeholder qui génère des images basées sur le texte
     * 
     * @param string $query
     * @return \Illuminate\Http\JsonResponse
     */
    private function getPlaceholderImage($query)
    {
        // Utiliser un service de placeholder comme placeholder.com ou via.placeholder.com
        // Ou utiliser une image générique de nourriture
        $encodedQuery = urlencode($query);
        
        // Option 1: Utiliser un service de placeholder avec texte
        // Option 2: Utiliser une image générique de nourriture depuis un CDN
        $placeholderUrl = "https://source.unsplash.com/800x600/?food,{$encodedQuery}";
        
        // Alternative: Utiliser un service de placeholder générique
        // $placeholderUrl = "https://via.placeholder.com/800x600/4B5563/FFFFFF?text=" . $encodedQuery;
        
        return response()->json([
            'success' => true,
            'image_url' => $placeholderUrl,
            'source' => 'placeholder',
            'photographer' => null,
        ]);
    }
}
