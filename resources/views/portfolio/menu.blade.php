<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu du jour - {{ $portfolio->hero_headline ?? $portfolio->name ?? $user->name }}</title>

    <!-- ✅ Favicons DigiCard -->
    <link rel="icon" type="image/png" sizes="16x16" href="https://digicard.arccenciel.com/logo2-16.png" />
    <link rel="icon" type="image/png" sizes="32x32" href="https://digicard.arccenciel.com/logo2-32.png" />
    <link rel="apple-touch-icon" sizes="180x180" href="https://digicard.arccenciel.com/logo2-180.png" />
    <link rel="icon" type="image/png" sizes="192x192" href="https://digicard.arccenciel.com/logo2-192.png" />
    <link rel="icon" type="image/png" sizes="512x512" href="https://digicard.arccenciel.com/logo2-512.png" />

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;800&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f97316 0%, #dc2626 100%);
            background-attachment: fixed;
            min-height: 100vh;
        }
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }
        .menu-item {
            transition: all 0.3s ease;
        }
        .menu-item:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }
    </style>
</head>
<body>
    <div class="min-h-screen font-inter">
        <!-- Header avec design moderne -->
        <header class="relative overflow-hidden">
            <!-- Gradient background -->
            <div class="absolute inset-0 bg-gradient-to-br from-orange-600 via-red-600 to-pink-500 opacity-90"></div>
            <div class="absolute inset-0 bg-[url('data:image/svg+xml,%3Csvg width="60" height="60" viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg"%3E%3Cg fill="none" fill-rule="evenodd"%3E%3Cg fill="%23ffffff" fill-opacity="0.05"%3E%3Cpath d="M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z"/%3E%3C/g%3E%3C/g%3E%3C/svg%3E')] opacity-20"></div>
            
            <div class="relative container mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-12 md:py-16">
                <div class="bg-white/95 backdrop-blur-lg rounded-3xl shadow-2xl p-8 md:p-12 fade-in-up">
                    <div class="flex flex-col md:flex-row items-center md:items-start gap-8">
                        @php
                            $avatarUrl = $portfolio->photo_url;
                            if ($avatarUrl && str_starts_with($avatarUrl, '/storage/')) {
                                $avatarUrl = url($avatarUrl);
                            }
                        @endphp
                        <div class="relative flex-shrink-0">
                            <img
                                src="{{ $avatarUrl ?? 'https://ui-avatars.com/api/?name='.urlencode($portfolio->name ?? $user->name).'&background=f97316&color=ffffff&size=128' }}"
                                alt="{{ $portfolio->name ?? $user->name }}"
                                class="w-40 h-40 md:w-48 md:h-48 rounded-full border-4 border-white shadow-xl ring-4 ring-orange-500/20"
                            />
                            <div class="absolute -bottom-2 -right-2 w-12 h-12 bg-gradient-to-br from-orange-400 to-red-500 rounded-full border-4 border-white shadow-lg flex items-center justify-center">
                                <i class="fas fa-utensils text-white text-lg"></i>
                            </div>
                        </div>
                        <div class="flex-1 text-center md:text-left">
                            <h1 class="text-4xl md:text-5xl font-black text-gray-900 mb-2 bg-gradient-to-r from-orange-600 to-red-600 bg-clip-text text-transparent">
                                {{ $portfolio->hero_headline ?? $portfolio->name ?? $user->name }}
                            </h1>
                            @if($portfolio->bio)
                                <div class="text-gray-700 mt-4 prose prose-lg max-w-none text-justify">
                                    {!! $portfolio->bio !!}
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="relative container mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-12 -mt-8">
            @php
                $menu = is_array($portfolio->menu) ? $portfolio->menu : json_decode($portfolio->menu, true);
                $dishes = $menu['dishes'] ?? [];
                $drinks = $menu['drinks'] ?? [];
            @endphp

            <!-- Section Plats -->
            @if(count($dishes) > 0)
            <section id="dishes" class="mb-16 fade-in-up" style="animation-delay: 0.1s">
                <div class="bg-white/95 backdrop-blur-lg rounded-3xl shadow-xl p-8 md:p-10">
                    <div class="flex items-center gap-3 mb-8">
                        <div class="w-12 h-12 bg-gradient-to-br from-orange-500 to-red-600 rounded-xl flex items-center justify-center shadow-lg">
                            <span class="text-3xl">🍽️</span>
                        </div>
                        <h2 class="text-3xl md:text-4xl font-black text-gray-900">
                            Nos Plats
                        </h2>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        @foreach($dishes as $dish)
                        <div class="menu-item bg-gradient-to-br from-white to-gray-50 border-2 rounded-2xl overflow-hidden shadow-md {{ $dish['available'] ?? true ? 'border-gray-200 hover:border-orange-400' : 'border-red-300 opacity-60' }}">
                            @if(isset($dish['image']) && $dish['image'])
                            <div class="h-64 overflow-hidden bg-gray-100">
                                <img src="{{ $dish['image'] }}" alt="{{ $dish['name'] ?? '' }}" class="w-full h-full object-cover" onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'h-64 flex items-center justify-center bg-gray-200\'><span class=\'text-8xl\'>🍽️</span></div>';">
                            </div>
                            @else
                            <div class="h-64 bg-gray-200 flex items-center justify-center">
                                <span class="text-8xl">🍽️</span>
                            </div>
                            @endif
                            <div class="p-6">
                                <div class="flex items-start justify-between mb-3">
                                    <h3 class="text-2xl font-bold text-gray-900">{{ $dish['name'] ?? '' }}</h3>
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold whitespace-nowrap {{ ($dish['available'] ?? true) ? 'bg-green-100 text-green-700 border border-green-300' : 'bg-red-100 text-red-700 border border-red-300' }}">
                                        {{ ($dish['available'] ?? true) ? 'Disponible' : 'Indisponible' }}
                                    </span>
                                </div>
                                @if(isset($dish['price']) && $dish['price'])
                                <div class="text-3xl font-bold text-orange-600 mb-4">
                                    {{ number_format($dish['price'], 0, ',', ' ') }} FCFA
                                </div>
                                @endif
                                @if(isset($dish['description']) && $dish['description'])
                                <p class="text-gray-600 mb-4 text-lg">{{ $dish['description'] }}</p>
                                @endif
                                @if(isset($dish['hasSides']) && $dish['hasSides'] && isset($dish['sides']) && count($dish['sides']) > 0)
                                <div class="mt-4 pt-4 border-t border-gray-200">
                                    <p class="text-sm font-semibold text-gray-500 mb-2">Accompagnements :</p>
                                    <ul class="list-disc list-inside text-sm text-gray-600 space-y-1">
                                        @foreach($dish['sides'] as $side)
                                        <li>{{ $side }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </section>
            @endif

            <!-- Section Boissons -->
            @if(count($drinks) > 0)
            <section id="drinks" class="mb-16 fade-in-up" style="animation-delay: 0.2s">
                <div class="bg-white/95 backdrop-blur-lg rounded-3xl shadow-xl p-8 md:p-10">
                    <div class="flex items-center gap-3 mb-8">
                        <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-cyan-600 rounded-xl flex items-center justify-center shadow-lg">
                            <span class="text-3xl">🥤</span>
                        </div>
                        <h2 class="text-3xl md:text-4xl font-black text-gray-900">
                            Nos Boissons
                        </h2>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        @foreach($drinks as $drink)
                        <div class="menu-item bg-gradient-to-br from-white to-gray-50 border-2 rounded-2xl overflow-hidden shadow-md {{ $drink['available'] ?? true ? 'border-gray-200 hover:border-orange-400' : 'border-red-300 opacity-60' }}">
                            @if(isset($drink['image']) && $drink['image'])
                            <div class="h-64 overflow-hidden bg-gray-100">
                                <img src="{{ $drink['image'] }}" alt="{{ $drink['name'] ?? '' }}" class="w-full h-full object-cover" onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'h-64 flex items-center justify-center bg-gray-200\'><span class=\'text-8xl\'>🥤</span></div>';">
                            </div>
                            @else
                            <div class="h-64 bg-gray-200 flex items-center justify-center">
                                <span class="text-8xl">🥤</span>
                            </div>
                            @endif
                            <div class="p-6">
                                <div class="flex items-start justify-between mb-3">
                                    <h3 class="text-2xl font-bold text-gray-900">{{ $drink['name'] ?? '' }}</h3>
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold whitespace-nowrap {{ ($drink['available'] ?? true) ? 'bg-green-100 text-green-700 border border-green-300' : 'bg-red-100 text-red-700 border border-red-300' }}">
                                        {{ ($drink['available'] ?? true) ? 'Disponible' : 'Indisponible' }}
                                    </span>
                                </div>
                                @if(isset($drink['price']) && $drink['price'])
                                <div class="text-3xl font-bold text-orange-600">
                                    {{ number_format($drink['price'], 0, ',', ' ') }} FCFA
                                </div>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </section>
            @endif

            <!-- Message si le menu est vide -->
            @if(count($dishes) === 0 && count($drinks) === 0)
            <section class="mb-16 fade-in-up">
                <div class="bg-white/95 backdrop-blur-lg rounded-3xl shadow-xl p-8 md:p-10 text-center py-12">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-orange-100 flex items-center justify-center">
                        <i class="fas fa-utensils text-orange-600 text-2xl"></i>
                    </div>
                    <p class="text-gray-600 text-lg font-medium">Aucun plat ou boisson disponible pour le moment</p>
                </div>
            </section>
            @endif
        </main>
    </div>
</body>
</html>
