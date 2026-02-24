@php
    // Priorité : orderEmployee (pour les employés et business_admin inclus) > order (pour business_admin/individual) > user
    $profileData = $orderEmployee ?? $order ?? null;

    // Nom et titre
    // IMPORTANT: Pour les employés et business_admin inclus, utiliser les données de orderEmployee
    // Pour les business_admin et individual, utiliser les données de order si disponible
    if ($orderEmployee) {
        // Utiliser les données de orderEmployee en priorité
        $displayName = $orderEmployee->profile_name ?? $orderEmployee->employee_name ?? $user->name;
        $displayTitle = $orderEmployee->profile_title ?? $user->title ?? null;

        // DEBUG: Logger pour déboguer
        \Log::info("PublicProfile Blade: orderEmployee trouvé", [
            'order_employee_id' => $orderEmployee->id ?? null,
            'profile_name' => $orderEmployee->profile_name ?? null,
            'profile_title' => $orderEmployee->profile_title ?? null,
            'employee_name' => $orderEmployee->employee_name ?? null,
            'user_title' => $user->title ?? null,
            'display_name' => $displayName,
            'display_title' => $displayTitle,
        ]);
    } elseif ($order) {
        // Utiliser les données de order
        $displayName = $order->profile_name ?? $user->name;
        $displayTitle = $order->profile_title ?? $user->title ?? null;

        // DEBUG: Logger pour déboguer avec toutes les données
        \Log::info("PublicProfile Blade: order trouvé (pas orderEmployee)", [
            'order_id' => $order->id ?? null,
            'profile_name' => $order->profile_name ?? null,
            'profile_title' => $order->profile_title ?? null,
            'user_title' => $user->title ?? null,
            'display_name' => $displayName,
            'display_title' => $displayTitle,
            'phone_numbers' => $order->phone_numbers ?? null,
            'emails' => $order->emails ?? null,
            'website_url' => $order->website_url ?? null,
            'whatsapp_url' => $order->whatsapp_url ?? null,
            'address_city' => $order->address_city ?? null,
            'order_avatar_url' => $order->order_avatar_url ?? null,
            'profile_border_color' => $order->profile_border_color ?? null,
        ]);
    } else {
        // Fallback sur les données de user
        $displayName = $user->name;
        $displayTitle = $user->title ?? null;

        // DEBUG: Logger pour déboguer
        \Log::info("PublicProfile Blade: Aucun orderEmployee ni order trouvé", [
            'user_id' => $user->id ?? null,
            'user_name' => $user->name ?? null,
            'user_title' => $user->title ?? null,
            'display_name' => $displayName,
            'display_title' => $displayTitle,
        ]);
    }

    // Couleurs
    // IMPORTANT: Utiliser les données de orderEmployee en priorité, puis order, puis user
    if ($orderEmployee) {
        $displayBorderColor = $orderEmployee->profile_border_color ?? '#facc15';
        $displaySaveButtonColor = $orderEmployee->save_contact_button_color ?? '#ca8a04';
        $displayServicesButtonColor = $orderEmployee->services_button_color ?? '#0ea5e9';
    } elseif ($order) {
        $displayBorderColor = $order->profile_border_color ?? '#facc15';
        $displaySaveButtonColor = $order->save_contact_button_color ?? '#ca8a04';
        $displayServicesButtonColor = $order->services_button_color ?? '#0ea5e9';
    } else {
        $displayBorderColor = $user->profile_border_color ?? '#facc15';
        $displaySaveButtonColor = $user->save_contact_button_color ?? '#ca8a04';
        $displayServicesButtonColor = $user->services_button_color ?? '#0ea5e9';
    }

    // Avatar : utiliser employee_avatar_url pour les employés, order_avatar_url pour les autres
    $avatarUrl = null;
    if ($orderEmployee && $orderEmployee->employee_avatar_url) {
        $avatarUrl = $orderEmployee->employee_avatar_url;
    } elseif ($order && $order->order_avatar_url) {
        $avatarUrl = $order->order_avatar_url;
    } else {
        $avatarUrl = $user->avatar_url;
    }

    // Logger pour déboguer l'avatar
    \Log::info("PublicProfile Blade: Avatar URL déterminé", [
        'has_orderEmployee' => !is_null($orderEmployee),
        'has_order' => !is_null($order),
        'orderEmployee_avatar_url' => $orderEmployee->employee_avatar_url ?? null,
        'order_avatar_url' => $order->order_avatar_url ?? null,
        'user_avatar_url' => $user->avatar_url ?? null,
        'avatarUrl' => $avatarUrl,
        'displayName' => $displayName,
        'displayTitle' => $displayTitle,
    ]);

    // Gérer différents formats d'URL d'avatar
    if ($avatarUrl) {
        // Si l'URL est déjà complète (http:// ou https://)
        if (str_starts_with($avatarUrl, 'http://') || str_starts_with($avatarUrl, 'https://')) {
            // Extraire le chemin relatif pour vérifier l'existence du fichier
            $parsedUrl = parse_url($avatarUrl);
            $path = $parsedUrl['path'] ?? '';
            
            // Si le chemin contient /storage/, extraire le chemin relatif
            if (str_contains($path, '/storage/')) {
                $relativePath = str_replace('/storage/', '', $path);
                $fileExists = \Storage::disk('public')->exists($relativePath);
                
                if ($fileExists) {
                    // Ajouter un timestamp pour éviter le cache
                    $displayAvatar = $avatarUrl . (str_contains($avatarUrl, '?') ? '&' : '?') . 't=' . time();
                } else {
                    \Log::warning("PublicProfile Blade: Fichier avatar introuvable", [
                        'avatarUrl' => $avatarUrl,
                        'relativePath' => $relativePath,
                        'fullPath' => \Storage::disk('public')->path($relativePath),
                    ]);
                    $displayAvatar = 'https://ui-avatars.com/api/?name=' . urlencode($displayName) . '&background=4b5563&color=ffffff&size=128';
                }
            } else {
                // URL externe, utiliser tel quel
                $displayAvatar = $avatarUrl;
            }
        } elseif (str_starts_with($avatarUrl, '/storage/')) {
            // Chemin relatif commençant par /storage/
            $displayAvatar = url($avatarUrl);
            // Ajouter un timestamp pour éviter le cache
            $displayAvatar .= '?t=' . time();
            // Vérifier que le fichier existe
            $relativePath = str_replace('/storage/', '', $avatarUrl);
            $fileExists = \Storage::disk('public')->exists($relativePath);
            if (!$fileExists) {
                \Log::warning("PublicProfile Blade: Fichier avatar introuvable", [
                    'avatarUrl' => $avatarUrl,
                    'relativePath' => $relativePath,
                    'fullPath' => \Storage::disk('public')->path($relativePath),
                ]);
                $displayAvatar = 'https://ui-avatars.com/api/?name=' . urlencode($displayName) . '&background=4b5563&color=ffffff&size=128';
            }
        } else {
            // Autre format d'URL, essayer de construire l'URL complète
            $displayAvatar = url($avatarUrl);
        }
    } else {
        // Aucun avatar, utiliser l'avatar généré
        $displayAvatar = 'https://ui-avatars.com/api/?name=' . urlencode($displayName) . '&background=4b5563&color=ffffff&size=128';
    }
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $displayName }} - Arcc En Ciel</title>

    <!-- ✅ Favicons DigiCard -->
    <link rel="icon" type="image/png" sizes="16x16" href="https://digicard.arccenciel.com/logo2-16.png" />
    <link rel="icon" type="image/png" sizes="32x32" href="https://digicard.arccenciel.com/logo2-32.png" />
    <link rel="apple-touch-icon" sizes="180x180" href="https://digicard.arccenciel.com/logo2-180.png" />
    <link rel="icon" type="image/png" sizes="192x192" href="https://digicard.arccenciel.com/logo2-192.png" />
    <link rel="icon" type="image/png" sizes="512x512" href="https://digicard.arccenciel.com/logo2-512.png" />

    <script src="https://cdn.tailwindcss.com"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #111827; color: #d1d5db; }
        .btn { transition: all 0.2s ease-in-out; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3); }
        .social-icon { 
            transition: all 0.2s ease-in-out; 
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.25rem;
            vertical-align: middle;
            line-height: 0;
        }
        .social-icon:hover { transform: scale(1.15); }
        .social-icon.whatsapp:hover { color: #25D366; }
        .social-icon.linkedin:hover { color: #0A66C2; }
        .social-icon.facebook:hover { color: #1877F2; }
        .social-icon.twitter:hover { color: #1DA1F2; }
        .social-icon.youtube:hover { color: #FF0000; }
        .social-icon.deezer:hover { color: #EF5466; }
        .social-icon.spotify:hover { color: #1DB954; }
        .social-icon.tiktok:hover { color: #000000; }
        .social-icon.threads:hover { color: #000000; }
        .social-icon.calendar:hover { color: #0ea5e9; }
        .social-icon.exchange:hover { color: #10b981; }
        /* Garantir que les icônes sont bien organisées et centrées */
        .social-icons-container {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            flex-wrap: nowrap;
            width: 100%;
            overflow: visible;
            padding: 0;
            margin: -0.5rem auto 0 auto;
        }
        .social-icons-group {
            display: flex;
            align-items: center;
            gap: 1rem;
            overflow: visible;
            flex-wrap: nowrap;
            justify-content: center;
            line-height: 1;
        }
        /* Ajustement dynamique selon le nombre d'icônes */
        .icons-count-high .social-icons-group {
            gap: 0.75rem;
        }
        .icons-count-very-high .social-icons-group {
            gap: 0.5rem;
        }
        .social-icons-group.left {
            justify-content: flex-end;
            align-items: center;
        }
        .social-icons-group.right {
            justify-content: flex-start;
            align-items: center;
        }
        /* Si aucune icône sociale, masquer le groupe vide */
        .social-icons-group.left:empty,
        .social-icons-group.right:empty {
            display: none;
        }
        
        /* Responsive pour tablette */
        @media (max-width: 768px) {
            .social-icons-container {
                padding: 0;
                margin-top: -0.375rem;
            }
        }
        .social-icons-center {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            gap: 0.75rem;
            overflow: visible;
            line-height: 1;
        }
        .icons-count-high .social-icons-center {
            gap: 0.5rem;
        }
        .icons-count-very-high .social-icons-center {
            gap: 0.4rem;
        }
        .social-icon.calendar,
        .social-icon.exchange {
            flex-shrink: 0;
        }
        /* Taille des icônes selon le nombre total */
        .social-icon svg {
            width: 1.75rem;
            height: 1.75rem;
            display: block;
            vertical-align: middle;
        }
        .icons-count-high .social-icon svg {
            width: 1.5rem;
            height: 1.5rem;
        }
        .icons-count-very-high .social-icon svg {
            width: 1.25rem;
            height: 1.25rem;
        }
        
        /* Responsive pour mobile */
        @media (max-width: 640px) {
            .social-icons-container {
                padding: 0 0.5rem;
                gap: 0.6rem;
                justify-content: center;
                margin-top: -0.25rem;
                flex-wrap: nowrap;
            }
            .social-icons-group {
                gap: 0.6rem;
                flex-wrap: nowrap;
            }
            .social-icons-group.left,
            .social-icons-group.right {
                justify-content: center;
            }
            .icons-count-high .social-icons-group {
                gap: 0.4rem;
            }
            .icons-count-very-high .social-icons-group {
                gap: 0.3rem;
            }
            .social-icons-center {
                margin: 0 0.3rem;
            }
            .icons-count-high .social-icons-center {
                margin: 0 0.2rem;
            }
            .icons-count-very-high .social-icons-center {
                margin: 0 0.15rem;
            }
            /* Réduire légèrement les icônes sur mobile */
            .social-icon svg {
                width: 1.4rem;
                height: 1.4rem;
            }
            .icons-count-high .social-icon svg {
                width: 1.2rem;
                height: 1.2rem;
            }
            .icons-count-very-high .social-icon svg {
                width: 1rem;
                height: 1rem;
            }
        }
        
        @media (max-width: 380px) {
            .social-icons-container {
                padding: 0 0.25rem;
                gap: 0.35rem;
                margin-top: -0.25rem;
                flex-wrap: nowrap;
            }
            .social-icons-group {
                gap: 0.35rem;
                flex-wrap: nowrap;
            }
            .icons-count-high .social-icons-group {
                gap: 0.25rem;
            }
            .icons-count-very-high .social-icons-group {
                gap: 0.2rem;
            }
            .social-icons-center {
                margin: 0 0.2rem;
            }
            .icons-count-high .social-icons-center {
                margin: 0 0.15rem;
            }
            .icons-count-very-high .social-icons-center {
                margin: 0 0.1rem;
            }
            /* Réduire encore plus les icônes sur très petits écrans */
            .social-icon svg {
                width: 1.1rem;
                height: 1.1rem;
            }
            .icons-count-high .social-icon svg {
                width: 1rem;
                height: 1rem;
            }
            .icons-count-very-high .social-icon svg {
                width: 0.9rem;
                height: 0.9rem;
            }
        }
        
        /* Forcer toutes les icônes sur une seule ligne centrée */
        @media (max-width: 480px) {
            .social-icons-container {
                flex-wrap: nowrap;
                gap: 0.4rem;
                padding: 0 0.5rem;
                margin-top: -0.25rem;
            }
            .icons-count-very-high .social-icons-container {
                gap: 0.25rem;
                padding: 0 0.25rem;
            }
            .social-icons-group {
                flex-wrap: nowrap;
            }
            .icons-count-very-high .social-icon svg {
                width: 0.9rem;
                height: 0.9rem;
            }
        }
        .modal-content {
            transition: transform 0.3s ease-in-out;
            max-height: 90vh;
            max-height: calc(100vh - 2rem);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .modal-content-body {
            flex: 1;
            overflow-y: auto;
            min-height: 0;
        }
        @media (max-width: 640px) {
            .modal-content {
                max-height: calc(100vh - 1rem);
                margin: 0.5rem;
            }
            .modal-content-body {
                max-height: calc(100vh - 12rem);
            }
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .animate-pulse { animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4">

    <div class="w-full max-w-sm mx-auto bg-gray-800 rounded-2xl shadow-2xl p-6 sm:p-8 text-center border border-gray-700">

        <!-- Photo de profil avec bordure personnalisable -->
        <div class="mb-6">
            <img src="{{ $displayAvatar ?? 'https://ui-avatars.com/api/?name='.urlencode($displayName).'&background=4b5563&color=ffffff&size=128' }}"
                 alt="Photo de profil de {{ $displayName }}"
                 class="w-32 h-32 rounded-full mx-auto border-4 object-cover shadow-lg mb-4"
                 style="border-color: {{ $displayBorderColor }};">

            <!-- Nom complet -->
            <h1 class="text-2xl font-bold text-white">{{ $displayName }}</h1>

            <!-- Titre (en dessous du nom, avec la couleur du cadre) -->
            @if($displayTitle)
                <p class="text-lg font-medium mt-2" style="color: {{ $displayBorderColor }};">
                    {{ $displayTitle }}
                </p>
            @endif

            <!-- Nom de l'entreprise (si applicable) -->
            @if($user->company_name)
                <p class="text-gray-400 text-sm mt-1">{{ $user->company_name }}</p>
            @endif
        </div>

        <!-- Boutons d'action -->
        <div class="space-y-3 mb-8">
            <!-- Bouton Enregistrer le Contact (couleur personnalisable, sans icône) -->
            @php
                $vcardUrl = route('profile.public.vcard', ['user' => $user->username]);
                if ($order) {
                    // Utiliser le token si disponible, sinon utiliser l'ID de commande
                    if ($order->access_token) {
                        $vcardUrl .= '?token=' . urlencode($order->access_token);
                    } else {
                        $vcardUrl .= '?order=' . $order->id;
                    }
                }
            @endphp
            <a href="{{ $vcardUrl }}"
               class="btn block w-full font-bold py-3 px-4 rounded-lg shadow-md text-gray-900"
               style="background-color: {{ $displaySaveButtonColor }};">
                Enregistrer le Contact
            </a>

            <!-- Bouton Découvrir Mon Profil / Menu du jour (pour les comptes particuliers avec portfolio configuré) -->
            @if($portfolioConfigured)
                @if(isset($portfolio) && $portfolio->profile_type === 'restaurant' && $portfolio->menu && (isset($portfolio->menu['dishes']) || isset($portfolio->menu['drinks'])))
                {{-- Bouton Menu du jour pour profil Restaurant --}}
                <a href="{{ config('app.url') }}/api/portfolio/{{ $user->username }}/menu"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="btn block w-full font-bold py-3 px-4 rounded-lg shadow-md text-white"
                   style="background: linear-gradient(135deg, #f97316 0%, #dc2626 100%);">
                    <i class="fas fa-utensils mr-2"></i>Menu du jour
                </a>
                @else
                {{-- Bouton Découvrir mon Profil pour les autres profils --}}
                <a href="{{ config('app.url') }}/api/portfolio/{{ $user->username }}"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="btn block w-full font-bold py-3 px-4 rounded-lg shadow-md text-white"
                   style="background-color: {{ $displayServicesButtonColor }};">
                    Découvrir mon Profil
                </a>
                @endif
            @endif

            <!-- Bouton Découvrir Nos Services (couleur personnalisable) -->
            <!-- Pour business_admin : redirige vers sa propre page ou le site web si configuré -->
            <!-- Pour employee : redirige vers la page de son business admin ou le site web si configuré -->
            @if($companyPagePublished && $companyPageUsername)
                @php
                    // Si le site web est configuré pour être mis en avant dans le bouton, utiliser l'URL du site web
                    // Sinon, rediriger vers la page entreprise de la commande spécifique si disponible
                    if ($websiteFeaturedInServicesButton && $companyWebsiteUrl) {
                        $servicesButtonUrl = $companyWebsiteUrl;
                    } else {
                        // ✅ CORRECTION: Parser FRONTEND_URL pour extraire uniquement la première URL valide
                        $frontendUrlRaw = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173'));
                        $frontendUrl = $frontendUrlRaw;

                        // Si FRONTEND_URL contient plusieurs URLs séparées par des virgules, prendre la première
                        if (strpos($frontendUrlRaw, ',') !== false) {
                            $urls = array_map('trim', explode(',', $frontendUrlRaw));
                            foreach ($urls as $url) {
                                // Vérifier que l'URL est valide
                                if (filter_var($url, FILTER_VALIDATE_URL)) {
                                    $frontendUrl = $url;
                                    break;
                                }
                            }
                        }

                        $servicesButtonUrl = $frontendUrl . '/entreprise/' . $companyPageUsername;
                        // Ajouter l'order_id à l'URL si une commande est fournie pour afficher le contenu spécifique de cette commande
                        if ($order && $order->id) {
                            $servicesButtonUrl .= '?order=' . $order->id;
                        } elseif ($orderEmployee && $orderEmployee->order_id) {
                            $servicesButtonUrl .= '?order=' . $orderEmployee->order_id;
                        }
                    }
                @endphp
            <a href="{{ $servicesButtonUrl }}"
               target="_blank"
               rel="noopener noreferrer"
               class="btn block w-full font-bold py-3 px-4 rounded-lg shadow-md text-white"
               style="background-color: {{ $displayServicesButtonColor }};">
                Découvrir nos Services
            </a>
            @endif
        </div>

        <!-- Réseaux Sociaux -->
        @php
            // IMPORTANT: Utiliser les données de orderEmployee en priorité, puis order, puis user
            if ($orderEmployee) {
                $whatsapp = $orderEmployee->whatsapp_url ?? $user->whatsapp_url;
                $linkedin = $orderEmployee->linkedin_url ?? $user->linkedin_url;
                $facebook = $orderEmployee->facebook_url ?? $user->facebook_url;
                $twitter = $orderEmployee->twitter_url ?? $user->twitter_url;
                $youtube = $orderEmployee->youtube_url ?? $user->youtube_url;
                $deezer = $orderEmployee->deezer_url ?? $user->deezer_url;
                $spotify = $orderEmployee->spotify_url ?? $user->spotify_url;
                $tiktok = $orderEmployee->tiktok_url ?? $user->tiktok_url ?? null;
                $threads = $orderEmployee->threads_url ?? $user->threads_url ?? null;
            } elseif ($order) {
                $whatsapp = $order->whatsapp_url ?? $user->whatsapp_url;
                $linkedin = $order->linkedin_url ?? $user->linkedin_url;
                $facebook = $order->facebook_url ?? $user->facebook_url;
                $twitter = $order->twitter_url ?? $user->twitter_url;
                $youtube = $order->youtube_url ?? $user->youtube_url;
                $deezer = $order->deezer_url ?? $user->deezer_url;
                $spotify = $order->spotify_url ?? $user->spotify_url;
                $tiktok = $order->tiktok_url ?? $user->tiktok_url ?? null;
                $threads = $order->threads_url ?? $user->threads_url ?? null;
            } else {
                $whatsapp = $user->whatsapp_url;
                $linkedin = $user->linkedin_url;
                $facebook = $user->facebook_url;
                $twitter = $user->twitter_url;
                $youtube = $user->youtube_url;
                $deezer = $user->deezer_url;
                $spotify = $user->spotify_url;
                $tiktok = $user->tiktok_url ?? null;
                $threads = $user->threads_url ?? null;
            }
            
            // Vérifier si les rendez-vous sont activés
            $appointmentEnabled = isset($appointmentSetting) && $appointmentSetting && $appointmentSetting->is_enabled;
        @endphp
        @php
            // Vérifier si les rendez-vous sont activés pour cette commande spécifique
            // IMPORTANT: Ne PAS utiliser de fallback - l'icône ne s'affiche QUE si activée pour cette commande
            $appointmentEnabled = isset($appointmentSetting) && $appointmentSetting && $appointmentSetting->is_enabled;
            
            // L'échange de contacts est toujours activé
            $shareContactEnabled = true;
            
            // Afficher la section seulement s'il y a au moins une icône, le calendrier ou l'échange
            $hasSocialIcons = $whatsapp || $linkedin || $facebook || $twitter || $youtube || $deezer || $spotify || $tiktok || $threads;
        @endphp
        @php
            // Construire les tableaux d'icônes pour répartition équilibrée
            $allIcons = [];
            if ($whatsapp) $allIcons[] = ['type' => 'whatsapp', 'url' => $whatsapp, 'title' => 'WhatsApp'];
            if ($linkedin) $allIcons[] = ['type' => 'linkedin', 'url' => $linkedin, 'title' => 'LinkedIn'];
            if ($facebook) $allIcons[] = ['type' => 'facebook', 'url' => $facebook, 'title' => 'Facebook'];
            if ($twitter) $allIcons[] = ['type' => 'twitter', 'url' => $twitter, 'title' => 'Twitter / X'];
            if ($youtube) $allIcons[] = ['type' => 'youtube', 'url' => $youtube, 'title' => 'YouTube'];
            if ($deezer) $allIcons[] = ['type' => 'deezer', 'url' => $deezer, 'title' => 'Deezer'];
            if ($spotify) $allIcons[] = ['type' => 'spotify', 'url' => $spotify, 'title' => 'Spotify'];
            if (!empty($tiktok)) $allIcons[] = ['type' => 'tiktok', 'url' => $tiktok, 'title' => 'TikTok'];
            if (!empty($threads)) $allIcons[] = ['type' => 'threads', 'url' => $threads, 'title' => 'Threads'];
            
            // Répartir équitablement de chaque côté de l'icône d'échange
            $midPoint = (int) ceil(count($allIcons) / 2);
            $leftIcons = array_slice($allIcons, 0, $midPoint);
            $rightIcons = array_slice($allIcons, $midPoint);
            
            // Si rendez-vous est activé, l'ajouter au début du groupe de droite
            // pour qu'il soit juste à droite de l'icône d'échange
            
            // Compter le nombre total d'icônes (sociales + centrales)
            $totalIconsCount = count($allIcons);
            if ($appointmentEnabled) $totalIconsCount++;
            if ($shareContactEnabled) $totalIconsCount++;
            
            // Déterminer la classe CSS selon le nombre d'icônes
            $iconsCountClass = '';
            if ($totalIconsCount >= 9) {
                $iconsCountClass = 'icons-count-very-high'; // 9+ icônes
            } elseif ($totalIconsCount >= 7) {
                $iconsCountClass = 'icons-count-high'; // 7-8 icônes
            }
        @endphp
        @if($hasSocialIcons || $appointmentEnabled || $shareContactEnabled)
            <hr class="border-gray-600 mb-4">
            <div class="social-icons-container {{ $iconsCountClass }}">
                {{-- Groupe GAUCHE des icônes --}}
                <div class="social-icons-group left">
                    @foreach($leftIcons as $icon)
                        @include('profile.partials.social-icon', ['icon' => $icon])
                    @endforeach
                </div>
                
                {{-- Icône CENTRALE : Échange (toujours au centre) --}}
                @if($shareContactEnabled)
                <div class="social-icons-center">
                    <button
                        onclick="openShareContactModal()"
                        class="social-icon exchange text-gray-400 relative cursor-pointer"
                        title="Échanger mon contact"
                    >
                        <svg fill="currentColor" viewBox="0 0 24 24">
                            <path d="M16 17.01V10h-2v7.01h-3L15 21l4-3.99h-3zM9 3L5 6.99h3V14h2V6.99h3L9 3z"/>
                        </svg>
                        <span class="absolute -top-1 -right-1 w-3 h-3 bg-emerald-500 rounded-full animate-pulse"></span>
                    </button>
                </div>
                @endif
                
                {{-- Groupe DROITE des icônes (Rendez-vous en premier si activé) --}}
                <div class="social-icons-group right">
                    {{-- Bouton Prendre Rendez-vous (juste à droite de l'échange) --}}
                    @if($appointmentEnabled)
                    <button
                        onclick="openBookingModal()"
                        class="social-icon calendar text-gray-400 relative cursor-pointer"
                        title="Prendre rendez-vous"
                    >
                        <svg fill="currentColor" viewBox="0 0 24 24">
                            <path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zm0-12H5V6h14v2zM9 14H7v-2h2v2zm4 0h-2v-2h2v2zm4 0h-2v-2h2v2zm-8 4H7v-2h2v2zm4 0h-2v-2h2v2zm4 0h-2v-2h2v2z"/>
                        </svg>
                        <span class="absolute -top-1 -right-1 w-3 h-3 bg-sky-500 rounded-full animate-pulse"></span>
                    </button>
                    @endif
                    
                    {{-- Icônes sociales du groupe de droite --}}
                    @foreach($rightIcons as $icon)
                        @include('profile.partials.social-icon', ['icon' => $icon])
                    @endforeach
                </div>
            </div>
        @endif
    </div>

{{-- Modal de Réservation de Rendez-vous --}}
@if($appointmentEnabled)
<div id="bookingModal" class="fixed inset-0 bg-black bg-opacity-60 hidden items-center justify-center z-50 p-2 sm:p-4 backdrop-blur-sm overflow-y-auto">
    <div class="modal-content bg-gradient-to-br from-slate-800 to-slate-900 rounded-2xl shadow-2xl w-full max-w-lg border border-slate-700 my-auto">
        <!-- Header -->
        <div class="relative p-4 sm:p-6 border-b border-slate-700/50 bg-gradient-to-r from-sky-500/10 to-indigo-500/10 flex-shrink-0">
            <button onclick="closeBookingModal()" class="absolute top-4 right-4 w-8 h-8 flex items-center justify-center rounded-full bg-slate-700/50 hover:bg-red-500/80 text-slate-400 hover:text-white transition-all">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-sky-500 to-indigo-500 flex items-center justify-center shadow-lg">
                    <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zm0-12H5V6h14v2z"/></svg>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-white">Prendre Rendez-vous</h2>
                    <p class="text-sm text-slate-400">avec {{ $displayName }}</p>
                </div>
            </div>
            <!-- Stepper -->
            <div class="flex items-center justify-center gap-2 mt-6">
                <div id="step1Indicator" class="w-8 h-1 rounded-full bg-sky-500 transition-all"></div>
                <div id="step2Indicator" class="w-8 h-1 rounded-full bg-slate-600 transition-all"></div>
                <div id="step3Indicator" class="w-8 h-1 rounded-full bg-slate-600 transition-all"></div>
            </div>
        </div>

        <!-- Content -->
        <div class="modal-content-body p-4 sm:p-6">
            <!-- Étape 1: Choix de la date -->
            <div id="bookingStep1" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-3">📅 Choisissez une date</label>
                    
                    <!-- Chargement des dates disponibles -->
                    <div id="loadingAvailableDates" class="w-full bg-slate-700/50 border border-slate-600 rounded-xl py-8 flex flex-col items-center justify-center gap-3">
                        <div class="relative">
                            <div class="w-12 h-12 rounded-full border-4 border-slate-700"></div>
                            <div class="absolute top-0 left-0 w-12 h-12 rounded-full border-4 border-sky-500 border-t-transparent animate-spin"></div>
                        </div>
                        <span class="text-sm text-slate-400">Chargement des dates disponibles...</span>
                    </div>
                    
                    <!-- Grille de dates visuelle -->
                    <div id="datesGridContainer" class="hidden space-y-6"></div>
                    
                    <!-- Message si aucune date disponible -->
                    <div id="noDatesAvailable" class="hidden w-full bg-slate-700/50 border border-slate-600 rounded-xl py-12 flex flex-col items-center justify-center gap-3">
                        <div class="w-16 h-16 rounded-full bg-slate-700/50 flex items-center justify-center">
                            <svg class="w-8 h-8 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <p class="text-slate-400 text-sm text-center px-4">Aucune date disponible pour le moment</p>
                    </div>
                </div>
                <div id="loadingSlots" class="hidden flex items-center justify-center py-8">
                    <div class="animate-spin rounded-full h-10 w-10 border-b-2 border-sky-500"></div>
                </div>
                <div id="noDateSelected" class="hidden text-center py-8">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-slate-700/50 flex items-center justify-center">
                        <svg class="w-8 h-8 text-slate-500" fill="currentColor" viewBox="0 0 24 24"><path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10z"/></svg>
                    </div>
                    <p class="text-slate-400">Sélectionnez une date ci-dessus pour voir les créneaux disponibles</p>
                </div>
            </div>

            <!-- Étape 2: Choix du créneau -->
            <div id="bookingStep2" class="hidden space-y-4">
                <div class="flex items-center justify-between mb-2">
                    <button onclick="goToBookingStep(1)" class="flex items-center gap-1 text-sm text-slate-400 hover:text-sky-400 transition-colors">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg> Modifier la date
                    </button>
                    <span id="selectedDateDisplay" class="text-sm text-sky-400 font-medium"></span>
                </div>
                <div id="slotsContainer" class="grid grid-cols-2 sm:grid-cols-3 gap-3"></div>
                <div id="noSlots" class="hidden text-center py-8">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-yellow-500/20 flex items-center justify-center">
                        <svg class="w-8 h-8 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </div>
                    <p class="text-yellow-400 font-medium mb-1">Il n'y a pas de Rendez-vous disponible pour cette date</p>
                    <p class="text-slate-400 text-sm">Veuillez essayer une autre date.</p>
                </div>
            </div>

            <!-- Étape 3: Formulaire -->
            <div id="bookingStep3" class="hidden space-y-4">
                <button onclick="goToBookingStep(2)" class="flex items-center gap-1 text-sm text-slate-400 hover:text-sky-400 transition-colors mb-4">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg> Modifier le créneau
                </button>
                <!-- Récapitulatif -->
                <div class="bg-gradient-to-r from-sky-500/10 to-indigo-500/10 border border-sky-500/30 rounded-xl p-4 mb-4">
                    <p class="text-sm text-slate-300">
                        <span class="text-sky-400 font-medium">📅 Rendez-vous avec {{ $displayName }}</span><br>
                        Le <span id="recapDate" class="font-bold text-white"></span>
                        à <span id="recapTime" class="font-bold text-white"></span>
                        <span id="recapDuration" class="text-slate-400"></span>
                    </p>
                </div>
                <!-- Formulaire -->
                <form id="bookingForm" onsubmit="submitBooking(event)">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-1">Votre nom *</label>
                            <input type="text" id="visitorName" required class="w-full bg-slate-700/50 border border-slate-600 rounded-xl py-2.5 px-4 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-sky-500" placeholder="Jean Dupont" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-1">Votre email *</label>
                            <input type="email" id="visitorEmail" required class="w-full bg-slate-700/50 border border-slate-600 rounded-xl py-2.5 px-4 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-sky-500" placeholder="jean@example.com" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-1">Téléphone <span class="text-slate-500">(optionnel)</span></label>
                            <input type="tel" id="visitorPhone" class="w-full bg-slate-700/50 border border-slate-600 rounded-xl py-2.5 px-4 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-sky-500" placeholder="+33 6 12 34 56 78" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-1">Message <span class="text-slate-500">(optionnel)</span></label>
                            <textarea id="visitorMessage" rows="2" class="w-full bg-slate-700/50 border border-slate-600 rounded-xl py-2.5 px-4 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-sky-500 resize-none" placeholder="Précisez le sujet de votre rendez-vous..."></textarea>
                        </div>
                        <div id="submitError" class="hidden bg-red-500/20 border border-red-500/50 rounded-xl p-4 text-red-400 text-sm"></div>
                        <button type="submit" id="submitBtn" class="w-full bg-gradient-to-r from-sky-500 to-indigo-500 hover:from-sky-600 hover:to-indigo-600 disabled:from-slate-600 disabled:to-slate-600 text-white py-3 px-6 rounded-xl font-semibold transition-all flex items-center justify-center gap-2 shadow-lg shadow-sky-500/25 disabled:shadow-none">
                            <span id="submitBtnText">✓ Confirmer le rendez-vous</span>
                            <span id="submitBtnLoading" class="hidden"><svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Confirmation...</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Étape 4: Succès -->
            <div id="bookingStep4" class="hidden text-center py-8">
                <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-gradient-to-br from-green-400 to-emerald-500 flex items-center justify-center shadow-lg shadow-green-500/30">
                    <svg class="w-10 h-10 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                </div>
                <h3 class="text-2xl font-bold text-white mb-2">Rendez-vous confirmé !</h3>
                <p class="text-slate-400 mb-6">
                    Votre rendez-vous avec <span class="text-sky-400">{{ $displayName }}</span> a été enregistré.<br>
                    Un email de confirmation vous sera envoyé.
                </p>
                <div id="successRecap" class="bg-slate-700/30 rounded-xl p-4 text-left mb-6"></div>
                <button onclick="closeBookingModal()" class="bg-slate-700 hover:bg-slate-600 text-white py-2 px-6 rounded-xl font-medium transition-colors">
                    Fermer
                </button>
            </div>
        </div>

        <!-- Footer avec bouton Continuer -->
        <div id="bookingFooter" class="p-4 sm:p-6 border-t border-slate-700/50 bg-slate-800/50 flex-shrink-0">
            <button id="continueBtn" onclick="goToNextBookingStep()" class="hidden w-full bg-sky-500 hover:bg-sky-600 text-white py-3 px-4 sm:px-6 rounded-xl font-semibold transition-colors flex items-center justify-center gap-2 text-sm sm:text-base">
                <span id="continueBtnText">Choisir un créneau</span>
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
            </button>
        </div>
    </div>
</div>

<script>
// ========== HELPER FUNCTIONS ==========
function getCookie(name) {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) return parts.pop().split(';').shift();
    return null;
}

// ========== BOOKING MODAL ==========
const userId = {{ $user->id }};
const orderId = @if(isset($appointmentOrderId) && $appointmentOrderId){{ $appointmentOrderId }}@else null @endif;
const ownerName = "{{ $displayName }}";
const apiBaseUrl = "{{ config('app.url') }}";

console.log('[PublicProfile Blade] Variables de rendez-vous:', {
    userId: userId,
    orderId: orderId,
    appointmentOrderId: @json($appointmentOrderId ?? null),
    appointmentSetting_enabled: @json($appointmentSetting->is_enabled ?? null),
    has_order: @json(isset($order) && $order),
    order_id_from_order: @json($order->id ?? null),
    has_orderEmployee: @json(isset($orderEmployee) && $orderEmployee),
    order_id_from_orderEmployee: @json($orderEmployee->order_id ?? null)
});

let currentBookingStep = 1;
let selectedDate = '';
let selectedSlot = null;
let availableSlots = [];
let availableDates = [];

// Charger les dates disponibles au chargement du modal
async function loadAvailableDates() {
    const loadingDiv = document.getElementById('loadingAvailableDates');
    const datesGridContainer = document.getElementById('datesGridContainer');
    const noDatesDiv = document.getElementById('noDatesAvailable');
    
    loadingDiv.classList.remove('hidden');
    datesGridContainer.classList.add('hidden');
    noDatesDiv.classList.add('hidden');
    
    try {
        const params = new URLSearchParams({ days_ahead: 60 });
        if (orderId) {
            params.append('order_id', orderId);
        }
        
        console.log('[BookingModal] Chargement des dates disponibles:', { userId, orderId, params: params.toString() });
        
        const response = await fetch(`${apiBaseUrl}/api/user/${userId}/available-dates?${params.toString()}`);
        const data = await response.json();
        
        console.log('[BookingModal] Dates disponibles reçues:', data);
        
        availableDates = data.available_dates || [];
        
        if (availableDates.length > 0) {
            // Grouper les dates par mois
            const groupedByMonth = {};
            availableDates.forEach(dateInfo => {
                const dateObj = new Date(dateInfo.date);
                const monthKey = dateObj.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
                const capitalizedMonth = monthKey.charAt(0).toUpperCase() + monthKey.slice(1);
                
                if (!groupedByMonth[capitalizedMonth]) {
                    groupedByMonth[capitalizedMonth] = [];
                }
                groupedByMonth[capitalizedMonth].push(dateInfo);
            });
            
            // Trier les dates dans chaque mois
            Object.keys(groupedByMonth).forEach(month => {
                groupedByMonth[month].sort((a, b) => {
                    return new Date(a.date) - new Date(b.date);
                });
            });
            
            // Créer la grille de dates
            datesGridContainer.innerHTML = '';
            
            Object.keys(groupedByMonth).forEach(monthKey => {
                // En-tête du mois
                const monthHeader = document.createElement('div');
                monthHeader.className = 'flex items-center gap-2 px-2 mb-3';
                monthHeader.innerHTML = `
                    <div class="h-px flex-1 bg-gradient-to-r from-transparent via-slate-600 to-transparent"></div>
                    <h3 class="text-sm font-semibold text-sky-400 px-3 py-1 bg-slate-800/50 rounded-lg">${monthKey}</h3>
                    <div class="h-px flex-1 bg-gradient-to-r from-transparent via-slate-600 to-transparent"></div>
                `;
                datesGridContainer.appendChild(monthHeader);
                
                // Grille de dates
                const datesGrid = document.createElement('div');
                datesGrid.className = 'grid grid-cols-2 sm:grid-cols-3 gap-2 mb-6';
                
                groupedByMonth[monthKey].forEach(dateInfo => {
                    const dateObj = new Date(dateInfo.date);
                    const dayName = dateObj.toLocaleDateString('fr-FR', { weekday: 'short' });
                    const dayNumber = dateObj.getDate();
                    const monthName = dateObj.toLocaleDateString('fr-FR', { month: 'short' });
                    
                    const dateButton = document.createElement('button');
                    dateButton.className = 'group relative p-3 rounded-xl border-2 transition-all duration-200 text-left overflow-hidden bg-slate-700/30 border-slate-600 hover:border-sky-400/50 hover:bg-slate-700/50';
                    dateButton.style.transform = 'scale(1)';
                    dateButton.dataset.date = dateInfo.date;
                    dateButton.innerHTML = `
                        <div class="absolute inset-0 bg-gradient-to-br from-white/0 via-white/0 to-white/0 group-hover:from-white/5 group-hover:via-white/10 group-hover:to-white/5 transition-all duration-300"></div>
                        <div class="relative z-10">
                            <div class="flex items-center gap-1.5 mb-1">
                                <span class="text-xs font-medium text-slate-400 uppercase tracking-wide">${dayName}</span>
                            </div>
                            <div class="flex items-baseline gap-1">
                                <span class="text-2xl font-bold text-white group-hover:text-sky-300 transition-colors">${dayNumber}</span>
                            </div>
                            <div class="text-xs text-slate-400 mt-0.5">${monthName}</div>
                        </div>
                    `;
                    
                    dateButton.addEventListener('click', function() {
                        // Retirer la sélection précédente
                        document.querySelectorAll('#datesGridContainer button').forEach(btn => {
                            btn.classList.remove('bg-gradient-to-br', 'from-sky-500/20', 'to-indigo-500/20', 'border-sky-500', 'shadow-lg', 'shadow-sky-500/20');
                            btn.classList.add('bg-slate-700/30', 'border-slate-600');
                            btn.style.transform = 'scale(1)';
                            
                            // Retirer l'icône de vérification (chercher dans la structure HTML)
                            const relativeDiv = btn.querySelector('.relative.z-10');
                            if (relativeDiv) {
                                const dayNameDiv = relativeDiv.querySelector('div:first-child');
                                if (dayNameDiv) {
                                    const svgIcon = dayNameDiv.querySelector('svg');
                                    if (svgIcon && svgIcon.getAttribute('viewBox') === '0 0 20 20') {
                                        svgIcon.remove();
                                    }
                                }
                            }
                            
                            // Retirer l'indicateur de sélection
                            const indicators = btn.querySelectorAll('.absolute');
                            indicators.forEach(ind => {
                                if (ind.classList.contains('top-2') && ind.classList.contains('right-2') && ind.classList.contains('rounded-full')) {
                                    ind.remove();
                                }
                            });
                            
                            // Réinitialiser le numéro du jour
                            const dayNumberSpan = btn.querySelector('[class*="text-2xl"]');
                            if (dayNumberSpan) {
                                dayNumberSpan.classList.remove('text-sky-400');
                                dayNumberSpan.classList.add('text-white');
                            }
                        });
                        
                        // Ajouter la sélection à la date cliquée
                        this.classList.remove('bg-slate-700/30', 'border-slate-600');
                        this.classList.add('bg-gradient-to-br', 'from-sky-500/20', 'to-indigo-500/20', 'border-sky-500', 'shadow-lg', 'shadow-sky-500/20');
                        this.style.transform = 'scale(1.05)';
                        
                        // Ajouter l'icône de vérification
                        const relativeDiv = this.querySelector('.relative.z-10');
                        if (relativeDiv) {
                            const dayNameDiv = relativeDiv.querySelector('div:first-child');
                            if (dayNameDiv) {
                                // Vérifier si l'icône existe déjà
                                const existingSvg = dayNameDiv.querySelector('svg[viewBox="0 0 20 20"]');
                                if (!existingSvg) {
                                    const checkIcon = document.createElement('svg');
                                    checkIcon.className = 'w-3 h-3 text-sky-400';
                                    checkIcon.setAttribute('fill', 'currentColor');
                                    checkIcon.setAttribute('viewBox', '0 0 20 20');
                                    checkIcon.innerHTML = '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />';
                                    dayNameDiv.appendChild(checkIcon);
                                }
                            }
                        }
                        
                        // Ajouter l'indicateur de sélection
                        const existingIndicator = this.querySelector('.absolute.top-2.right-2.rounded-full');
                        if (!existingIndicator) {
                            const indicator = document.createElement('div');
                            indicator.className = 'absolute top-2 right-2 w-2 h-2 rounded-full bg-sky-400 shadow-lg shadow-sky-400/50';
                            indicator.style.animation = 'pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite';
                            this.appendChild(indicator);
                        }
                        
                        // Mettre à jour le numéro du jour en couleur
                        const dayNumberSpan = this.querySelector('[class*="text-2xl"]');
                        if (dayNumberSpan) {
                            dayNumberSpan.classList.remove('text-white');
                            dayNumberSpan.classList.add('text-sky-400');
                        }
                        
                        // Sélectionner la date et charger les créneaux
                        selectedDate = dateInfo.date;
                        document.getElementById('noDateSelected').classList.add('hidden');
                        loadSlotsForDate(dateInfo.date);
                    });
                    
                    datesGrid.appendChild(dateButton);
                });
                
                datesGridContainer.appendChild(datesGrid);
            });
            
            loadingDiv.classList.add('hidden');
            datesGridContainer.classList.remove('hidden');
        } else {
            loadingDiv.classList.add('hidden');
            noDatesDiv.classList.remove('hidden');
        }
    } catch (error) {
        console.error('[BookingModal] Erreur lors du chargement des dates disponibles:', error);
        loadingDiv.classList.add('hidden');
        noDatesDiv.classList.add('hidden');
        noDatesDiv.classList.remove('hidden');
        availableDates = [];
    }
}

function openBookingModal() {
    resetBookingModal();
    document.getElementById('bookingModal').classList.remove('hidden');
    document.getElementById('bookingModal').classList.add('flex');
    // Charger les dates disponibles
    loadAvailableDates();
}

function closeBookingModal() {
    document.getElementById('bookingModal').classList.add('hidden');
    document.getElementById('bookingModal').classList.remove('flex');
}

function resetBookingModal() {
    currentBookingStep = 1;
    selectedDate = '';
    selectedSlot = null;
    availableSlots = [];
    
    // Réinitialiser la sélection visuelle des dates
    document.querySelectorAll('#datesGridContainer button').forEach(btn => {
        btn.classList.remove('bg-gradient-to-br', 'from-sky-500/20', 'to-indigo-500/20', 'border-sky-500', 'shadow-lg', 'shadow-sky-500/20');
        btn.classList.add('bg-slate-700/30', 'border-slate-600');
        btn.style.transform = 'scale(1)';
        
        // Retirer l'icône de vérification (chercher dans la structure HTML)
        const relativeDiv = btn.querySelector('.relative.z-10');
        if (relativeDiv) {
            const dayNameDiv = relativeDiv.querySelector('div:first-child');
            if (dayNameDiv) {
                const svgIcon = dayNameDiv.querySelector('svg');
                if (svgIcon && svgIcon.getAttribute('viewBox') === '0 0 20 20') {
                    svgIcon.remove();
                }
            }
        }
        
        // Retirer l'indicateur de sélection
        const indicators = btn.querySelectorAll('.absolute');
        indicators.forEach(ind => {
            if (ind.classList.contains('top-2') && ind.classList.contains('right-2') && ind.classList.contains('rounded-full')) {
                ind.remove();
            }
        });
        
        // Réinitialiser le numéro du jour
        const dayNumberSpan = btn.querySelector('[class*="text-2xl"]');
        if (dayNumberSpan) {
            dayNumberSpan.classList.remove('text-sky-400');
            dayNumberSpan.classList.add('text-white');
        }
    });
    
    document.getElementById('visitorName').value = '';
    document.getElementById('visitorEmail').value = '';
    document.getElementById('visitorPhone').value = '';
    document.getElementById('visitorMessage').value = '';
    document.getElementById('submitError').classList.add('hidden');
    showBookingStep(1);
}

function showBookingStep(step) {
    currentBookingStep = step;
    document.getElementById('bookingStep1').classList.add('hidden');
    document.getElementById('bookingStep2').classList.add('hidden');
    document.getElementById('bookingStep3').classList.add('hidden');
    document.getElementById('bookingStep4').classList.add('hidden');
    document.getElementById('bookingStep' + step).classList.remove('hidden');
    document.getElementById('step1Indicator').className = step >= 1 ? 'w-8 h-1 rounded-full bg-sky-500 transition-all' : 'w-8 h-1 rounded-full bg-slate-600 transition-all';
    document.getElementById('step2Indicator').className = step >= 2 ? 'w-8 h-1 rounded-full bg-sky-500 transition-all' : 'w-8 h-1 rounded-full bg-slate-600 transition-all';
    document.getElementById('step3Indicator').className = step >= 3 ? 'w-8 h-1 rounded-full bg-sky-500 transition-all' : 'w-8 h-1 rounded-full bg-slate-600 transition-all';
    updateBookingFooter();
}

function goToBookingStep(step) { showBookingStep(step); }

function goToNextBookingStep() {
    if (currentBookingStep === 1 && selectedDate && availableSlots.length > 0) {
        showBookingStep(2);
    } else if (currentBookingStep === 2 && selectedSlot) {
        showBookingStep(3);
        updateRecap();
    }
}

function updateBookingFooter() {
    const continueBtn = document.getElementById('continueBtn');
    const footer = document.getElementById('bookingFooter');
    if (currentBookingStep === 1 && selectedDate && availableSlots.length > 0) {
        continueBtn.classList.remove('hidden');
        continueBtn.classList.add('flex');
        document.getElementById('continueBtnText').textContent = 'Choisir un créneau';
        footer.classList.remove('hidden');
    } else if (currentBookingStep === 2 && selectedSlot) {
        continueBtn.classList.remove('hidden');
        continueBtn.classList.add('flex');
        document.getElementById('continueBtnText').textContent = 'Continuer';
        footer.classList.remove('hidden');
    } else if (currentBookingStep >= 3) {
        footer.classList.add('hidden');
    } else {
        continueBtn.classList.add('hidden');
        continueBtn.classList.remove('flex');
    }
}

// Fonction pour charger les créneaux pour une date donnée
async function loadSlotsForDate(date) {
    selectedSlot = null;
    if (!date) {
        document.getElementById('noDateSelected').classList.remove('hidden');
        updateBookingFooter();
        return;
    }
    document.getElementById('noDateSelected').classList.add('hidden');
    document.getElementById('loadingSlots').classList.remove('hidden');
    try {
        let url = `${apiBaseUrl}/api/user/${userId}/slots?date=${date}`;
        if (orderId) url += `&order_id=${orderId}`;
        console.log('[BookingModal] Chargement des créneaux pour la date:', date);
        const response = await fetch(url);
        const data = await response.json();
        console.log('[BookingModal] Créneaux reçus:', data);
        availableSlots = data.available_slots || [];
        document.getElementById('loadingSlots').classList.add('hidden');
        // Toujours passer à l'étape 2 pour afficher les créneaux ou le message "aucun créneau"
        showBookingStep(2);
        renderSlots();
    } catch (error) {
        console.error('[BookingModal] Error fetching slots:', error);
        document.getElementById('loadingSlots').classList.add('hidden');
        availableSlots = [];
        // Passer à l'étape 2 pour afficher le message d'erreur
        showBookingStep(2);
        renderSlots();
    }
}

function renderSlots() {
    const container = document.getElementById('slotsContainer');
    const noSlots = document.getElementById('noSlots');
    document.getElementById('selectedDateDisplay').textContent = formatDisplayDate(selectedDate);
    if (availableSlots.length === 0) {
        container.innerHTML = '';
        noSlots.classList.remove('hidden');
        return;
    }
    noSlots.classList.add('hidden');
    container.innerHTML = availableSlots.map((slot, index) => `
        <button type="button" onclick="selectSlot(${index})" class="slot-btn p-3 rounded-xl border-2 transition-all text-center ${selectedSlot && selectedSlot.start === slot.start ? 'bg-sky-500/20 border-sky-500 text-sky-400' : 'bg-slate-700/30 border-slate-600 text-slate-300 hover:border-sky-400 hover:text-sky-400'}">
            <div class="font-bold text-lg">${slot.start}</div>
            <div class="text-xs opacity-70">${formatDuration(slot.duration)}</div>
        </button>
    `).join('');
    updateBookingFooter();
}

function selectSlot(index) {
    selectedSlot = availableSlots[index];
    renderSlots();
    updateBookingFooter();
}

function updateRecap() {
    document.getElementById('recapDate').textContent = formatDisplayDate(selectedDate);
    document.getElementById('recapTime').textContent = selectedSlot.start;
    document.getElementById('recapDuration').textContent = `(${formatDuration(selectedSlot.duration)})`;
}

async function submitBooking(event) {
    event.preventDefault();
    const submitBtn = document.getElementById('submitBtn');
    const submitBtnText = document.getElementById('submitBtnText');
    const submitBtnLoading = document.getElementById('submitBtnLoading');
    const errorDiv = document.getElementById('submitError');
    submitBtn.disabled = true;
    submitBtnText.classList.add('hidden');
    submitBtnLoading.classList.remove('hidden');
    errorDiv.classList.add('hidden');
    const payload = {
        visitor_name: document.getElementById('visitorName').value,
        visitor_email: document.getElementById('visitorEmail').value,
        visitor_phone: document.getElementById('visitorPhone').value || null,
        message: document.getElementById('visitorMessage').value || null,
        date: selectedDate,
        start_time: selectedSlot.start
    };
    if (orderId) payload.order_id = orderId;
    
    // Récupérer le token CSRF depuis la meta tag ou le cookie XSRF-TOKEN
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const xsrfToken = getCookie('XSRF-TOKEN');
    
    try {
        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        };
        
        // Ajouter le token CSRF (priorité à la meta tag, sinon cookie XSRF-TOKEN)
        if (csrfToken) {
            headers['X-CSRF-TOKEN'] = csrfToken;
        } else if (xsrfToken) {
            headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrfToken);
        }
        
        const response = await fetch(`${apiBaseUrl}/api/user/${userId}/appointments`, {
            method: 'POST',
            headers: headers,
            credentials: 'same-origin', // Inclure les cookies (pour XSRF-TOKEN)
            body: JSON.stringify(payload)
        });
        const data = await response.json();
        if (!response.ok) {
            if (response.status === 409) {
                errorDiv.textContent = 'Ce créneau vient d\'être réservé. Veuillez en choisir un autre.';
                errorDiv.classList.remove('hidden');
                showBookingStep(2);
                document.getElementById('loadingSlots').classList.remove('hidden');
                let url = `${apiBaseUrl}/api/user/${userId}/slots?date=${selectedDate}`;
                if (orderId) url += `&order_id=${orderId}`;
                const slotsResponse = await fetch(url);
                const slotsData = await slotsResponse.json();
                availableSlots = slotsData.available_slots || [];
                document.getElementById('loadingSlots').classList.add('hidden');
                renderSlots();
            } else {
                throw new Error(data.message || 'Une erreur est survenue');
            }
        } else {
            document.getElementById('successRecap').innerHTML = `
                <p class="text-sm text-slate-300">
                    <span class="text-slate-500">📅 Date :</span> ${formatDisplayDate(selectedDate)}<br>
                    <span class="text-slate-500">⏰ Heure :</span> ${selectedSlot.start} - ${selectedSlot.end || ''}
                </p>
            `;
            showBookingStep(4);
        }
    } catch (error) {
        console.error('Error submitting booking:', error);
        errorDiv.textContent = error.message || 'Une erreur est survenue. Veuillez réessayer.';
        errorDiv.classList.remove('hidden');
    } finally {
        submitBtn.disabled = false;
        submitBtnText.classList.remove('hidden');
        submitBtnLoading.classList.add('hidden');
    }
}

function formatDuration(minutes) {
    if (!minutes) return '';
    if (minutes < 60) return minutes + 'min';
    if (minutes === 60) return '1h';
    if (minutes === 90) return '1h30';
    if (minutes === 120) return '2h';
    const hours = Math.floor(minutes / 60);
    const mins = minutes % 60;
    return mins > 0 ? hours + 'h' + mins : hours + 'h';
}

function formatDisplayDate(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    const options = { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' };
    return date.toLocaleDateString('fr-FR', options);
}

// Fermer le modal en cliquant en dehors
document.addEventListener('click', function(e) {
    const bookingModal = document.getElementById('bookingModal');
    if (e.target === bookingModal) {
        closeBookingModal();
    }
});
</script>
@endif

{{-- Modal d'Échange de Contact --}}
<div id="shareContactModal" class="fixed inset-0 bg-black bg-opacity-60 hidden items-center justify-center z-50 p-2 sm:p-4 backdrop-blur-sm overflow-y-auto">
    <div class="modal-content bg-gradient-to-br from-slate-800 to-slate-900 rounded-2xl shadow-2xl w-full max-w-lg border border-slate-700 my-auto">
        <!-- Header -->
        <div class="relative p-4 sm:p-6 border-b border-slate-700/50 bg-gradient-to-r from-emerald-500/10 to-teal-500/10 flex-shrink-0">
            <button onclick="closeShareContactModal()" class="absolute top-4 right-4 w-8 h-8 flex items-center justify-center rounded-full bg-slate-700/50 hover:bg-red-500/80 text-slate-400 hover:text-white transition-all">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-500 flex items-center justify-center shadow-lg">
                    <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M16 17.01V10h-2v7.01h-3L15 21l4-3.99h-3zM9 3L5 6.99h3V14h2V6.99h3L9 3z"/></svg>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-white">Échanger mon contact</h2>
                    <p class="text-sm text-slate-400">avec {{ $displayName }}</p>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="modal-content-body p-4 sm:p-6">
            <!-- Formulaire -->
            <div id="shareContactForm" class="space-y-4">
                <p class="text-slate-400 text-sm mb-4">
                    Partagez vos coordonnées avec {{ $displayName }}. Votre navigateur peut pré-remplir automatiquement les champs.
                </p>
                
                <form id="contactForm" onsubmit="submitShareContact(event)">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-1">Prénom *</label>
                            <input 
                                type="text" 
                                id="shareFirstName" 
                                name="fname"
                                autocomplete="given-name"
                                required 
                                class="w-full bg-slate-700/50 border border-slate-600 rounded-xl py-2.5 px-4 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500" 
                                placeholder="Jean" 
                            />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-1">Nom *</label>
                            <input 
                                type="text" 
                                id="shareLastName" 
                                name="lname"
                                autocomplete="family-name"
                                required 
                                class="w-full bg-slate-700/50 border border-slate-600 rounded-xl py-2.5 px-4 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500" 
                                placeholder="Dupont" 
                            />
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-slate-300 mb-1">Email</label>
                        <input 
                            type="email" 
                            id="shareEmail" 
                            name="email"
                            autocomplete="email"
                            class="w-full bg-slate-700/50 border border-slate-600 rounded-xl py-2.5 px-4 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500" 
                            placeholder="jean.dupont@email.com" 
                        />
                    </div>
                    
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-slate-300 mb-1">Téléphone</label>
                        <input 
                            type="tel" 
                            id="sharePhone" 
                            name="phone"
                            autocomplete="tel"
                            class="w-full bg-slate-700/50 border border-slate-600 rounded-xl py-2.5 px-4 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500" 
                            placeholder="+33 6 12 34 56 78" 
                        />
                    </div>
                    
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-slate-300 mb-1">Entreprise <span class="text-slate-500">(optionnel)</span></label>
                        <input 
                            type="text" 
                            id="shareCompany" 
                            name="organization"
                            autocomplete="organization"
                            class="w-full bg-slate-700/50 border border-slate-600 rounded-xl py-2.5 px-4 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500" 
                            placeholder="Ma Société" 
                        />
                    </div>
                    
                    <p class="text-xs text-slate-500 mt-4">* Au moins un email ou un téléphone est requis</p>
                    
                    <div id="shareContactError" class="hidden mt-4 bg-red-500/20 border border-red-500/50 rounded-xl p-4 text-red-400 text-sm"></div>
                    
                    <button 
                        type="submit" 
                        id="shareSubmitBtn" 
                        class="w-full mt-6 bg-gradient-to-r from-emerald-500 to-teal-500 hover:from-emerald-600 hover:to-teal-600 disabled:from-slate-600 disabled:to-slate-600 text-white py-3 px-6 rounded-xl font-semibold transition-all flex items-center justify-center gap-2 shadow-lg shadow-emerald-500/25 disabled:shadow-none"
                    >
                        <span id="shareSubmitBtnText">
                            <svg class="w-5 h-5 inline mr-2" fill="currentColor" viewBox="0 0 24 24"><path d="M16 17.01V10h-2v7.01h-3L15 21l4-3.99h-3zM9 3L5 6.99h3V14h2V6.99h3L9 3z"/></svg>
                            Échanger
                        </span>
                        <span id="shareSubmitBtnLoading" class="hidden">
                            <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            Envoi...
                        </span>
                    </button>
                </form>
            </div>

            <!-- Succès -->
            <div id="shareContactSuccess" class="hidden text-center py-8">
                <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-gradient-to-br from-emerald-400 to-teal-500 flex items-center justify-center shadow-lg shadow-emerald-500/30">
                    <svg class="w-10 h-10 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                </div>
                <h3 class="text-2xl font-bold text-white mb-2">Contact partagé !</h3>
                <p class="text-slate-400 mb-6">
                    Vos coordonnées ont été envoyées à<br>
                    <span class="text-emerald-400 font-medium">{{ $displayName }}</span>
                </p>
                <button onclick="closeShareContactModal()" class="bg-slate-700 hover:bg-slate-600 text-white py-2 px-6 rounded-xl font-medium transition-colors">
                    Fermer
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ========== SHARE CONTACT MODAL ==========
const shareContactUserId = {{ $user->id }};
const shareContactOrderId = @if(isset($orderEmployee) && $orderEmployee && $orderEmployee->order_id){{ $orderEmployee->order_id }}@elseif(isset($order) && $order){{ $order->id }}@else null @endif;

function openShareContactModal() {
    resetShareContactModal();
    document.getElementById('shareContactModal').classList.remove('hidden');
    document.getElementById('shareContactModal').classList.add('flex');
    // Focus sur le premier champ pour déclencher l'autofill
    setTimeout(() => {
        document.getElementById('shareFirstName').focus();
    }, 100);
}

function closeShareContactModal() {
    document.getElementById('shareContactModal').classList.add('hidden');
    document.getElementById('shareContactModal').classList.remove('flex');
}

function resetShareContactModal() {
    document.getElementById('contactForm').reset();
    document.getElementById('shareContactForm').classList.remove('hidden');
    document.getElementById('shareContactSuccess').classList.add('hidden');
    document.getElementById('shareContactError').classList.add('hidden');
}

// Récupérer un cookie par son nom
function getCookieShare(name) {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) return parts.pop().split(';').shift();
    return null;
}

async function submitShareContact(event) {
    event.preventDefault();
    
    const submitBtn = document.getElementById('shareSubmitBtn');
    const submitBtnText = document.getElementById('shareSubmitBtnText');
    const submitBtnLoading = document.getElementById('shareSubmitBtnLoading');
    const errorDiv = document.getElementById('shareContactError');
    
    const firstName = document.getElementById('shareFirstName').value.trim();
    const lastName = document.getElementById('shareLastName').value.trim();
    const email = document.getElementById('shareEmail').value.trim();
    const phone = document.getElementById('sharePhone').value.trim();
    const company = document.getElementById('shareCompany').value.trim();
    
    // Validation
    if (!email && !phone) {
        errorDiv.textContent = 'Veuillez fournir au moins un email ou un numéro de téléphone.';
        errorDiv.classList.remove('hidden');
        return;
    }
    
    submitBtn.disabled = true;
    submitBtnText.classList.add('hidden');
    submitBtnLoading.classList.remove('hidden');
    errorDiv.classList.add('hidden');
    
    const payload = {
        first_name: firstName,
        last_name: lastName,
        email: email || null,
        phone: phone || null,
        company: company || null,
    };
    if (shareContactOrderId) {
        payload.order_id = shareContactOrderId;
    }
    
    // Récupérer le token CSRF
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const xsrfToken = getCookieShare('XSRF-TOKEN');
    
    try {
        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        };
        
        if (csrfToken) {
            headers['X-CSRF-TOKEN'] = csrfToken;
        } else if (xsrfToken) {
            headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrfToken);
        }
        
        console.log('Envoi du contact:', { payload, userId: shareContactUserId, headers: Object.keys(headers) });
        
        const response = await fetch(`{{ config('app.url') }}/api/user/${shareContactUserId}/share-contact`, {
            method: 'POST',
            headers: headers,
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        });
        
        console.log('Response status:', response.status);
        
        const data = await response.json();
        console.log('Response data:', data);
        
        if (!response.ok) {
            throw new Error(data.message || 'Une erreur est survenue');
        }
        
        // Succès
        document.getElementById('shareContactForm').classList.add('hidden');
        document.getElementById('shareContactSuccess').classList.remove('hidden');
        
    } catch (error) {
        console.error('Error sharing contact:', error);
        console.error('Error details:', error.message, error.stack);
        errorDiv.textContent = error.message || 'Une erreur est survenue. Veuillez réessayer.';
        errorDiv.classList.remove('hidden');
    } finally {
        submitBtn.disabled = false;
        submitBtnText.classList.remove('hidden');
        submitBtnLoading.classList.add('hidden');
    }
}

// Fermer le modal share contact en cliquant en dehors
document.addEventListener('click', function(e) {
    const shareModal = document.getElementById('shareContactModal');
    if (e.target === shareModal) {
        closeShareContactModal();
    }
});
    </script>

</body>
</html>
