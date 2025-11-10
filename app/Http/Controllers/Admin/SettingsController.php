<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    /**
     * Récupère tous les paramètres de l'application
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $settings = Setting::all()->pluck('value', 'key');

        // Définir les paramètres par défaut s'ils n'existent pas
        $defaults = [
            'card_price' => '20000',
            'additional_card_price' => '45000',
            'subscription_price' => '40000',
            'site_name' => 'Digicard',
            'support_email' => 'support@digicard.com',
            'max_cards_per_order' => '100',
            'max_employees_per_order' => '100',
            'allow_registration' => 'true',
            'require_email_verification' => 'true',
        ];

        // Fusionner avec les valeurs par défaut
        $allSettings = array_merge($defaults, $settings->toArray());

        return response()->json([
            'settings' => $allSettings
        ]);
    }

    /**
     * Renvoie publiquement les tarifs (sans auth), pour l'affichage client.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPublicPricing()
    {
        $defaults = [
            'card_price' => '180000',
            'additional_card_price' => '45000',
            'subscription_price' => '40000',
        ];

        $settings = Setting::whereIn('key', array_keys($defaults))
            ->pluck('value', 'key')
            ->toArray();

        $pricing = array_merge($defaults, $settings);

        // Cast en int pour le front
        $pricing = array_map(fn($v) => (int) $v, $pricing);

        return response()->json(['pricing' => $pricing]);
    }

    /**
     * Renvoie publiquement le contenu de la page d'accueil (CMS léger)
     */
    public function getPublicHomepage()
    {
        $defaults = [
            'hero_title' => 'Cartes de visite digitales intelligentes',
            'hero_subtitle' => "Partagez votre identité en un geste, où que vous soyez.",
            'hero_cta_text' => 'Commander maintenant',
            'hero_cta_link' => '/#commander',
            'hero_image_url' => null,
            'highlights' => [
                ['title' => 'NFC + QR', 'text' => 'Compatible iOS/Android et QR Code'],
                ['title' => 'Personnalisable', 'text' => 'Votre branding, vos couleurs'],
                ['title' => 'Mise à jour instantanée', 'text' => 'Modifiez sans réimprimer'],
            ],
            'faqs' => [
                [
                    'question' => 'Comment fonctionne la technologie NFC ?',
                    'answer' => "La technologie NFC (Near Field Communication) permet à votre carte de communiquer sans contact avec un smartphone compatible. Il suffit d'approcher la carte du téléphone pour que votre profil s'affiche instantanément, sans aucune application nécessaire."
                ],
                [
                    'question' => 'Dois-je recharger ma carte ?',
                    'answer' => "Non, nos cartes et stickers NFC sont passifs, ce qui signifie qu'ils n'ont pas de batterie et n'ont jamais besoin d'être rechargés. Ils sont alimentés par le champ magnétique du téléphone lorsqu'il est à proximité."
                ],
                [
                    'question' => 'Puis-je modifier mon profil après avoir commandé ma carte ?',
                    'answer' => "Oui, absolument ! Vous pouvez vous connecter à votre espace membre à tout moment pour mettre à jour vos informations, liens et photos. Les changements sont appliqués en temps réel sur votre profil public."
                ],
                [
                    'question' => 'Est-ce que tous les téléphones sont compatibles ?',
                    'answer' => "La grande majorité des smartphones modernes (iOS et Android) sont équipés de la technologie NFC. Pour les téléphones plus anciens, chaque carte dispose également d'un QR Code que vous pouvez faire scanner pour un accès garanti à votre profil."
                ]
            ],
            'testimonials' => [
                [
                    'text' => "Absolument révolutionnaire ! En tant que consultant, je rencontre des dizaines de personnes chaque semaine. Cette carte a simplifié mes échanges et renforcé mon image de marque. Un indispensable.",
                    'author_name' => 'Aminata Bah',
                    'author_role' => 'Consultante en Stratégie',
                    'avatar_url' => '/images/avatar1.jpg'
                ],
                [
                    'text' => "J'étais sceptique au début, mais le sticker NFC est génial. Je l'ai collé sur mon téléphone et je n'ai plus jamais à m'inquiéter d'oublier mes cartes de visite. Efficace et très pro.",
                    'author_name' => 'Mamadou Diallo',
                    'author_role' => 'CEO, Tech Innov',
                    'avatar_url' => '/images/avatar2.jpg'
                ],
                [
                    'text' => "Le design de la carte PVC est superbe et la personnalisation du profil en ligne est très intuitive. Nos clients sont impressionnés à chaque fois. Je recommande vivement !",
                    'author_name' => 'Fatou Camara',
                    'author_role' => 'Directrice Marketing, Agence Créa',
                    'avatar_url' => '/images/avatar3.jpg'
                ]
            ],
            'social_proof' => [
                [
                    'name' => 'IconValley',
                    'logo_url' => '/images/LogoIconValley.png'
                ],
                [
                    'name' => 'Gnalenmady Consulting',
                    'logo_url' => '/images/LogoGnalenmady.png'
                ],
                [
                    'name' => 'Byte Securitas',
                    'logo_url' => '/images/LogoByteSecuritas.png'
                ],
                [
                    'name' => 'Bally Multi Expertise',
                    'logo_url' => '/images/LogoBMEX.png'
                ],
                [
                    'name' => 'AGEP Events',
                    'logo_url' => '/images/LogoAGEP.png'
                ],
            ],
        ];

        $existing = Setting::where('key', 'homepage_content')->value('value');
        $content = $existing ? json_decode($existing, true) : [];
        $merged = array_merge($defaults, is_array($content) ? $content : []);

        return response()->json(['homepage' => $merged]);
    }

    /**
     * Renvoie le contenu d'accueil pour l'admin
     */
    public function getHomepage()
    {
        $existing = Setting::where('key', 'homepage_content')->value('value');
        $content = $existing ? json_decode($existing, true) : null;
        return response()->json(['homepage' => $content]);
    }

    /**
     * Met à jour le contenu d'accueil (admin)
     */
    public function updateHomepage(Request $request)
    {
        $data = $request->validate([
            'hero_title' => 'nullable|string|max:150',
            'hero_subtitle' => 'nullable|string|max:300',
            'hero_cta_text' => 'nullable|string|max:80',
            'hero_cta_link' => 'nullable|string|max:200',
            'hero_image_url' => 'nullable|string|max:500',
            'highlights' => 'nullable|array',
            'highlights.*.title' => 'nullable|string|max:80',
            'highlights.*.text' => 'nullable|string|max:160',
            // FAQs
            'faqs' => 'nullable|array',
            'faqs.*.question' => 'nullable|string|max:500',
            'faqs.*.answer' => 'nullable|string|max:2000',
            // Testimonials
            'testimonials' => 'nullable|array',
            'testimonials.*.text' => 'nullable|string|max:1000',
            'testimonials.*.author_name' => 'nullable|string|max:100',
            'testimonials.*.author_role' => 'nullable|string|max:200',
            'testimonials.*.avatar_url' => 'nullable|string|max:500',
            // Social Proof (entreprises)
            'social_proof' => 'nullable|array',
            'social_proof.*.name' => 'nullable|string|max:200',
            'social_proof.*.logo_url' => 'nullable|string|max:500',
        ]);

        Setting::set('homepage_content', json_encode($data));

        return response()->json(['message' => 'Homepage mise à jour avec succès', 'homepage' => $data]);
    }

    /**
     * Met à jour les paramètres de l'application
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request)
    {
        // Valider les paramètres critiques
        $request->validate([
            'card_price' => 'nullable|numeric|min:0',
            'additional_card_price' => 'nullable|numeric|min:0',
            'subscription_price' => 'nullable|numeric|min:0',
            'max_cards_per_order' => 'nullable|integer|min:1|max:1000',
            'max_employees_per_order' => 'nullable|integer|min:1|max:1000',
            'support_email' => 'nullable|email',
        ]);

        $updatedSettings = [];

        // Boucler sur tous les paramètres de la requête
        foreach ($request->all() as $key => $value) {
            // Ignorer les champs non liés aux paramètres
            if (in_array($key, ['_method', '_token'])) {
                continue;
            }

            // Convertir les booléens en chaînes
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            // Mettre à jour ou créer le paramètre
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $value]
            );

            $updatedSettings[$key] = $value;
        }

        // Logger l'action
        Log::info('Admin settings updated', [
            'admin_id' => auth()->id(),
            'admin_email' => auth()->user()->email,
            'updated_settings' => array_keys($updatedSettings),
            'timestamp' => now(),
        ]);

        return response()->json([
            'message' => 'Paramètres mis à jour avec succès',
            'settings' => $updatedSettings
        ]);
    }

    /**
     * Vérifie le statut du système (variables d'environnement)
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSystemStatus()
    {
        $status = [
            // Configuration Email
            'mail_configured' => !empty(env('MAIL_HOST')) && !empty(env('MAIL_USERNAME')),
            'mail_host_set' => !empty(env('MAIL_HOST')),
            'mail_username_set' => !empty(env('MAIL_USERNAME')),
            'mail_from_set' => !empty(env('MAIL_FROM_ADDRESS')),

            // Configuration Gemini AI
            'gemini_configured' => !empty(env('GEMINI_API_KEY')),
            'gemini_api_key_set' => !empty(env('GEMINI_API_KEY')),

            // Configuration Base de données
            'database_configured' => !empty(env('DB_DATABASE')),
            'db_connection_set' => !empty(env('DB_CONNECTION')),
            'db_host_set' => !empty(env('DB_HOST')),

            // Configuration Application
            'app_key_set' => !empty(env('APP_KEY')),
            'app_debug' => env('APP_DEBUG', false),
            'app_env' => env('APP_ENV', 'production'),
            'app_url_set' => !empty(env('APP_URL')),

            // Statut du mode maintenance
            'maintenance_mode' => app()->isDownForMaintenance(),

            // Informations système
            'php_version' => phpversion(),
            'laravel_version' => app()->version(),
            'storage_writable' => is_writable(storage_path()),
            'cache_writable' => is_writable(storage_path('framework/cache')),

            // Statistiques
            'total_users' => \App\Models\User::count(),
            'total_orders' => \App\Models\Order::count(),
            'total_company_pages' => \App\Models\CompanyPage::count(),
            'database_size' => $this->getDatabaseSize(),
        ];

        return response()->json([
            'status' => $status,
            'overall_health' => $this->calculateOverallHealth($status)
        ]);
    }

    /**
     * Active ou désactive le mode maintenance
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggleMaintenance()
    {
        $isDown = app()->isDownForMaintenance();

        try {
            if ($isDown) {
                // Activer le site (up)
                Artisan::call('up');
                $message = 'Mode maintenance désactivé. Le site est maintenant accessible.';
                $newStatus = false;
            } else {
                // Désactiver le site (down)
                Artisan::call('down', [
                    '--render' => 'errors::503',
                    '--retry' => 60,
                ]);
                $message = 'Mode maintenance activé. Le site est inaccessible pour les utilisateurs.';
                $newStatus = true;
            }

            // Logger l'action
            Log::warning('Admin maintenance mode toggled', [
                'admin_id' => auth()->id(),
                'admin_email' => auth()->user()->email,
                'was_down' => $isDown,
                'is_down' => $newStatus,
                'timestamp' => now(),
            ]);

            return response()->json([
                'message' => $message,
                'maintenance_mode' => $newStatus
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to toggle maintenance mode', [
                'error' => $e->getMessage(),
                'admin_id' => auth()->id(),
            ]);

            return response()->json([
                'message' => 'Erreur lors du changement de mode maintenance',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Efface le cache de l'application
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function clearCache()
    {
        try {
            Artisan::call('cache:clear');
            Artisan::call('config:clear');
            Artisan::call('route:clear');
            Artisan::call('view:clear');

            Log::info('Admin cleared application cache', [
                'admin_id' => auth()->id(),
                'admin_email' => auth()->user()->email,
                'timestamp' => now(),
            ]);

            return response()->json([
                'message' => 'Cache effacé avec succès'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erreur lors de l\'effacement du cache',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtient la taille de la base de données
     * 
     * @return string
     */
    private function getDatabaseSize(): string
    {
        try {
            $dbName = env('DB_DATABASE');
            $dbConnection = env('DB_CONNECTION', 'mysql');

            if ($dbConnection === 'sqlite') {
                $dbPath = database_path('database.sqlite');
                if (file_exists($dbPath)) {
                    $sizeInBytes = filesize($dbPath);
                    return $this->formatBytes($sizeInBytes);
                }
            } elseif ($dbConnection === 'mysql') {
                $result = \DB::select("
                    SELECT 
                        SUM(data_length + index_length) as size 
                    FROM information_schema.TABLES 
                    WHERE table_schema = ?
                ", [$dbName]);

                if (!empty($result) && isset($result[0]->size)) {
                    return $this->formatBytes($result[0]->size);
                }
            }

            return 'N/A';
        } catch (\Exception $e) {
            return 'Erreur';
        }
    }

    /**
     * Formate les bytes en format lisible
     * 
     * @param int $bytes
     * @param int $precision
     * @return string
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Calcule l'état de santé global du système
     * 
     * @param array $status
     * @return string
     */
    private function calculateOverallHealth(array $status): string
    {
        $criticalChecks = [
            'mail_configured',
            'gemini_configured',
            'database_configured',
            'app_key_set',
            'storage_writable',
        ];

        $passedChecks = 0;
        $totalChecks = count($criticalChecks);

        foreach ($criticalChecks as $check) {
            if (!empty($status[$check])) {
                $passedChecks++;
            }
        }

        $percentage = ($passedChecks / $totalChecks) * 100;

        if ($percentage === 100) {
            return 'excellent';
        } elseif ($percentage >= 80) {
            return 'good';
        } elseif ($percentage >= 60) {
            return 'warning';
        } else {
            return 'critical';
        }
    }

    /**
     * Upload un avatar pour un témoignage
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadTestimonialAvatar(Request $request)
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        try {
            $file = $request->file('avatar');
            $filename = uniqid() . '_' . time() . '.' . $file->getClientOriginalExtension();
            
            // Compresser et stocker l'avatar
            $compressionService = new \App\Services\ImageCompressionService();
            $result = $compressionService->compressImage($file, 'testimonial_avatars');
            // ✅ CORRECTION : Utiliser /api/storage/ pour que la route API soit utilisée
            $url = '/api/storage/' . $result['path'];

            return response()->json([
                'message' => 'Avatar uploadé avec succès',
                'avatar_url' => $url
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur upload avatar témoignage: ' . $e->getMessage());
            return response()->json(['message' => 'Erreur lors de l\'upload'], 500);
        }
    }

    /**
     * Upload un logo pour une entreprise (social proof)
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadSocialProofLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        try {
            $file = $request->file('logo');
            $filename = uniqid() . '_' . time() . '.' . $file->getClientOriginalExtension();
            
            $path = Storage::disk('public')->putFileAs('social_proof_logos', $file, $filename);
            // ✅ CORRECTION : Utiliser /api/storage/ pour que la route API soit utilisée
            $url = '/api/storage/' . $path;

            return response()->json([
                'message' => 'Logo uploadé avec succès',
                'logo_url' => $url
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur upload logo social proof: ' . $e->getMessage());
            return response()->json(['message' => 'Erreur lors de l\'upload'], 500);
        }
    }
}
