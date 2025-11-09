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

    if ($avatarUrl && str_starts_with($avatarUrl, '/storage/')) {
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
    } elseif ($avatarUrl && (str_starts_with($avatarUrl, 'http://') || str_starts_with($avatarUrl, 'https://'))) {
        // URL complète (externe)
        $displayAvatar = $avatarUrl;
    } elseif ($avatarUrl) {
        // Autre format d'URL, essayer de construire l'URL complète
        $displayAvatar = url($avatarUrl);
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
    <title>{{ $displayName }} - Arcc En Ciel</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #111827; color: #d1d5db; }
        .btn { transition: all 0.2s ease-in-out; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3); }
        .social-icon { transition: all 0.2s ease-in-out; }
        .social-icon:hover { transform: scale(1.15); }
        .social-icon.whatsapp:hover { color: #25D366; }
        .social-icon.linkedin:hover { color: #0A66C2; }
        .social-icon.facebook:hover { color: #1877F2; }
        .social-icon.twitter:hover { color: #1DA1F2; }
        .social-icon.youtube:hover { color: #FF0000; }
        .social-icon.deezer:hover { color: #EF5466; }
        .social-icon.spotify:hover { color: #1DB954; }
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

            <!-- Bouton Découvrir Mon Profil (pour les comptes particuliers avec portfolio configuré) -->
            @if($portfolioConfigured)
            <a href="{{ env('VITE_APP_URL_BACKEND', 'http://localhost:8000') }}/api/portfolio/{{ $user->username }}"
               target="_blank"
               rel="noopener noreferrer"
               class="btn block w-full font-bold py-3 px-4 rounded-lg shadow-md text-white"
               style="background-color: {{ $displayServicesButtonColor }};">
                Découvrir mon Profil
            </a>
            @endif

            <!-- Bouton Découvrir Nos Services (couleur personnalisable) -->
            <!-- Pour business_admin : redirige vers sa propre page ou le site web si configuré -->
            <!-- Pour employee : redirige vers la page de son business admin ou le site web si configuré -->
            @if($companyPagePublished && $companyPageUsername)
                @php
                    // Si le site web est configuré pour être mis en avant dans le bouton, utiliser l'URL du site web
                    // Sinon, rediriger vers la page entreprise
                    if ($websiteFeaturedInServicesButton && $companyWebsiteUrl) {
                        $servicesButtonUrl = $companyWebsiteUrl;
                    } else {
                        $servicesButtonUrl = env('VITE_APP_URL_FRONTEND', 'http://localhost:5173') . '/entreprise/' . $companyPageUsername;
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
            } elseif ($order) {
                $whatsapp = $order->whatsapp_url ?? $user->whatsapp_url;
                $linkedin = $order->linkedin_url ?? $user->linkedin_url;
                $facebook = $order->facebook_url ?? $user->facebook_url;
                $twitter = $order->twitter_url ?? $user->twitter_url;
                $youtube = $order->youtube_url ?? $user->youtube_url;
                $deezer = $order->deezer_url ?? $user->deezer_url;
                $spotify = $order->spotify_url ?? $user->spotify_url;
            } else {
                $whatsapp = $user->whatsapp_url;
                $linkedin = $user->linkedin_url;
                $facebook = $user->facebook_url;
                $twitter = $user->twitter_url;
                $youtube = $user->youtube_url;
                $deezer = $user->deezer_url;
                $spotify = $user->spotify_url;
            }
        @endphp
        @if($whatsapp || $linkedin || $facebook || $twitter || $youtube || $deezer || $spotify)
            <hr class="border-gray-600 mb-6">
            <div class="flex justify-center items-center flex-wrap gap-x-5 gap-y-3">
                @if($whatsapp)
                <a href="{{ $whatsapp }}" target="_blank" rel="noopener noreferrer" class="social-icon whatsapp text-gray-400" title="WhatsApp">
                    <svg class="w-7 h-7" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/>
                    </svg>
                </a>
                @endif
                @if($linkedin)
                <a href="{{ $linkedin }}" target="_blank" rel="noopener noreferrer" class="social-icon linkedin text-gray-400" title="LinkedIn">
                    <svg class="w-7 h-7" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
                    </svg>
                </a>
                @endif
                @if($facebook)
                <a href="{{ $facebook }}" target="_blank" rel="noopener noreferrer" class="social-icon facebook text-gray-400" title="Facebook">
                    <svg class="w-7 h-7" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                    </svg>
                </a>
                @endif
                @if($twitter)
                <a href="{{ $twitter }}" target="_blank" rel="noopener noreferrer" class="social-icon twitter text-gray-400" title="Twitter / X">
                    <svg class="w-7 h-7" fill="currentColor" viewBox="0 0 300 271">
                        <path d="M236 0h46L181 115l118 156h-92.6l-72.5-94.8L60 271H14l107-123L14 0h94.9l65.5 86.6L236 0zm-16.1 244h25.5L80.4 26H53l167 218z"/>
                    </svg>
                </a>
                @endif
                @if($youtube)
                <a href="{{ $youtube }}" target="_blank" rel="noopener noreferrer" class="social-icon youtube text-gray-400" title="YouTube">
                    <svg class="w-7 h-7" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                    </svg>
                </a>
                @endif
                @if($deezer)
                <a href="{{ $deezer }}" target="_blank" rel="noopener noreferrer" class="social-icon deezer text-gray-400" title="Deezer">
                    <svg class="w-7 h-7" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M18.81 4.16v3.03h5.19V4.16h-5.19zm0 4.97v3.03h5.19V9.13h-5.19zm0 4.96v3.03h5.19v-3.03h-5.19zm-6.58-9.93v3.03h5.19V4.16h-5.19zm0 4.97v3.03h5.19V9.13h-5.19zm0 4.96v3.03h5.19v-3.03h-5.19zm0 4.97v3.03h5.19v-3.03h-5.19zM5.65 9.13v3.03h5.19V9.13H5.65zm0 4.96v3.03h5.19v-3.03H5.65zm0 4.97v3.03h5.19v-3.03H5.65zM0 14.09v3.03h4.16v-3.03H0zm0 4.97v3.03h4.16v-3.03H0z"/>
                    </svg>
                </a>
                @endif
                @if($spotify)
                <a href="{{ $spotify }}" target="_blank" rel="noopener noreferrer" class="social-icon spotify text-gray-400" title="Spotify">
                    <svg class="w-7 h-7" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"/>
                    </svg>
                </a>
                @endif
            </div>
        @endif
    </div>
</body>
</html>
