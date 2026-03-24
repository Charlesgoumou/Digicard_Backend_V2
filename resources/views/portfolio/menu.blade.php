<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu du jour - {{ $portfolio->hero_headline ?? $portfolio->name ?? $user->name }}</title>

    <link rel="icon" type="image/png" sizes="16x16" href="https://digicard.arccenciel.com/logo2-16.png" />
    <link rel="icon" type="image/png" sizes="32x32" href="https://digicard.arccenciel.com/logo2-32.png" />
    <link rel="apple-touch-icon" sizes="180x180" href="https://digicard.arccenciel.com/logo2-180.png" />

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        :root {
            --delivo-orange: #ff6b35;
            --delivo-bg: #000000;
            --delivo-card: #141414;
            --delivo-pill: #2a1410;
        }
        body { font-family: "Inter", sans-serif; background: var(--delivo-bg); color: #fff; }
        .orange-hero {
            background: linear-gradient(145deg, #ff6b35 0%, #ff8f3f 45%, #ff5520 100%);
            border-radius: 0 0 28px 28px;
        }
        @media (min-width: 768px) {
            .orange-hero { border-radius: 0 0 36px 36px; }
        }
        .scrollbar-hide {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .super-deals-track {
            display: flex;
            gap: 1rem;
            overflow-x: auto;
            scroll-snap-type: x mandatory;
            padding-bottom: 0.5rem;
            -webkit-overflow-scrolling: touch;
        }
        .super-deal-card {
            flex: 0 0 min(280px, 85vw);
            scroll-snap-align: start;
        }
        @media (min-width: 1024px) {
            .super-deal-card { flex: 0 0 300px; }
        }
        .category-track {
            display: flex;
            gap: 0.75rem;
            overflow-x: auto;
            padding-bottom: 0.25rem;
            -webkit-overflow-scrolling: touch;
        }
        .pill-category {
            flex-shrink: 0;
            border-radius: 9999px;
            padding: 0.6rem 1.1rem;
            font-size: 0.875rem;
            font-weight: 600;
            white-space: nowrap;
            transition: background 0.2s, color 0.2s;
        }
        .pill-category--inactive {
            background: var(--delivo-pill);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.08);
        }
        .pill-category--active {
            background: var(--delivo-orange);
            color: #fff;
        }
        .hot-deal-card {
            background: var(--delivo-card);
            border: 1px solid rgba(255,255,255,0.08);
        }
        .bottom-nav {
            box-shadow: 0 -8px 24px rgba(0,0,0,0.45);
        }
    </style>
</head>
<body class="antialiased">

@php
    $menu = is_array($portfolio->menu) ? $portfolio->menu : json_decode($portfolio->menu, true);
    $dishes = is_array($menu['dishes'] ?? null) ? $menu['dishes'] : [];
    $drinks = is_array($menu['drinks'] ?? null) ? $menu['drinks'] : [];

    // Image par défaut pour les cartes plat/boisson (URL absolue fiable, jamais une autre carte du menu)
    $menuCardPlaceholder = asset('images/menu-item-placeholder.svg');

    $menuImageUrl = static function (?string $url, string $placeholder): string {
        $url = is_string($url) ? trim($url) : '';
        if ($url === '') {
            return $placeholder;
        }
        if (preg_match('#^blob:#i', $url)) {
            return $placeholder;
        }
        if (str_starts_with($url, 'data:')) {
            return $url;
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        if (str_starts_with($url, '/')) {
            return url($url);
        }
        if (preg_match('#^(storage/|images/)#', $url)) {
            return asset($url);
        }
        return url('/' . ltrim($url, '/'));
    };

    $avatarUrl = $portfolio->photo_url;
    if ($avatarUrl && str_starts_with($avatarUrl, '/storage/')) {
        $avatarUrl = url($avatarUrl);
    }
    $fallbackHero = file_exists(public_path('images/ArrierePlanRestaurrant.jpg'))
        ? asset('images/ArrierePlanRestaurrant.jpg')
        : $menuCardPlaceholder;

    $availableDishes = array_values(array_filter($dishes, fn ($item) => ($item['available'] ?? true)));
    $availableDrinks = array_values(array_filter($drinks, fn ($item) => ($item['available'] ?? true)));

    $superItems = [];
    foreach (array_merge($availableDishes, $availableDrinks) as $it) {
        $superItems[] = $it;
    }
    $heroImage = $menuImageUrl($availableDishes[0]['image'] ?? null, $fallbackHero);
    if ($heroImage === $fallbackHero && !empty($availableDrinks[0]['image'])) {
        $heroImage = $menuImageUrl($availableDrinks[0]['image'], $fallbackHero);
    }

    $hashRating = static function (string $name): array {
        $h = crc32($name);
        $rating = 4.0 + ($h % 10) / 10;
        if ($rating > 4.9) {
            $rating = 4.9;
        }
        $reviews = 20 + ($h % 80);
        return [round($rating, 1), $reviews];
    };

    $distanceForIndex = static function (int $i): string {
        $km = 1.2 + ($i % 5) * 0.35;
        return number_format($km, 1, ',', '') . ' km';
    };

    $timeForIndex = static function (int $i): string {
        $starts = [15, 20, 25];
        $ends = [30, 35, 40];
        $s = $starts[$i % count($starts)];
        $e = $ends[$i % count($ends)];
        return $s . '-' . $e . ' min';
    };

    $discounts = ['10% Off', '15% Off', '10% Off'];
    $phoneHref = preg_replace('/\s+/', '', $portfolio->phone ?? '');
    $commandTel = $phoneHref !== '' ? 'tel:' . $phoneHref : '';
@endphp

<div class="min-h-screen pb-24 md:pb-10">
    {{-- Header type app (orange, coins arrondis en bas) --}}
    <header class="orange-hero relative text-white">
        <div class="absolute inset-0 bg-black/10 pointer-events-none"></div>
        <div class="relative mx-auto max-w-6xl px-4 pt-5 pb-8 md:pt-8 md:pb-10">
            <div class="flex items-start justify-between gap-3">
                {{-- Espace réservé pour équilibrer le bouton téléphone (pas de retour vers une autre page) --}}
                <span class="h-10 w-10 shrink-0" aria-hidden="true"></span>
                @php
                    $menuRestaurantName = trim((string)($portfolio->name ?? '')) !== ''
                        ? $portfolio->name
                        : ($portfolio->hero_headline ?? $user->name ?? 'Restaurant');
                    $menuAccroche = trim((string)($portfolio->bio ?? ''));
                    $menuEmplacement = trim((string)($portfolio->restaurant_location ?? ''));
                @endphp
                <div class="flex-1 min-w-0 text-center px-2">
                    <p class="text-[11px] md:text-sm font-semibold text-white/95 uppercase tracking-wide leading-snug">
                        {{ $menuRestaurantName }}
                    </p>
                    @if($menuAccroche !== '')
                        <p class="text-xs md:text-sm text-white/80 mt-1.5 px-1 leading-snug">
                            {{ $menuAccroche }}
                        </p>
                    @endif
                    @if($menuEmplacement !== '')
                        <p class="text-xs md:text-sm text-white/85 mt-1 truncate max-w-[85vw] mx-auto">
                            <i class="fas fa-location-dot text-xs opacity-90"></i>
                            {{ $menuEmplacement }}
                        </p>
                    @endif
                </div>
                <a href="{{ $commandTel !== '' ? $commandTel : '#' }}"
                   class="h-10 w-10 rounded-full bg-white/20 hover:bg-white/30 inline-flex items-center justify-center shrink-0 {{ $commandTel === '' ? 'pointer-events-none opacity-45' : '' }}"
                   aria-label="Appeler">
                    <i class="fas fa-phone"></i>
                </a>
            </div>

            <div class="mt-5 flex items-center gap-3">
                <img
                    src="{{ $avatarUrl ?? 'https://ui-avatars.com/api/?name='.urlencode($portfolio->name ?? $user->name).'&background=f97316&color=ffffff&size=128' }}"
                    alt=""
                    class="h-11 w-11 rounded-full object-cover border-2 border-white/50 shrink-0"
                />
                <h1 class="text-xl md:text-3xl font-extrabold leading-tight">
                    {{ trim((string)($portfolio->hero_headline ?? '')) !== '' ? $portfolio->hero_headline : 'Que souhaitez-vous commander aujourd’hui ?' }}
                </h1>
            </div>

            <div class="mt-4 flex items-center gap-2 rounded-full bg-black/35 px-4 py-3 border border-white/15">
                <i class="fas fa-magnifying-glass text-white/75 text-sm"></i>
                <span class="text-sm text-white/85">Rechercher un plat, une boisson…</span>
                <span class="ml-auto text-white/60"><i class="fas fa-sliders"></i></span>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 -mt-2">
        {{-- Catégories (scroll horizontal, style mobile + desktop) --}}
        <section class="pt-4" aria-label="Catégories">
            <h2 class="text-base md:text-lg font-bold text-white mb-3">Catégories</h2>
            <div class="category-track scrollbar-hide">
                <a href="#super-deals" class="pill-category pill-category--active">Tous</a>
                <a href="#dishes" class="pill-category pill-category--inactive inline-flex items-center gap-2">
                    <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-amber-500/25 text-amber-300 text-xs"><i class="fas fa-burger"></i></span>
                    Plats
                </a>
                <a href="#drinks" class="pill-category pill-category--inactive inline-flex items-center gap-2">
                    <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-rose-500/25 text-rose-200 text-xs"><i class="fas fa-mug-hot"></i></span>
                    Boissons
                </a>
            </div>
        </section>

        @if(count($superItems) > 0)
            {{-- Super Deals : carrousel horizontal (grands écrans + smartphone) --}}
            <section id="super-deals" class="mt-6">
                <div class="flex items-center justify-between gap-2 mb-3">
                    <h2 class="text-base md:text-lg font-bold">Super Deals <span aria-hidden="true">🔥</span></h2>
                    <a href="#hot-deals" class="text-sm text-white/80 hover:text-white font-medium">Voir tout</a>
                </div>
                <div class="super-deals-track scrollbar-hide">
                    @foreach($superItems as $idx => $item)
                        @php
                            $img = $menuImageUrl($item['image'] ?? null, $menuCardPlaceholder);
                            [$rating, $reviews] = $hashRating((string)($item['name'] ?? 'x'.$idx));
                            $badge = $discounts[$idx % count($discounts)];
                        @endphp
                        <article class="super-deal-card hot-deal-card rounded-2xl overflow-hidden flex flex-col shadow-xl">
                            <div class="relative aspect-[4/3] bg-neutral-900">
                                <img src="{{ $img }}" alt="{{ $item['name'] ?? 'Article' }}" class="absolute inset-0 h-full w-full object-cover" loading="lazy" onerror="this.onerror=null;this.src='{{ $menuCardPlaceholder }}'">
                                <span class="absolute top-2.5 left-2.5 rounded-md bg-[#e53935] px-2 py-1 text-[11px] font-bold text-white shadow">
                                    {{ $badge }}
                                </span>
                                <button type="button" class="favorite-btn absolute top-2.5 right-2.5 h-9 w-9 rounded-full bg-black/45 text-white flex items-center justify-center border border-white/20" aria-label="Favori">
                                    <i class="far fa-heart text-sm"></i>
                                </button>
                            </div>
                            <div class="p-4 flex-1 flex flex-col">
                                <div class="flex items-start justify-between gap-2">
                                    <h3 class="font-bold text-[15px] leading-snug">{{ $item['name'] ?? 'Article' }}</h3>
                                    <span class="text-[15px] font-extrabold text-white shrink-0 whitespace-nowrap">
                                        {{ number_format((int)($item['price'] ?? 0), 0, ',', ' ') }} GNF
                                    </span>
                                </div>
                                <div class="mt-1.5 flex items-center gap-1.5 text-xs text-neutral-400">
                                    <i class="far fa-clock"></i>
                                    <span>{{ $timeForIndex($idx) }}</span>
                                    <span class="text-neutral-600">•</span>
                                    <span>{{ $distanceForIndex($idx) }}</span>
                                </div>
                                <div class="mt-2 flex items-center gap-1.5 text-xs">
                                    <i class="fas fa-star text-[#ff6b35]"></i>
                                    <span class="text-[#ff6b35] font-semibold">{{ number_format($rating, 1, ',', '') }}</span>
                                    <span class="text-neutral-500">({{ $reviews }} avis)</span>
                                </div>
                                @if(!empty($item['description']))
                                    <p class="mt-2 text-xs text-neutral-400 line-clamp-2">{{ strip_tags($item['description']) }}</p>
                                @endif
                                @if($commandTel !== '')
                                    <a href="{{ $commandTel }}" class="mt-4 w-full rounded-full bg-[#ff6b35] hover:bg-[#ff5722] text-white text-center text-sm font-bold py-3 transition">
                                        Commander
                                    </a>
                                @else
                                    <button type="button" class="mt-4 w-full rounded-full bg-neutral-700 text-neutral-300 text-center text-sm font-bold py-3 cursor-not-allowed" disabled>
                                        Commander
                                    </button>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>

            {{-- Bannière promo (style Image 5) --}}
            <section class="mt-7 rounded-[26px] overflow-hidden bg-gradient-to-br from-[#ff6b35] to-[#ff8f3f] shadow-lg">
                <div class="p-4 md:p-5 flex flex-col sm:flex-row gap-4 items-center">
                    <div class="w-full sm:w-2/5 aspect-video sm:aspect-auto sm:h-28 rounded-2xl overflow-hidden bg-black/10 shrink-0">
                        <img src="{{ $heroImage }}" alt="" class="h-full w-full object-cover" onerror="this.onerror=null;this.src='{{ $menuCardPlaceholder }}'">
                    </div>
                    <div class="flex-1 text-center sm:text-left">
                        <p class="font-extrabold text-lg md:text-xl leading-tight">Jusqu’à 30% sur votre première commande</p>
                        @if($commandTel !== '')
                            <a href="{{ $commandTel }}" class="mt-3 inline-flex rounded-full bg-black/25 hover:bg-black/35 px-6 py-2.5 text-sm font-bold border border-white/25">
                                Commander maintenant
                            </a>
                        @endif
                    </div>
                </div>
            </section>

            {{-- Hot Deals : liste avec vignette + bouton Ajouter (Image 6) --}}
            <section id="hot-deals" class="mt-8 mb-6">
                <div class="flex items-center justify-between gap-2 mb-3">
                    <h2 class="text-base md:text-lg font-bold">Offres chaudes <span aria-hidden="true">🔥</span></h2>
                    <a href="#dishes" class="text-sm text-white/80 hover:text-white font-medium">Voir tout</a>
                </div>
                <div class="space-y-3">
                    @foreach(array_slice($superItems, 0, min(8, count($superItems))) as $idx => $item)
                        @php
                            $img = $menuImageUrl($item['image'] ?? null, $menuCardPlaceholder);
                            [$rating, $reviews] = $hashRating('hot-'.($item['name'] ?? '').$idx);
                        @endphp
                        <article class="hot-deal-card flex items-stretch gap-3 rounded-2xl p-3">
                            <div class="w-[88px] h-[88px] shrink-0 rounded-xl overflow-hidden bg-neutral-900">
                                <img src="{{ $img }}" alt="" class="h-full w-full object-cover" onerror="this.onerror=null;this.src='{{ $menuCardPlaceholder }}'">
                            </div>
                            <div class="flex-1 min-w-0 flex flex-col justify-center py-0.5">
                                <h3 class="font-bold text-[15px] leading-tight truncate">{{ $item['name'] ?? 'Article' }}</h3>
                                <p class="text-xs text-neutral-400 mt-1">
                                    {{ $timeForIndex($idx + 1) }} • {{ $distanceForIndex($idx + 2) }}
                                </p>
                                <p class="text-xs text-neutral-500 mt-0.5">
                                    {{ number_format($rating, 1, ',', '') }} ({{ $reviews }} avis)
                                </p>
                                <p class="text-sm font-extrabold mt-1">
                                    {{ number_format((int)($item['price'] ?? 0), 0, ',', ' ') }} GNF
                                </p>
                            </div>
                            @if($commandTel !== '')
                                <a href="{{ $commandTel }}" class="self-center shrink-0 rounded-full bg-[#ff6b35] hover:bg-[#ff5722] text-white text-xs font-extrabold px-5 py-2.5 min-w-[4.5rem] text-center">
                                    Ajouter
                                </a>
                            @else
                                <span class="self-center shrink-0 rounded-full bg-neutral-700 text-neutral-400 text-xs font-extrabold px-5 py-2.5 min-w-[4.5rem] text-center cursor-not-allowed">Ajouter</span>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        @if(count($dishes) > 0)
            <section id="dishes" class="mb-10">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-base md:text-lg font-bold">Nos plats</h2>
                    <span class="text-xs text-neutral-500">{{ count($dishes) }} articles</span>
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                    @foreach($dishes as $dIdx => $dish)
                        @php $dImg = $menuImageUrl($dish['image'] ?? null, $menuCardPlaceholder); @endphp
                        <article class="hot-deal-card rounded-2xl overflow-hidden flex flex-col sm:flex-row {{ ($dish['available'] ?? true) ? '' : 'opacity-60' }}">
                            <div class="h-36 sm:h-auto sm:w-36 shrink-0 bg-neutral-900">
                                <img src="{{ $dImg }}" alt="" class="h-full w-full object-cover" onerror="this.onerror=null;this.src='{{ $menuCardPlaceholder }}'">
                            </div>
                            <div class="p-4 flex-1 min-w-0">
                                <div class="flex items-start justify-between gap-2">
                                    <h3 class="font-bold">{{ $dish['name'] ?? 'Plat' }}</h3>
                                    <span class="text-[#ff6b35] font-extrabold whitespace-nowrap">{{ number_format((int)($dish['price'] ?? 0), 0, ',', ' ') }} GNF</span>
                                </div>
                                @if(!empty($dish['description']))
                                    <p class="text-sm text-neutral-400 mt-1">{{ strip_tags($dish['description']) }}</p>
                                @endif
                                @if(($dish['hasSides'] ?? false) && !empty($dish['sides']))
                                    <div class="mt-2 flex flex-wrap gap-1.5">
                                        @foreach($dish['sides'] as $side)
                                            <span class="text-[11px] bg-white/10 rounded-full px-2 py-0.5">{{ $side }}</span>
                                        @endforeach
                                    </div>
                                @endif
                                <p class="mt-2 text-xs {{ ($dish['available'] ?? true) ? 'text-emerald-400' : 'text-red-400' }}">
                                    {{ ($dish['available'] ?? true) ? 'Disponible' : 'Indisponible' }}
                                </p>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        @if(count($drinks) > 0)
            <section id="drinks" class="mb-10">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-base md:text-lg font-bold">Nos boissons</h2>
                    <span class="text-xs text-neutral-500">{{ count($drinks) }} articles</span>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($drinks as $drink)
                        @php $drImg = $menuImageUrl($drink['image'] ?? null, $menuCardPlaceholder); @endphp
                        <article class="hot-deal-card rounded-2xl overflow-hidden {{ ($drink['available'] ?? true) ? '' : 'opacity-60' }}">
                            <div class="h-36 bg-neutral-900">
                                <img src="{{ $drImg }}" alt="" class="h-full w-full object-cover" onerror="this.onerror=null;this.src='{{ $menuCardPlaceholder }}'">
                            </div>
                            <div class="p-4">
                                <div class="flex items-start justify-between gap-2">
                                    <h3 class="font-bold">{{ $drink['name'] ?? 'Boisson' }}</h3>
                                    <span class="text-[#ff6b35] font-extrabold">{{ number_format((int)($drink['price'] ?? 0), 0, ',', ' ') }} GNF</span>
                                </div>
                                <p class="mt-2 text-xs {{ ($drink['available'] ?? true) ? 'text-emerald-400' : 'text-red-400' }}">
                                    {{ ($drink['available'] ?? true) ? 'Disponible' : 'Indisponible' }}
                                </p>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        @if(count($dishes) === 0 && count($drinks) === 0)
            <section class="mt-8 rounded-2xl border border-white/10 bg-white/5 p-8 text-center">
                <i class="fas fa-utensils text-3xl text-[#ff6b35] mb-3"></i>
                <p class="font-semibold">Aucun menu publié pour le moment.</p>
            </section>
        @endif
    </main>

    {{-- Barre de navigation basse (smartphone + tablette ; masquée sur très grands écrans optionnelle) --}}
    <nav class="bottom-nav md:hidden fixed bottom-0 inset-x-0 z-50 bg-black border-t border-white/10 px-2 py-2 pb-[max(0.5rem,env(safe-area-inset-bottom))]" aria-label="Navigation principale">
        <div class="flex justify-around items-end max-w-lg mx-auto">
            <a href="#super-deals" class="flex flex-col items-center gap-0.5 py-1 text-[#ff6b35] min-w-[4rem]">
                <i class="fas fa-house text-lg"></i>
                <span class="text-[10px] font-semibold">Accueil</span>
            </a>
            <a href="{{ $commandTel !== '' ? $commandTel : '#' }}" class="flex flex-col items-center gap-0.5 py-1 text-neutral-500 min-w-[4rem] {{ $commandTel === '' ? 'pointer-events-none opacity-50' : '' }}">
                <i class="fas fa-bag-shopping text-lg"></i>
                <span class="text-[10px] font-semibold">Commande</span>
            </a>
            <a href="#hot-deals" class="flex flex-col items-center gap-0.5 py-1 text-neutral-500 min-w-[4rem]">
                <i class="far fa-heart text-lg"></i>
                <span class="text-[10px] font-semibold">Favoris</span>
            </a>
        </div>
    </nav>
</div>

<script>
    document.querySelectorAll('.favorite-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var icon = btn.querySelector('i');
            if (!icon) return;
            var on = icon.classList.contains('fas');
            icon.classList.toggle('far', on);
            icon.classList.toggle('fas', !on);
            if (!on) {
                icon.classList.add('text-orange-500');
            } else {
                icon.classList.remove('text-orange-500');
            }
        });
    });
</script>
</body>
</html>
