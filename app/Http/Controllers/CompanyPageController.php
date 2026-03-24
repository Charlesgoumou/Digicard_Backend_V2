<?php

namespace App\Http\Controllers;

use App\Models\AppointmentSetting;
use App\Models\CompanyPage;
use App\Models\Order;
use App\Models\User;
use App\Models\MarketplaceUserNeed;
use App\Jobs\ProcessMarketplaceMatching;
use App\Services\GeminiService;
use App\Services\ImageCompressionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class CompanyPageController extends Controller
{
    /**
     * Récupère ou crée la page entreprise de l'utilisateur pour une commande spécifique
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Seuls les business_admin peuvent avoir une page entreprise
        if ($user->role !== 'business_admin') {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        // Récupérer order_id depuis la requête
        $orderId = $request->input('order_id');

        if ($orderId) {
            // Vérifier que la commande appartient bien à l'utilisateur
            // Un business_admin peut configurer "Nos Services" pour toutes ses commandes (business ou personal)
            $order = \App\Models\Order::where('id', $orderId)
                ->where('user_id', $user->id)
                ->first();

            if (!$order) {
                return response()->json(['message' => 'Commande non trouvée ou non autorisée.'], 404);
            }

            // Récupérer ou créer la page entreprise pour cette commande
            $companyPage = CompanyPage::firstOrCreate(
                ['order_id' => $orderId],
                [
                    'user_id' => $user->id,
                    'company_name' => $user->company_name,
                    'services' => [],
                    'pillars' => [],
                ]
            );
        } else {
            // Compatibilité : si pas d'order_id, utiliser user_id (ancien comportement)
            $companyPage = CompanyPage::firstOrCreate(
                ['user_id' => $user->id, 'order_id' => null],
                [
                    'company_name' => $user->company_name,
                    'services' => [],
                    'pillars' => [],
                ]
            );
        }

        // Formater les données pour le frontend
        $data = $companyPage->toArray();
        
        // Formater l'URL du logo pour qu'elle soit complète
        if (!empty($data['logo_url'])) {
            $data['logo_url'] = url($data['logo_url']);
        }
        
        // S'assurer que les tableaux JSON sont bien des tableaux (pour éviter les erreurs)
        if (isset($data['services']) && !is_array($data['services'])) {
            $data['services'] = json_decode($data['services'], true) ?? [];
        }
        if (isset($data['chart_labels']) && !is_array($data['chart_labels'])) {
            $data['chart_labels'] = json_decode($data['chart_labels'], true) ?? [];
        }
        if (isset($data['chart_data']) && !is_array($data['chart_data'])) {
            $data['chart_data'] = json_decode($data['chart_data'], true) ?? [];
        }
        if (isset($data['pillars']) && !is_array($data['pillars'])) {
            $data['pillars'] = json_decode($data['pillars'], true) ?? [];
        }
        if (isset($data['process_order_steps']) && !is_array($data['process_order_steps'])) {
            $data['process_order_steps'] = json_decode($data['process_order_steps'], true) ?? [];
        }
        if (isset($data['process_logistics_steps']) && !is_array($data['process_logistics_steps'])) {
            $data['process_logistics_steps'] = json_decode($data['process_logistics_steps'], true) ?? [];
        }

        return response()->json($data);
    }

    /**
     * Met à jour la page entreprise pour une commande spécifique
     */
    public function update(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'business_admin') {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        // Snapshot pour détecter les changements importants pour le matching
        $beforeCompanyWebsite = null;
        $beforeServicesHash = null;

        $validated = $request->validate([
            'order_id' => 'nullable|exists:orders,id',
            'company_name' => 'nullable|string|max:255',
            'company_name_short' => 'nullable|string|max:50',
            'company_website_url' => 'nullable|url|max:500',
            'website_featured_in_services_button' => 'nullable|boolean',
            'primary_color' => 'nullable|string|max:7',
            'secondary_color' => 'nullable|string|max:7',
            'services' => 'nullable|array',
            'services.*.title' => 'nullable|string', // Permettre les titres vides
            'services.*.description' => 'nullable|string', // Ajouter description (peut être utilisé au lieu de details)
            'services.*.details' => 'nullable|string',
            'services.*.icon' => 'nullable|string',
            'hero_headline' => 'nullable|string',
            'hero_subheadline' => 'nullable|string',
            'hero_description' => 'nullable|string',
            'chart_labels' => 'nullable|array',
            'chart_data' => 'nullable|array',
            'chart_colors' => 'nullable|array',
            'chart_title' => 'nullable|string',
            'chart_description' => 'nullable|string',
            'pillars' => 'nullable|array',
            'pillars_title' => 'nullable|string',
            'engagement_description' => 'nullable|string',
            'products_button_text' => 'nullable|string',
            'products_button_icon' => 'nullable|string',
            'products_modal_title' => 'nullable|string',
            'contact_email' => 'nullable|email',
            'is_published' => 'nullable|boolean',
            'processes_title' => 'nullable|string',
            'process_order_title' => 'nullable|string',
            'process_order_description' => 'nullable|string',
            'process_order_steps' => 'nullable|array',
            'process_logistics_title' => 'nullable|string',
            'process_logistics_description' => 'nullable|string',
            'process_logistics_steps' => 'nullable|array',
        ]);

        $orderId = $validated['order_id'] ?? null;
        unset($validated['order_id']);

        // Trouver ou créer la page entreprise
        if ($orderId) {
            // Vérifier que la commande appartient bien à l'utilisateur
            // Un business_admin peut configurer "Nos Services" pour toutes ses commandes (business ou personal)
            $order = \App\Models\Order::where('id', $orderId)
                ->where('user_id', $user->id)
                ->first();

            if (!$order) {
                return response()->json(['message' => 'Commande non trouvée ou non autorisée.'], 404);
            }

            $companyPage = CompanyPage::firstOrCreate(
                ['order_id' => $orderId],
                ['user_id' => $user->id]
            );
        } else {
            // Compatibilité : si pas d'order_id, utiliser user_id (ancien comportement)
            $companyPage = CompanyPage::firstOrCreate(
                ['user_id' => $user->id, 'order_id' => null]
            );
        }

        $beforeCompanyWebsite = $companyPage->company_website_url;
        $beforeServicesHash = md5(json_encode($companyPage->services ?? []));

        // Cas spécial : Si le site web est renseigné ET la case est cochée pour le mettre en avant,
        // on permet la sauvegarde même si les autres champs sont vides
        $websiteFeatured = isset($validated['website_featured_in_services_button']) && $validated['website_featured_in_services_button'] === true;
        $hasWebsite = !empty($validated['company_website_url']) && trim($validated['company_website_url']) !== '';
        
        // Filtrer les valeurs pour ne mettre à jour que les champs qui ont des valeurs significatives
        // Cela préserve les données existantes pour les champs vides
        $updateData = [];
        foreach ($validated as $key => $value) {
            // Mettre à jour si :
            // - La valeur n'est pas null ET n'est pas une chaîne vide (sauf pour certains champs)
            // - C'est un boolean (même false est valide)
            // - C'est un tableau (même vide est valide, car cela peut être intentionnel)
            if ($value !== null) {
                if (is_bool($value) || is_array($value)) {
                    $updateData[$key] = $value;
                } elseif (is_string($value) && trim($value) !== '') {
                    $updateData[$key] = $value;
                } elseif (!is_string($value)) {
                    // Pour les autres types (int, float, etc.)
                    $updateData[$key] = $value;
                }
            }
        }

        // Si le site web est mis en avant, forcer l'inclusion des champs nécessaires même si vides
        // Cela permet la publication minimale avec juste le site web
        if ($websiteFeatured && $hasWebsite) {
            // S'assurer que website_featured_in_services_button est bien dans updateData
            $updateData['website_featured_in_services_button'] = true;
            $updateData['company_website_url'] = trim($validated['company_website_url']);
            // S'assurer que is_published peut être défini
            if (isset($validated['is_published'])) {
                $updateData['is_published'] = $validated['is_published'];
            }
        }

        // Mettre à jour la page avec les données filtrées
        // Si on a des données à mettre à jour OU si c'est le cas spécial du site web mis en avant
        if (!empty($updateData) || ($websiteFeatured && $hasWebsite)) {
            // Si c'est seulement le cas spécial, s'assurer qu'on a au moins les données minimales
            if (empty($updateData) && $websiteFeatured && $hasWebsite) {
                $updateData = [
                    'website_featured_in_services_button' => true,
                    'company_website_url' => trim($validated['company_website_url']),
                    'is_published' => $validated['is_published'] ?? true,
                ];
            }
            $companyPage->update($updateData);
        }

        // Recharger la page depuis la base de données pour avoir toutes les données à jour
        $companyPage->refresh();

        // Déclencher matching si site entreprise/services ont changé (throttle léger)
        $afterCompanyWebsite = $companyPage->company_website_url;
        $afterServicesHash = md5(json_encode($companyPage->services ?? []));
        if ($beforeCompanyWebsite !== $afterCompanyWebsite || $beforeServicesHash !== $afterServicesHash) {
            $throttleKey = "marketplace_matching_trigger_{$user->id}";
            if (!Cache::has($throttleKey)) {
                Cache::put($throttleKey, true, now()->addSeconds(45));
                if (config('queue.default') === 'sync') {
                    (new ProcessMarketplaceMatching($user->id))->handle();
                } else {
                    ProcessMarketplaceMatching::dispatch($user->id);
                }
            }
        }
        
        // Formater les données pour le frontend (comme dans la méthode index)
        $companyPageData = $companyPage->toArray();
        
        // Formater l'URL du logo pour qu'elle soit complète
        if (!empty($companyPageData['logo_url'])) {
            $companyPageData['logo_url'] = url($companyPageData['logo_url']);
        }
        
        // S'assurer que les tableaux JSON sont bien des tableaux
        if (isset($companyPageData['services']) && !is_array($companyPageData['services'])) {
            $companyPageData['services'] = json_decode($companyPageData['services'], true) ?? [];
        }
        if (isset($companyPageData['chart_labels']) && !is_array($companyPageData['chart_labels'])) {
            $companyPageData['chart_labels'] = json_decode($companyPageData['chart_labels'], true) ?? [];
        }
        if (isset($companyPageData['chart_data']) && !is_array($companyPageData['chart_data'])) {
            $companyPageData['chart_data'] = json_decode($companyPageData['chart_data'], true) ?? [];
        }
        if (isset($companyPageData['pillars']) && !is_array($companyPageData['pillars'])) {
            $companyPageData['pillars'] = json_decode($companyPageData['pillars'], true) ?? [];
        }
        if (isset($companyPageData['process_order_steps']) && !is_array($companyPageData['process_order_steps'])) {
            $companyPageData['process_order_steps'] = json_decode($companyPageData['process_order_steps'], true) ?? [];
        }
        if (isset($companyPageData['process_logistics_steps']) && !is_array($companyPageData['process_logistics_steps'])) {
            $companyPageData['process_logistics_steps'] = json_decode($companyPageData['process_logistics_steps'], true) ?? [];
        }

        return response()->json([
            'message' => 'Page entreprise mise à jour avec succès.',
            'companyPage' => $companyPageData,
        ]);
    }

    /**
     * Upload du logo et extraction des couleurs
     */
    public function uploadLogo(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'business_admin') {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg,svg|max:2048',
            'order_id' => 'nullable|exists:orders,id',
        ]);

        try {
            $orderId = $request->input('order_id');
            
            if ($orderId) {
                // Vérifier que la commande appartient bien à l'utilisateur
                // Un business_admin peut configurer "Nos Services" pour toutes ses commandes (business ou personal)
                $order = \App\Models\Order::where('id', $orderId)
                    ->where('user_id', $user->id)
                    ->first();

                if (!$order) {
                    return response()->json(['message' => 'Commande non trouvée ou non autorisée.'], 404);
                }

                $companyPage = CompanyPage::firstOrCreate(
                    ['order_id' => $orderId],
                    ['user_id' => $user->id]
                );
            } else {
                // Compatibilité : si pas d'order_id, utiliser user_id
                $companyPage = CompanyPage::firstOrCreate(
                    ['user_id' => $user->id, 'order_id' => null]
                );
            }

            // Supprimer l'ancien logo si il existe
            if ($companyPage->logo_url) {
                // ✅ CORRECTION : Gérer les deux formats (/storage/ et /api/storage/)
                $oldPath = preg_replace('#^/api/storage/#', '', $companyPage->logo_url);
                $oldPath = preg_replace('#^/storage/#', '', $oldPath);
                $oldPath = preg_replace('#^https?://[^/]+/(api/)?storage/#', '', $oldPath);
                if (Storage::disk('public')->exists($oldPath)) {
                    Storage::disk('public')->delete($oldPath);
                }
            }

            // Compresser et stocker le nouveau logo
            $compressionService = new ImageCompressionService();
            $result = $compressionService->compressImage($request->file('logo'), 'company_logos');
            // ✅ CORRECTION : Utiliser Storage::url() pour générer l'URL correcte
            // Laravel génère automatiquement l'URL basée sur la configuration (config/filesystems.php)
            // En production avec Nginx, cela génère /storage/company_logos/image.jpg
            $url = Storage::disk('public')->url($result['path']);

            // Extraire les couleurs du logo
            $colors = $this->extractColorsFromImage(storage_path('app/public/' . $result['path']));

            // Mettre à jour la page avec le logo et les couleurs
            $companyPage->update([
                'logo_url' => $url,
                'primary_color' => $colors['primary'] ?? null,
                'secondary_color' => $colors['secondary'] ?? null,
            ]);

            return response()->json([
                'message' => 'Logo uploadé avec succès.',
                'logo_url' => url($url),
                'colors' => $colors,
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'upload du logo: ' . $e->getMessage());
            return response()->json([
                'message' => 'Erreur lors de l\'upload du logo.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Extrait les couleurs dominantes d'une image
     */
    private function extractColorsFromImage($imagePath)
    {
        try {
            $imageInfo = getimagesize($imagePath);
            if (!$imageInfo) {
                return ['primary' => '#000000', 'secondary' => '#ffffff'];
            }

            $mimeType = $imageInfo['mime'];
            $image = null;

            // Charger l'image selon son type
            switch ($mimeType) {
                case 'image/jpeg':
                case 'image/jpg':
                    $image = imagecreatefromjpeg($imagePath);
                    break;
                case 'image/png':
                    $image = imagecreatefrompng($imagePath);
                    break;
                case 'image/gif':
                    $image = imagecreatefromgif($imagePath);
                    break;
                default:
                    return ['primary' => '#000000', 'secondary' => '#ffffff'];
            }

            if (!$image) {
                return ['primary' => '#000000', 'secondary' => '#ffffff'];
            }

            // Redimensionner l'image pour accélérer le traitement
            $width = imagesx($image);
            $height = imagesy($image);
            $newWidth = 100;
            $newHeight = (int)(($height / $width) * $newWidth);
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            // Extraire les couleurs
            $colors = [];
            for ($x = 0; $x < $newWidth; $x++) {
                for ($y = 0; $y < $newHeight; $y++) {
                    $rgb = imagecolorat($resized, $x, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;

                    // Ignorer les couleurs trop claires ou trop foncées
                    $brightness = ($r + $g + $b) / 3;
                    if ($brightness > 30 && $brightness < 225) {
                        $hex = sprintf("#%02x%02x%02x", $r, $g, $b);
                        if (!isset($colors[$hex])) {
                            $colors[$hex] = 0;
                        }
                        $colors[$hex]++;
                    }
                }
            }

            // Trier par fréquence
            arsort($colors);

            // Nettoyer
            imagedestroy($image);
            imagedestroy($resized);

            // Récupérer les 2 couleurs les plus fréquentes
            $topColors = array_slice(array_keys($colors), 0, 2);

            return [
                'primary' => $topColors[0] ?? '#000000',
                'secondary' => $topColors[1] ?? '#ffffff',
            ];
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'extraction des couleurs: ' . $e->getMessage());
            return ['primary' => '#000000', 'secondary' => '#ffffff'];
        }
    }

    /**
     * Affiche la page publique de l'entreprise
     * Si un order_id est fourni en paramètre, affiche la page entreprise de cette commande spécifique
     */
    public function show(Request $request, $username)
    {
        // Trouver l'utilisateur par username
        $user = User::where('username', $username)
            ->where('role', 'business_admin')
            ->first();
        
        if (!$user) {
            Log::warning('Company Page Show - Utilisateur non trouvé ou n\'est pas un business_admin', [
                'username' => $username,
            ]);
            return response()->json([
                'message' => 'Utilisateur non trouvé ou n\'est pas un administrateur d\'entreprise.',
            ], 404);
        }

        // Récupérer l'identifiant de commande depuis la requête si fourni
        // Préférer le short_code (URL-safe), fallback sur l'ancien paramètre order (id ou order_number)
        $code = $request->query('code');
        $orderParam = $request->query('order');
        $orderId = null;
        $order = null;

        // Si un code court est fourni, l'utiliser en priorité
        if ($code) {
            $order = \App\Models\Order::where('short_code', $code)
                ->where('user_id', $user->id)
                ->first();
            if ($order) {
                $orderId = $order->id;
            }
        }

        // Si un order est fourni, chercher la commande par ID ou par order_number
        if (!$orderId && $orderParam) {
            // Essayer d'abord par ID numérique
            if (is_numeric($orderParam)) {
                $order = \App\Models\Order::where('id', (int) $orderParam)
                    ->where('user_id', $user->id)
                    ->first();
            }
            
            // Si pas trouvé par ID, essayer par order_number
            if (!$order) {
                $order = \App\Models\Order::where('order_number', $orderParam)
                    ->where('user_id', $user->id)
                    ->first();
            }
            
            // Si la commande est trouvée, utiliser son ID
            if ($order) {
                $orderId = $order->id;
            }
        }

        $companyPage = null;
        $contactInfo = null;

        // Si un order_id est trouvé, utiliser la page entreprise de cette commande spécifique
        if ($orderId && $order) {
            // Récupérer la page entreprise de cette commande spécifique (publiée d'abord)
            $companyPage = CompanyPage::where('order_id', $orderId)
                ->where('user_id', $user->id)
                ->where('is_published', true)
                ->first();

            // Si pas de page publiée pour cette commande, essayer une page non publiée
            if (!$companyPage) {
                $companyPage = CompanyPage::where('order_id', $orderId)
                    ->where('user_id', $user->id)
                    ->first();
            }

            // Si toujours pas de page pour cette commande, fallback sur une page sans order_id (publiée d'abord)
            if (!$companyPage) {
                $companyPage = CompanyPage::where('user_id', $user->id)
                    ->whereNull('order_id')
                    ->where('is_published', true)
                    ->first();
            }
            
            // Si toujours pas de page publiée sans order_id, essayer une page non publiée
            if (!$companyPage) {
                $companyPage = CompanyPage::where('user_id', $user->id)
                    ->whereNull('order_id')
                    ->first();
            }

            // Récupérer les informations de contact depuis OrderEmployee de cette commande spécifique
            // Pour un business_admin, chercher dans OrderEmployee si il est inclus dans la commande
            // Sinon, utiliser les données de Order directement
            if ($companyPage) {
                // D'abord essayer OrderEmployee si le business_admin est inclus dans cette commande
                $contactInfo = \App\Models\OrderEmployee::where('order_id', $orderId)
                    ->where('employee_id', $user->id)
                    ->where('is_configured', true)
                    ->first();
                
                // Si pas trouvé dans OrderEmployee, utiliser les données de Order directement
                if (!$contactInfo && $order) {
                    // Créer un objet avec les données de Order pour compatibilité avec formatPageData
                    $contactInfo = (object)[
                        'profile_name' => $order->profile_name ?? $user->name,
                        'address_neighborhood' => $order->address_neighborhood ?? null,
                        'address_commune' => $order->address_commune ?? null,
                        'address_city' => $order->address_city ?? null,
                        'address_country' => $order->address_country ?? null,
                        'phone_numbers' => $order->phone_numbers ?? null,
                        'emails' => $order->emails ?? null,
                        'website_url' => $order->website_url ?? null,
                        'whatsapp_url' => $order->whatsapp_url ?? null,
                        'linkedin_url' => $order->linkedin_url ?? null,
                        'facebook_url' => $order->facebook_url ?? null,
                        'twitter_url' => $order->twitter_url ?? null,
                        'youtube_url' => $order->youtube_url ?? null,
                        'deezer_url' => $order->deezer_url ?? null,
                        'spotify_url' => $order->spotify_url ?? null,
                    ];
                }
            }
        }

        // Si pas de page trouvée ou pas d'order_id, utiliser la logique par défaut
        if (!$companyPage) {
            // Récupérer sa page entreprise (sans order_id ou la première publiée)
            $companyPage = CompanyPage::where('user_id', $user->id)
                ->where('is_published', true)
                ->orderBy('order_id', 'asc') // Préférer les pages sans order_id d'abord
                ->first();
            
            // Si aucune page publiée n'est trouvée, essayer de trouver une page non publiée
            if (!$companyPage) {
                $companyPage = CompanyPage::where('user_id', $user->id)
                    ->orderBy('order_id', 'asc')
                    ->first();
            }
            
            // Si toujours aucune page, retourner une erreur 404
            if (!$companyPage) {
                Log::warning('Company Page Show - Aucune page entreprise trouvée', [
                    'user_id' => $user->id,
                    'username' => $username,
                    'order_id' => $orderId,
                ]);
                return response()->json([
                    'message' => 'Aucune page entreprise trouvée pour cet utilisateur. Veuillez configurer la section "Nos Services" dans les paramètres.',
                ], 404);
            }

            // Récupérer les informations de contact depuis le premier OrderEmployee configuré
            if (!$contactInfo) {
                $contactInfo = \App\Models\OrderEmployee::where('employee_id', $user->id)
                    ->where('is_configured', true)
                    ->first();
            }
            
            // Si la companyPage a un order_id mais qu'on ne l'a pas encore, l'utiliser
            if (!$orderId && $companyPage && $companyPage->order_id) {
                $orderId = $companyPage->order_id;
                // Récupérer la commande correspondante
                $order = \App\Models\Order::where('id', $orderId)
                    ->where('user_id', $user->id)
                    ->first();
            }
        }

        // Log pour debug
        // IMPORTANT: Utiliser l'opérateur null-safe ?-> pour éviter les erreurs si $contactInfo est null
        Log::info('Company Page Show - Page trouvée', [
            'user_id' => $user->id,
            'username' => $username,
            'order_param' => $request->query('order'),
            'order_id' => $orderId,
            'order_found' => $order ? 'yes' : 'no',
            'order_number' => $order ? $order->order_number : null,
            'company_page_id' => $companyPage->id ?? null,
            'company_page_order_id' => $companyPage->order_id ?? null,
            'is_published' => $companyPage->is_published ?? false,
            'has_contact_info' => $contactInfo ? 'yes' : 'no',
            'whatsapp' => $contactInfo?->whatsapp_url ?? 'N/A',
            'linkedin' => $contactInfo?->linkedin_url ?? 'N/A',
            'facebook' => $contactInfo?->facebook_url ?? 'N/A',
        ]);

        // Retourner les données pour le frontend
        // IMPORTANT: Passer orderId pour récupérer la configuration de rendez-vous spécifique à la commande
        $pageData = $this->formatPageData($companyPage, $contactInfo, $user, $orderId);
        
        Log::info('Company Page Show - pageData formaté', [
            'order_id_in_pageData' => $pageData['order_id'] ?? null,
            'user_id_in_pageData' => $pageData['user_id'] ?? null,
            'appointment_setting_enabled' => $pageData['appointment_setting']['is_enabled'] ?? null,
        ]);
        
        return response()->json([
            'user' => [
                'username' => $user->username,
                'company_name' => $user->company_name,
            ],
            'pageData' => $pageData,
        ]);
    }

    /**
     * Affiche la page publique entreprise par short_code (URL courte /e/{code})
     * Même format de réponse que show() pour réutilisation du frontend.
     */
    public function showByCode($code)
    {
        $code = trim((string) $code);
        if ($code === '') {
            return response()->json(['message' => 'Code invalide.'], 400);
        }

        $order = Order::where('short_code', $code)
            ->where('status', 'validated')
            ->first();

        if (!$order) {
            Log::warning('Company Page ShowByCode - Commande non trouvée ou non validée', ['code' => $code]);
            return response()->json([
                'message' => 'Page entreprise non trouvée ou lien invalide.',
            ], 404);
        }

        $user = $order->user;
        if (!$user || $user->role !== 'business_admin') {
            return response()->json([
                'message' => 'Page entreprise non disponible pour ce lien.',
            ], 404);
        }

        $orderId = $order->id;

        $companyPage = CompanyPage::where('order_id', $orderId)
            ->where('user_id', $user->id)
            ->where('is_published', true)
            ->first();
        if (!$companyPage) {
            $companyPage = CompanyPage::where('order_id', $orderId)
                ->where('user_id', $user->id)
                ->first();
        }
        if (!$companyPage) {
            $companyPage = CompanyPage::where('user_id', $user->id)
                ->whereNull('order_id')
                ->where('is_published', true)
                ->first();
        }
        if (!$companyPage) {
            $companyPage = CompanyPage::where('user_id', $user->id)
                ->whereNull('order_id')
                ->first();
        }

        if (!$companyPage) {
            Log::warning('Company Page ShowByCode - Aucune page entreprise', ['order_id' => $orderId]);
            return response()->json([
                'message' => 'Aucune page entreprise trouvée. Veuillez configurer la section "Nos Services" dans les paramètres.',
            ], 404);
        }

        $contactInfo = \App\Models\OrderEmployee::where('order_id', $orderId)
            ->where('employee_id', $user->id)
            ->where('is_configured', true)
            ->first();
        if (!$contactInfo && $order) {
            $contactInfo = (object)[
                'profile_name' => $order->profile_name ?? $user->name,
                'address_neighborhood' => $order->address_neighborhood ?? null,
                'address_commune' => $order->address_commune ?? null,
                'address_city' => $order->address_city ?? null,
                'address_country' => $order->address_country ?? null,
                'phone_numbers' => $order->phone_numbers ?? null,
                'emails' => $order->emails ?? null,
                'website_url' => $order->website_url ?? null,
                'whatsapp_url' => $order->whatsapp_url ?? null,
                'linkedin_url' => $order->linkedin_url ?? null,
                'facebook_url' => $order->facebook_url ?? null,
                'twitter_url' => $order->twitter_url ?? null,
                'youtube_url' => $order->youtube_url ?? null,
                'deezer_url' => $order->deezer_url ?? null,
                'spotify_url' => $order->spotify_url ?? null,
            ];
        }

        $pageData = $this->formatPageData($companyPage, $contactInfo, $user, $orderId);

        return response()->json([
            'user' => [
                'username' => $user->username,
                'company_name' => $user->company_name,
            ],
            'pageData' => $pageData,
        ]);
    }

    /**
     * Formate les données de la page pour le frontend
     * 
     * @param CompanyPage $companyPage
     * @param mixed $contactInfo
     * @param User|null $user
     * @param int|null $orderId Pour récupérer la configuration de rendez-vous spécifique à la commande
     */
    private function formatPageData($companyPage, $contactInfo = null, $user = null, $orderId = null)
    {
        $primaryColor = $companyPage->primary_color ?? '#3b82f6';

        // Récupérer la configuration des rendez-vous spécifique à la commande si fournie
        // IMPORTANT: Ne PAS faire de fallback sur la configuration générale
        // L'icône ne doit s'afficher QUE si la configuration est activée pour cette commande spécifique
        $appointmentSetting = null;
        $userId = null;
        if ($user) {
            $userId = $user->id;
            
            // Chercher UNIQUEMENT la configuration spécifique à la commande
            if ($orderId) {
                $appointmentSetting = AppointmentSetting::where('user_id', $user->id)
                    ->where('order_id', $orderId)
                    ->first();
            }
        }

        // Construire l'adresse complète depuis les données de "Ma Carte"
        // IMPORTANT: Utiliser l'opérateur null-safe ?-> pour éviter les erreurs si $contactInfo est null
        $fullAddress = null;
        if ($contactInfo) {
            $addressParts = array_filter([
                $contactInfo->address_neighborhood ?? null,
                $contactInfo->address_commune ?? null,
                $contactInfo->address_city ?? null,
                $contactInfo->address_country ?? null
            ]);
            $fullAddress = !empty($addressParts) ? implode(', ', $addressParts) : null;
        }

        // Formater les téléphones depuis l'array JSON
        // IMPORTANT: Utiliser l'opérateur null-safe ?-> pour éviter les erreurs si $contactInfo est null
        $phoneNumbers = null;
        if ($contactInfo) {
            $phones = $contactInfo->phone_numbers ?? null;
            if ($phones && is_array($phones)) {
                $phoneNumbers = implode(' / ', array_filter($phones));
            }
        }

        // Récupérer l'email (priorité : CompanyPage, sinon OrderEmployee)
        $contactEmail = $companyPage->contact_email;
        if (!$contactEmail && $contactInfo) {
            $emails = $contactInfo->emails ?? null;
            if ($emails && is_array($emails) && count($emails) > 0) {
                $contactEmail = $emails[0];
            }
        }

        // Déterminer l'URL du site web à afficher dans le footer de contact
        // Si la case "website_featured_in_services_button" n'est pas cochée mais que company_website_url est définie,
        // afficher company_website_url dans le footer. Sinon, utiliser website_url depuis contactInfo.
        $footerWebsiteUrl = null;
        if (!$companyPage->website_featured_in_services_button && $companyPage->company_website_url) {
            // Si la case n'est pas cochée mais que l'URL est définie, utiliser company_website_url
            $footerWebsiteUrl = $companyPage->company_website_url;
        } elseif ($contactInfo) {
            // Sinon, utiliser website_url depuis contactInfo (depuis "Ma Carte")
            $footerWebsiteUrl = $contactInfo->website_url ?? null;
        }

        return [
            'company_name' => $companyPage->company_name,
            'company_name_short' => $companyPage->company_name_short,
            'logo_url' => $companyPage->logo_url ? url($companyPage->logo_url) : null,
            'services' => $companyPage->services ?? [],
            'hero_headline' => $companyPage->hero_headline,
            'hero_subheadline' => $companyPage->hero_subheadline,
            'hero_description' => $companyPage->hero_description,
            'chart_labels' => $companyPage->chart_labels,
            'chart_data' => $companyPage->chart_data,
            'chart_colors' => $companyPage->chart_colors,
            'chart_title' => $companyPage->chart_title,
            'chart_description' => $companyPage->chart_description,
            'pillars' => $companyPage->pillars,
            'pillars_title' => $companyPage->pillars_title,
            'engagement_description' => $companyPage->engagement_description,
            'products_button_text' => $companyPage->products_button_text ?? 'Nos Produits',
            'products_button_icon' => $companyPage->products_button_icon ?? 'fa-list',
            'products_modal_title' => $companyPage->products_modal_title ?? 'Nos Produits et Services',
            'processes_title' => $companyPage->processes_title,
            'process_order_title' => $companyPage->process_order_title,
            'process_order_description' => $companyPage->process_order_description,
            'process_order_steps' => $companyPage->process_order_steps,
            'process_logistics_title' => $companyPage->process_logistics_title,
            'process_logistics_description' => $companyPage->process_logistics_description,
            'process_logistics_steps' => $companyPage->process_logistics_steps,
            // Informations de contact récupérées depuis "Ma Carte" (OrderEmployee)
            // IMPORTANT: Utiliser l'opérateur null-safe ?-> pour éviter les erreurs si $contactInfo est null
            'contact_name' => $contactInfo?->profile_name ?? null,
            'contact_address' => $fullAddress,
            'contact_phones' => $phoneNumbers,
            'contact_email' => $contactEmail,
            // Réseaux sociaux depuis "Ma Carte"
            'whatsapp_url' => $contactInfo?->whatsapp_url ?? null,
            'linkedin_url' => $contactInfo?->linkedin_url ?? null,
            'facebook_url' => $contactInfo?->facebook_url ?? null,
            'twitter_url' => $contactInfo?->twitter_url ?? null,
            'youtube_url' => $contactInfo?->youtube_url ?? null,
            'deezer_url' => $contactInfo?->deezer_url ?? null,
            'spotify_url' => $contactInfo?->spotify_url ?? null,
            // URL du site web : priorité à company_website_url si la case n'est pas cochée, sinon website_url depuis contactInfo
            'website_url' => $footerWebsiteUrl,
            'primary_color' => $primaryColor,
            // Classes Tailwind générées dynamiquement basées sur la couleur principale
            'bg_color_600' => 'bg-[' . $primaryColor . ']',
            'bg_color_800' => 'bg-[' . $primaryColor . ']',
            'hover_bg_color_600' => 'hover:bg-[' . $primaryColor . ']',
            'hover_bg_color_800' => 'hover:bg-[' . $primaryColor . ']',
            'text_color_500' => 'text-[' . $primaryColor . ']',
            'text_color_600' => 'text-[' . $primaryColor . ']',
            'text_color_700' => 'text-[' . $primaryColor . ']',
            'hover_text_color_500' => 'hover:text-[' . $primaryColor . ']',
            // Données pour la prise de rendez-vous
            'user_id' => $userId,
            'order_id' => $orderId,
            'appointment_setting' => $appointmentSetting ? [
                'is_enabled' => $appointmentSetting->is_enabled,
            ] : null,
        ];
    }

    /**
     * Génère automatiquement le contenu marketing avec Gemini AI
     */
    public function generateContent(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'business_admin') {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        // Récupérer order_id depuis la requête
        $orderId = $request->input('order_id');

        if ($orderId) {
            // Vérifier que la commande appartient bien à l'utilisateur
            // Un business_admin peut configurer "Nos Services" pour toutes ses commandes (business ou personal)
            $order = \App\Models\Order::where('id', $orderId)
                ->where('user_id', $user->id)
                ->first();

            if (!$order) {
                return response()->json(['message' => 'Commande non trouvée ou non autorisée.'], 404);
            }

            $companyPage = CompanyPage::firstOrCreate(
                ['order_id' => $orderId],
                [
                    'user_id' => $user->id,
                    'company_name' => $user->company_name,
                    'services' => [],
                ]
            );
        } else {
            // Compatibilité : si pas d'order_id, utiliser user_id
            $companyPage = CompanyPage::firstOrCreate(
                ['user_id' => $user->id, 'order_id' => null],
                [
                    'company_name' => $user->company_name,
                    'services' => [],
                ]
            );
        }

        // Utiliser les données du formulaire si elles sont fournies, sinon utiliser celles de la BDD
        $companyName = $request->input('company_name', $companyPage->company_name);
        $companyNameShort = $request->input('company_name_short', $companyPage->company_name_short);
        $primaryColor = $request->input('primary_color', $companyPage->primary_color ?? '#3b82f6');
        $services = $request->input('services', $companyPage->services ?? []);

        // Vérifier que les données minimales sont présentes
        if (empty($companyName)) {
            return response()->json([
                'message' => 'Le nom de l\'entreprise est requis pour générer le contenu.'
            ], 400);
        }

        if (empty($services) || count($services) === 0) {
            return response()->json([
                'message' => 'Au moins un service est requis pour générer le contenu.'
            ], 400);
        }

        // Mettre à jour la page avec les données du formulaire AVANT la génération
        $companyPage->update([
            'company_name' => $companyName,
            'company_name_short' => $companyNameShort,
            'primary_color' => $primaryColor,
            'services' => $services,
        ]);

        // Appeler le service Gemini
        $geminiService = new GeminiService();
        $generatedContent = $geminiService->generateCompanyPageContent([
            'company_name' => $companyName,
            'company_name_short' => $companyNameShort,
            'primary_color' => $primaryColor,
            'services' => $services,
        ]);

        if (!$generatedContent) {
            return response()->json([
                'message' => 'Erreur lors de la génération du contenu. Veuillez réessayer.'
            ], 500);
        }

        // Mettre à jour la page avec le contenu généré
        $companyPage->update([
            'hero_headline' => $generatedContent['hero_headline'] ?? null,
            'hero_subheadline' => $generatedContent['hero_subheadline'] ?? null,
            'hero_description' => $generatedContent['hero_description'] ?? null,
            'products_button_text' => $generatedContent['products_button_text'] ?? 'Nos Produits',
            'products_button_icon' => $generatedContent['products_button_icon'] ?? 'fa-list',
            'products_modal_title' => $generatedContent['products_modal_title'] ?? 'Nos Produits et Services',
            'chart_title' => $generatedContent['chart_title'] ?? null,
            'chart_description' => $generatedContent['chart_description'] ?? null,
            'chart_labels' => $generatedContent['chart_labels'] ?? [],
            'chart_data' => $generatedContent['chart_data'] ?? [],
            'chart_colors' => $generatedContent['chart_colors'] ?? [],
            'pillars' => $generatedContent['pillars'] ?? [],
            'pillars_title' => $generatedContent['pillars_title'] ?? null,
            'processes_title' => $generatedContent['processes_title'] ?? null,
            'process_order_title' => $generatedContent['process_order_title'] ?? null,
            'process_order_description' => $generatedContent['process_order_description'] ?? null,
            'process_order_steps' => $generatedContent['process_order_steps'] ?? [],
            'process_logistics_title' => $generatedContent['process_logistics_title'] ?? null,
            'process_logistics_description' => $generatedContent['process_logistics_description'] ?? null,
            'process_logistics_steps' => $generatedContent['process_logistics_steps'] ?? [],
            'engagement_description' => $generatedContent['engagement_description'] ?? null,
            'services' => $generatedContent['services'] ?? $companyPage->services,
        ]);

        // Formater les données pour inclure l'URL complète du logo
        $freshData = $companyPage->fresh()->toArray();
        if (!empty($freshData['logo_url'])) {
            $freshData['logo_url'] = url($freshData['logo_url']);
        }

        return response()->json([
            'message' => 'Contenu généré avec succès !',
            'companyPage' => $freshData,
        ]);
    }

    /**
     * Extrait les informations d'un fichier de présentation (PDF ou image)
     */
    public function extractPresentation(Request $request)
    {
        // Augmenter les limites de temps d'exécution pour les gros fichiers
        set_time_limit(180); // 3 minutes
        ini_set('max_execution_time', 180);

        $user = $request->user();

        if ($user->role !== 'business_admin') {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        $request->validate([
            'presentation' => 'required|file|mimes:pdf,jpeg,png,jpg|max:2048', // Max 2MB
        ]);

        try {
            $file = $request->file('presentation');
            $extension = $file->getClientOriginalExtension();
            $fileSize = $file->getSize();
            $extractedText = '';

            Log::info('Tentative d\'extraction de fichier', [
                'extension' => $extension,
                'size' => $fileSize,
                'mime' => $file->getMimeType()
            ]);

            // Extraire le texte selon le type de fichier
            if ($file->getMimeType() === 'application/pdf') {
                // PDF : extraction rapide via pdftotext (Windows + Linux)
                $extractedText = $this->extractTextFromPdfWithPoppler($file);
                if (strlen($extractedText) < 50) {
                    $extractedText = $this->extractTextFromPdfWithGemini($file);
                }
            } else {
                // Pour les images, utiliser Gemini Vision API
                $extractedText = $this->extractTextFromImageWithGemini($file);
            }

            Log::info('Texte extrait', ['length' => strlen($extractedText)]);

            if (empty($extractedText)) {
                return response()->json([
                    'message' => 'Impossible d\'extraire le texte du fichier. L\'API Gemini est peut-être temporairement indisponible. Veuillez réessayer dans quelques instants.'
                ], 503);
            }

            // Utiliser Gemini pour analyser le texte et extraire les informations structurées
            $geminiService = new GeminiService();
            $extractedData = $geminiService->extractCompanyInfoFromText($extractedText);

            if (!$extractedData) {
                return response()->json([
                    'message' => 'Erreur lors de l\'analyse du texte. Veuillez réessayer.'
                ], 500);
            }

            // ✅ Marketplace: si aucun site web fourni, extraire des besoins (keywords) depuis le document
            try {
                $companyPage = CompanyPage::where('user_id', $user->id)->first();
                $hasWebsite = !empty($user->website_url) || (!empty($companyPage) && !empty($companyPage->company_website_url));

                if (!$hasWebsite) {
                    $needsData = $geminiService->extractMarketplaceNeedsFromText($extractedText, $user->title ?? null);
                    if ($needsData && is_array($needsData)) {
                        $keywords = $needsData['keywords'] ?? [];
                        if (!is_array($keywords)) {
                            $keywords = [];
                        }

                        MarketplaceUserNeed::updateOrCreate(
                            ['user_id' => $user->id, 'source' => 'gemini_document'],
                            [
                                'source_ref' => $file->getClientOriginalName(),
                                'keywords' => array_values(array_unique(array_filter(array_map('strtolower', $keywords)))),
                                'needs' => $needsData['needs'] ?? null,
                                'last_error' => empty($keywords) ? 'Gemini: aucun keyword retourné (document)' : null,
                                'last_extracted_at' => now(),
                            ]
                        );

                        // Déclencher matching (sync si driver sync)
                        if (config('queue.default') === 'sync') {
                            (new \App\Jobs\ProcessMarketplaceMatching($user->id))->handle();
                        } else {
                            \App\Jobs\ProcessMarketplaceMatching::dispatch($user->id);
                        }
                    } else {
                        MarketplaceUserNeed::updateOrCreate(
                            ['user_id' => $user->id, 'source' => 'gemini_document'],
                            [
                                'source_ref' => $file->getClientOriginalName(),
                                'keywords' => [],
                                'needs' => null,
                                'last_error' => 'Gemini: extraction besoins impossible (document)',
                                'last_extracted_at' => now(),
                            ]
                        );
                    }
                }
            } catch (\Throwable $e) {
                Log::error('CompanyPageController: Marketplace needs (Gemini) - erreur', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                try {
                    MarketplaceUserNeed::updateOrCreate(
                        ['user_id' => $user->id, 'source' => 'gemini_document'],
                        [
                            'source_ref' => $file->getClientOriginalName(),
                            'keywords' => [],
                            'needs' => null,
                            'last_error' => $e->getMessage(),
                            'last_extracted_at' => now(),
                        ]
                    );
                } catch (\Throwable $ignored) {
                    // noop
                }
            }

            return response()->json([
                'message' => 'Fichier analysé avec succès ! Les informations ont été extraites.',
                'extractedData' => $extractedData,
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'extraction du fichier: ' . $e->getMessage());
            return response()->json([
                'message' => 'Erreur lors du traitement du fichier.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Extrait le texte d'un PDF via pdftotext (Poppler) - Windows + Linux
     */
    private function extractTextFromPdfWithPoppler($file)
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $binPath = config('poppler.bin_path');
        if (empty($binPath)) {
            $binPath = $isWindows
                ? 'C:\\poppler\\Library\\bin\\pdftotext.exe'
                : '/usr/bin/pdftotext';
        }

        $tempDir = sys_get_temp_dir();
        $id = uniqid('pres_');
        $tempPdf = $tempDir . DIRECTORY_SEPARATOR . $id . '.pdf';
        $tempTxt = $tempDir . DIRECTORY_SEPARATOR . $id . '.txt';

        try {
            file_put_contents($tempPdf, file_get_contents($file->getRealPath()));
            $cmd = sprintf('"%s" -layout -enc UTF-8 "%s" "%s"', $binPath, $tempPdf, $tempTxt);
            $output = [];
            $returnVar = 0;
            exec($cmd . ' 2>&1', $output, $returnVar);

            if ($returnVar === 0 && file_exists($tempTxt)) {
                return trim(file_get_contents($tempTxt));
            }
        } catch (\Exception $e) {
            Log::error('Poppler extraction error: ' . $e->getMessage());
        } finally {
            @unlink($tempPdf);
            @unlink($tempTxt);
        }

        return '';
    }

    /**
     * Extrait le texte d'une image en utilisant Gemini Vision API
     */
    private function extractTextFromImageWithGemini($file)
    {
        try {
            // Lire le contenu du fichier et le convertir en base64
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
    private function extractTextFromPdfWithGemini($file)
    {
        try {
            // Convertir le PDF en base64
            $pdfData = base64_encode(file_get_contents($file->getRealPath()));

            $geminiService = new GeminiService();
            return $geminiService->extractTextFromPdf($pdfData);
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'extraction du texte du PDF: ' . $e->getMessage());
            return '';
        }
    }
}
