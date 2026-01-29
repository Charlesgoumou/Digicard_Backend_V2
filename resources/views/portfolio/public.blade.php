<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $portfolio->name ?? $user->name }} - Portfolio</title>

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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            background-attachment: fixed;
            min-height: 100vh;
        }
        .modal-content {
            transition: transform 0.3s ease-in-out;
            max-height: 90vh;
            overflow-y: auto;
        }
        .prose ul {
            list-style-type: disc;
            margin-left: 1.5rem;
            margin-top: 1rem;
            margin-bottom: 1rem;
        }
        .prose p {
            margin-bottom: 1rem;
            text-align: justify;
        }
        .prose ul {
            text-align: justify;
        }
        .prose li {
            text-align: justify;
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
        .skill-badge {
            transition: all 0.3s ease;
        }
        .skill-badge:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px rgba(99, 102, 241, 0.3);
        }
        .project-card {
            transition: all 0.3s ease;
        }
        .project-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }
        .timeline-item {
            transition: all 0.3s ease;
        }
        .timeline-item:hover {
            transform: translateX(8px);
        }
        .project-details, .timeline-details {
            animation: slideDown 0.3s ease-out;
        }
        @keyframes slideDown {
            from {
                opacity: 0;
                max-height: 0;
            }
            to {
                opacity: 1;
                max-height: 1000px;
            }
        }
    </style>
</head>
<body>

<div class="min-h-screen font-inter">
    <!-- Header avec design moderne -->
    <header class="relative overflow-hidden">
        <!-- Gradient background -->
        <div class="absolute inset-0 bg-gradient-to-br from-indigo-600 via-purple-600 to-pink-500 opacity-90"></div>
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
                            src="{{ $avatarUrl ?? 'https://ui-avatars.com/api/?name='.urlencode($portfolio->name ?? $user->name).'&background=6366f1&color=ffffff&size=128' }}"
                            alt="{{ $portfolio->name ?? $user->name }}"
                            class="w-40 h-40 md:w-48 md:h-48 rounded-full border-4 border-white shadow-xl ring-4 ring-indigo-500/20"
                        />
                        <div class="absolute -bottom-2 -right-2 w-12 h-12 bg-gradient-to-br from-green-400 to-emerald-500 rounded-full border-4 border-white shadow-lg flex items-center justify-center">
                            <i class="fas fa-check text-white text-lg"></i>
                        </div>
                    </div>
                    <div class="flex-1 text-center md:text-left">
                        <h1 class="text-4xl md:text-5xl font-black text-gray-900 mb-2 bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent">
                            {{ $portfolio->name ?? $user->name }}
                        </h1>
                        @if($portfolio->hero_headline)
                            <h2 class="text-xl md:text-2xl font-semibold text-indigo-600 mb-4">
                                {{ $portfolio->hero_headline }}
                            </h2>
                        @endif
                        @if($portfolio->bio)
                            <div class="text-gray-700 mt-4 prose prose-lg max-w-none text-justify">
                                {!! $portfolio->bio !!}
                            </div>
                        @endif
                        <div class="mt-8 flex flex-wrap justify-center md:justify-start gap-4">
                            @if($portfolio->email)
                                <a href="mailto:{{ $portfolio->email }}" class="group flex items-center gap-2 px-4 py-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-600 rounded-xl transition-all hover:scale-105 shadow-sm" aria-label="Email">
                                    <i class="fas fa-envelope"></i>
                                    <span class="text-sm font-medium">Email</span>
                                </a>
                            @endif
                            @if($portfolio->linkedin_url)
                                <a href="{{ $portfolio->linkedin_url }}" target="_blank" class="group flex items-center gap-2 px-4 py-2 bg-blue-50 hover:bg-blue-100 text-blue-600 rounded-xl transition-all hover:scale-105 shadow-sm" aria-label="LinkedIn">
                                    <i class="fab fa-linkedin"></i>
                                    <span class="text-sm font-medium">LinkedIn</span>
                                </a>
                            @endif
                            {{-- Bouton Menu du jour pour profil Restaurant --}}
                            @if($portfolio->profile_type === 'restaurant' && $portfolio->menu && (isset($portfolio->menu['dishes']) || isset($portfolio->menu['drinks'])))
                                <button
                                    onclick="openMenuModal()"
                                    class="group flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-orange-500 to-red-500 hover:from-orange-600 hover:to-red-600 text-white rounded-xl transition-all hover:scale-105 shadow-lg shadow-orange-500/30 relative"
                                    aria-label="Menu du jour"
                                    title="Menu du jour"
                                >
                                    <i class="fas fa-utensils"></i>
                                    <span class="text-sm font-medium">Menu du jour</span>
                                </button>
                            @endif
                            {{-- Bouton Prendre Rendez-vous --}}
                            @if(isset($appointmentSetting) && $appointmentSetting && $appointmentSetting->is_enabled)
                                <button
                                    onclick="openBookingModal()"
                                    class="group flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-sky-500 to-indigo-500 hover:from-sky-600 hover:to-indigo-600 text-white rounded-xl transition-all hover:scale-105 shadow-lg shadow-sky-500/30 relative"
                                    aria-label="Prendre rendez-vous"
                                    title="Prendre rendez-vous"
                                >
                                    <i class="fas fa-calendar-check"></i>
                                    <span class="text-sm font-medium">Rendez-vous</span>
                                    <span class="absolute -top-1 -right-1 w-3 h-3 bg-yellow-400 rounded-full animate-pulse ring-2 ring-white"></span>
                                </button>
                            @endif
                            @if($portfolio->github_url)
                                <a href="{{ $portfolio->github_url }}" target="_blank" class="group flex items-center gap-2 px-4 py-2 bg-gray-50 hover:bg-gray-100 text-gray-700 rounded-xl transition-all hover:scale-105 shadow-sm" aria-label="GitHub">
                                    <i class="fab fa-github"></i>
                                    <span class="text-sm font-medium">GitHub</span>
                                </a>
                            @endif
                            @if($portfolio->phone)
                                <a href="tel:{{ $portfolio->phone }}" class="group flex items-center gap-2 px-4 py-2 bg-green-50 hover:bg-green-100 text-green-600 rounded-xl transition-all hover:scale-105 shadow-sm" aria-label="Téléphone">
                                    <i class="fas fa-phone"></i>
                                    <span class="text-sm font-medium">Appeler</span>
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="relative container mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 py-12 -mt-8">
        @if($portfolio->skills && count($portfolio->skills) > 0)
        <section id="skills" class="mb-16 fade-in-up" style="animation-delay: 0.1s">
            <div class="bg-white/95 backdrop-blur-lg rounded-3xl shadow-xl p-8 md:p-10">
                <div class="flex items-center gap-3 mb-8">
                    <div class="w-12 h-12 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg">
                        <i class="fas fa-star text-white text-xl"></i>
                    </div>
                    <h2 class="text-3xl md:text-4xl font-black text-gray-900">
                        {{ $portfolio->skills_title ?? 'Mes Compétences' }}
                    </h2>
                </div>
                <div class="flex flex-wrap gap-3 md:gap-4">
                    @foreach($portfolio->skills as $skill)
                    <div class="skill-badge group bg-gradient-to-br from-indigo-50 to-purple-50 border-2 border-indigo-200 hover:border-indigo-400 rounded-2xl px-5 py-3 flex items-center gap-3 shadow-md hover:shadow-xl">
                        <span class="text-2xl transform group-hover:scale-110 transition-transform">{{ $skill['icon'] ?? '🏷️' }}</span>
                        <span class="font-bold text-gray-800 text-sm md:text-base">{{ $skill['name'] ?? '' }}</span>
                    </div>
                    @endforeach
                </div>
            </div>
        </section>
        @endif

        @if($portfolio->projects && count($portfolio->projects) > 0)
        <section id="projects" class="mb-16 fade-in-up" style="animation-delay: 0.2s">
            <div class="bg-white/95 backdrop-blur-lg rounded-3xl shadow-xl p-8 md:p-10">
                <div class="flex items-center gap-3 mb-8">
                    @php
                        $profileType = $profileType ?? ($portfolio->profile_type ?? 'student');
                        $projectIcon = 'fa-briefcase';
                        $projectGradient = 'from-blue-500 to-cyan-600';
                        if ($profileType === 'teacher') {
                            $projectIcon = 'fa-chalkboard-teacher';
                            $projectGradient = 'from-green-500 to-emerald-600';
                        } elseif ($profileType === 'freelance') {
                            $projectIcon = 'fa-briefcase';
                            $projectGradient = 'from-orange-500 to-amber-600';
                        } elseif ($profileType === 'pharmacist') {
                            $projectIcon = 'fa-pills';
                            $projectGradient = 'from-red-500 to-pink-600';
                        } elseif ($profileType === 'doctor') {
                            $projectIcon = 'fa-user-md';
                            $projectGradient = 'from-blue-500 to-indigo-600';
                        } elseif ($profileType === 'lawyer') {
                            $projectIcon = 'fa-gavel';
                            $projectGradient = 'from-purple-500 to-indigo-600';
                        } elseif ($profileType === 'notary') {
                            $projectIcon = 'fa-stamp';
                            $projectGradient = 'from-amber-500 to-orange-600';
                        } elseif ($profileType === 'bailiff') {
                            $projectIcon = 'fa-file-contract';
                            $projectGradient = 'from-slate-500 to-gray-600';
                        } elseif ($profileType === 'architect') {
                            $projectIcon = 'fa-drafting-compass';
                            $projectGradient = 'from-teal-500 to-cyan-600';
                        } elseif ($profileType === 'engineer') {
                            $projectIcon = 'fa-cogs';
                            $projectGradient = 'from-indigo-500 to-blue-600';
                        } elseif ($profileType === 'consultant') {
                            $projectIcon = 'fa-lightbulb';
                            $projectGradient = 'from-yellow-500 to-amber-600';
                        } elseif ($profileType === 'accountant') {
                            $projectIcon = 'fa-calculator';
                            $projectGradient = 'from-green-500 to-teal-600';
                        } elseif ($profileType === 'financial_analyst') {
                            $projectIcon = 'fa-chart-line';
                            $projectGradient = 'from-emerald-500 to-green-600';
                        } elseif ($profileType === 'photographer') {
                            $projectIcon = 'fa-camera';
                            $projectGradient = 'from-gray-500 to-slate-600';
                        } elseif ($profileType === 'graphic_designer') {
                            $projectIcon = 'fa-palette';
                            $projectGradient = 'from-pink-500 to-rose-600';
                        } elseif ($profileType === 'developer') {
                            $projectIcon = 'fa-laptop-code';
                            $projectGradient = 'from-blue-500 to-purple-600';
                        }
                    @endphp
                    <div class="w-12 h-12 bg-gradient-to-br {{ $projectGradient }} rounded-xl flex items-center justify-center shadow-lg">
                        <i class="fas {{ $projectIcon }} text-white text-xl"></i>
                    </div>
                    <h2 class="text-3xl md:text-4xl font-black text-gray-900">
                        {{ $portfolio->projects_title ?? 'Mes Projets Académiques' }}
                    </h2>
                </div>
                <div class="space-y-4">
                    @foreach($portfolio->projects as $index => $project)
                    <div
                        class="project-item group bg-gradient-to-br from-white to-gray-50 border-2 border-gray-200 hover:border-indigo-400 rounded-2xl overflow-hidden cursor-pointer shadow-md hover:shadow-xl transition-all"
                        data-project-index="{{ $index }}"
                        onclick="openProjectModal({{ $index }})"
                    >
                        <div class="p-6">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-4 flex-1">
                                    <div class="w-12 h-12 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-xl flex items-center justify-center shadow-md group-hover:scale-110 transition-transform flex-shrink-0">
                                        <i class="fas {{ $project['icon'] ?? 'fa-cube' }} text-white text-lg"></i>
                                    </div>
                                    <div class="flex-1">
                                        <h3 class="text-lg md:text-xl font-bold text-gray-900 group-hover:text-indigo-600 transition-colors">
                                            {{ $project['title'] ?? '' }}
                                        </h3>
                                    </div>
                                </div>
                                <i class="fas fa-arrow-right text-gray-400 group-hover:text-indigo-600 group-hover:translate-x-1 transition-all"></i>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </section>
        @endif

        <section id="formations" class="mb-16 fade-in-up" style="animation-delay: 0.3s">
            <div class="bg-white/95 backdrop-blur-lg rounded-3xl shadow-xl p-8 md:p-10">
                <div class="flex items-center gap-3 mb-8">
                    <div class="w-12 h-12 bg-gradient-to-br from-emerald-500 to-teal-600 rounded-xl flex items-center justify-center shadow-lg">
                        <i class="fas fa-graduation-cap text-white text-xl"></i>
                    </div>
                    <h2 class="text-3xl md:text-4xl font-black text-gray-900">
                        {{ $portfolio->formations_title ?? 'Mes Formations' }}
                    </h2>
                </div>
                @if($portfolio->formations && count($portfolio->formations) > 0)
                <div class="space-y-4">
                    @foreach($portfolio->formations as $index => $formation)
                    <div
                        class="timeline-item group bg-gradient-to-br from-white to-gray-50 border-2 border-gray-200 hover:border-emerald-400 rounded-2xl overflow-hidden cursor-pointer shadow-md hover:shadow-xl transition-all"
                        data-formation-index="{{ $index }}"
                        onclick="openFormationModal({{ $index }})"
                    >
                        <div class="p-6">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-4 flex-1">
                                    <div class="w-12 h-12 bg-gradient-to-br from-emerald-500 to-teal-600 rounded-full flex items-center justify-center shadow-md group-hover:scale-110 transition-transform flex-shrink-0">
                                        <i class="fas fa-graduation-cap text-white text-lg"></i>
                                    </div>
                                    <div class="flex-1">
                                        <h3 class="text-lg md:text-xl font-bold text-gray-900 group-hover:text-emerald-600 transition-colors">
                                            {{ $formation['title'] ?? '' }}
                                        </h3>
                                        @if(isset($formation['organization']) && $formation['organization'])
                                            <span class="block text-sm font-semibold text-emerald-600 mt-1">{{ $formation['organization'] }}</span>
                                        @endif
                                        @if(isset($formation['date']) && $formation['date'])
                                            <time class="block text-xs font-medium text-gray-500 mt-1 bg-gray-100 px-2 py-1 rounded-md inline-block">{{ $formation['date'] }}</time>
                                        @endif
                                    </div>
                                </div>
                                <i class="fas fa-arrow-right text-gray-400 group-hover:text-emerald-600 group-hover:translate-x-1 transition-all"></i>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
                @else
                <div class="text-center py-12">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-emerald-100 flex items-center justify-center">
                        <i class="fas fa-graduation-cap text-emerald-600 text-2xl"></i>
                    </div>
                    <p class="text-gray-600 text-lg font-medium">Aucune formation enregistrée pour le moment</p>
                    <p class="text-gray-500 text-sm mt-2">Les formations seront affichées ici une fois ajoutées</p>
                </div>
                @endif
            </div>
        </section>

        @if($portfolio->timeline && count($portfolio->timeline) > 0)
        <section id="timeline" class="mb-16 fade-in-up" style="animation-delay: 0.4s">
            <div class="bg-white/95 backdrop-blur-lg rounded-3xl shadow-xl p-8 md:p-10">
                <div class="flex items-center gap-3 mb-8">
                    @php
                        $profileType = $profileType ?? ($portfolio->profile_type ?? 'student');
                        $timelineIcon = 'fa-graduation-cap';
                        $timelineGradient = 'from-purple-500 to-pink-600';
                        if ($profileType === 'teacher') {
                            $timelineIcon = 'fa-user-tie';
                            $timelineGradient = 'from-indigo-500 to-blue-600';
                        } elseif ($profileType === 'freelance') {
                            $timelineIcon = 'fa-handshake';
                            $timelineGradient = 'from-amber-500 to-orange-600';
                        } elseif ($profileType === 'pharmacist') {
                            $timelineIcon = 'fa-mortar-pestle';
                            $timelineGradient = 'from-red-500 to-pink-600';
                        } elseif ($profileType === 'doctor') {
                            $timelineIcon = 'fa-stethoscope';
                            $timelineGradient = 'from-blue-500 to-indigo-600';
                        } elseif ($profileType === 'lawyer') {
                            $timelineIcon = 'fa-balance-scale';
                            $timelineGradient = 'from-purple-500 to-indigo-600';
                        } elseif ($profileType === 'notary') {
                            $timelineIcon = 'fa-scroll';
                            $timelineGradient = 'from-amber-500 to-orange-600';
                        } elseif ($profileType === 'bailiff') {
                            $timelineIcon = 'fa-gavel';
                            $timelineGradient = 'from-slate-500 to-gray-600';
                        } elseif ($profileType === 'architect') {
                            $timelineIcon = 'fa-building';
                            $timelineGradient = 'from-teal-500 to-cyan-600';
                        } elseif ($profileType === 'engineer') {
                            $timelineIcon = 'fa-tools';
                            $timelineGradient = 'from-indigo-500 to-blue-600';
                        } elseif ($profileType === 'consultant') {
                            $timelineIcon = 'fa-briefcase';
                            $timelineGradient = 'from-yellow-500 to-amber-600';
                        } elseif ($profileType === 'accountant') {
                            $timelineIcon = 'fa-file-invoice-dollar';
                            $timelineGradient = 'from-green-500 to-teal-600';
                        } elseif ($profileType === 'financial_analyst') {
                            $timelineIcon = 'fa-chart-bar';
                            $timelineGradient = 'from-emerald-500 to-green-600';
                        } elseif ($profileType === 'photographer') {
                            $timelineIcon = 'fa-images';
                            $timelineGradient = 'from-gray-500 to-slate-600';
                        } elseif ($profileType === 'graphic_designer') {
                            $timelineIcon = 'fa-paint-brush';
                            $timelineGradient = 'from-pink-500 to-rose-600';
                        } elseif ($profileType === 'developer') {
                            $timelineIcon = 'fa-code';
                            $timelineGradient = 'from-blue-500 to-purple-600';
                        }
                    @endphp
                    <div class="w-12 h-12 bg-gradient-to-br {{ $timelineGradient }} rounded-xl flex items-center justify-center shadow-lg">
                        <i class="fas {{ $timelineIcon }} text-white text-xl"></i>
                    </div>
                    <h2 class="text-3xl md:text-4xl font-black text-gray-900">
                        {{ $portfolio->timeline_title ?? 'Mon Parcours Professionnel' }}
                    </h2>
                </div>
                <div class="space-y-4">
                    @foreach($portfolio->timeline as $index => $item)
                    <div
                        class="timeline-item group bg-gradient-to-br from-white to-gray-50 border-2 border-gray-200 hover:border-purple-400 rounded-2xl overflow-hidden cursor-pointer shadow-md hover:shadow-xl transition-all"
                        data-timeline-index="{{ $index }}"
                        onclick="openTimelineModal({{ $index }})"
                    >
                        <div class="p-6">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-4 flex-1">
                                    <div class="w-12 h-12 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-full flex items-center justify-center shadow-md group-hover:scale-110 transition-transform flex-shrink-0">
                                        <span class="text-lg">{{ $item['icon'] ?? '🎓' }}</span>
                                    </div>
                                    <div class="flex-1">
                                        <h3 class="text-lg md:text-xl font-bold text-gray-900 group-hover:text-purple-600 transition-colors">
                                            {{ $item['title'] ?? '' }}
                                        </h3>
                                        @if(isset($item['organization']) && $item['organization'])
                                            <span class="block text-sm font-semibold text-indigo-600 mt-1">{{ $item['organization'] }}</span>
                                        @endif
                                        @if(isset($item['date']) && $item['date'])
                                            <time class="block text-xs font-medium text-gray-500 mt-1 bg-gray-100 px-2 py-1 rounded-md inline-block">{{ $item['date'] }}</time>
                                        @elseif(isset($item['dates']) && $item['dates'])
                                            <time class="block text-xs font-medium text-gray-500 mt-1 bg-gray-100 px-2 py-1 rounded-md inline-block">{{ $item['dates'] }}</time>
                                        @endif
                                    </div>
                                </div>
                                <i class="fas fa-arrow-right text-gray-400 group-hover:text-purple-600 group-hover:translate-x-1 transition-all"></i>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </section>
        @endif
    </main>
</div>

<!-- Modal pour afficher le Menu du jour (Restaurant) -->
@if($portfolio->profile_type === 'restaurant' && $portfolio->menu && (isset($portfolio->menu['dishes']) || isset($portfolio->menu['drinks'])))
<div id="menuModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-4">
    <div class="modal-content bg-white rounded-3xl shadow-2xl p-8 md:p-10 w-full max-w-5xl transform scale-100 border border-gray-200 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-start mb-6">
            <div class="flex-1">
                <h2 class="text-3xl md:text-4xl font-black bg-gradient-to-r from-orange-600 to-red-600 bg-clip-text text-transparent flex items-center gap-3">
                    <i class="fas fa-utensils"></i>
                    Menu du jour
                </h2>
                <p class="text-gray-600 mt-2">{{ $portfolio->hero_headline ?? $portfolio->name }}</p>
            </div>
            <button onclick="closeMenuModal()" class="ml-4 w-10 h-10 flex items-center justify-center rounded-full bg-gray-100 hover:bg-gray-200 text-gray-600 hover:text-gray-800 transition-all">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="space-y-8">
            @php
                $menu = is_array($portfolio->menu) ? $portfolio->menu : json_decode($portfolio->menu, true);
                $dishes = $menu['dishes'] ?? [];
                $drinks = $menu['drinks'] ?? [];
            @endphp
            
            @if(count($dishes) > 0)
            <div>
                <h3 class="text-2xl font-bold text-gray-900 mb-6 flex items-center gap-3">
                    <span class="text-3xl">🍽️</span>
                    <span>Nos Plats</span>
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    @foreach($dishes as $dish)
                    <div class="bg-gradient-to-br from-white to-gray-50 border-2 rounded-2xl overflow-hidden shadow-md hover:shadow-xl transition-all {{ $dish['available'] ?? true ? 'border-gray-200 hover:border-orange-400' : 'border-red-300 opacity-60' }}">
                        @if(isset($dish['image']) && $dish['image'])
                        <div class="h-48 overflow-hidden">
                            <img src="{{ $dish['image'] }}" alt="{{ $dish['name'] ?? '' }}" class="w-full h-full object-cover">
                        </div>
                        @else
                        <div class="h-48 bg-gray-200 flex items-center justify-center">
                            <span class="text-6xl">🍽️</span>
                        </div>
                        @endif
                        <div class="p-6">
                            <div class="flex items-start justify-between mb-2">
                                <h4 class="text-xl font-bold text-gray-900">{{ $dish['name'] ?? '' }}</h4>
                                <span class="px-3 py-1 rounded-full text-xs font-semibold {{ ($dish['available'] ?? true) ? 'bg-green-100 text-green-700 border border-green-300' : 'bg-red-100 text-red-700 border border-red-300' }}">
                                    {{ ($dish['available'] ?? true) ? 'Disponible' : 'Indisponible' }}
                                </span>
                            </div>
                            @if(isset($dish['price']) && $dish['price'])
                            <div class="text-2xl font-bold text-orange-600 mb-3">
                                {{ number_format($dish['price'], 0, ',', ' ') }} FCFA
                            </div>
                            @endif
                            @if(isset($dish['description']) && $dish['description'])
                            <p class="text-gray-600 mb-4">{{ $dish['description'] }}</p>
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
            @endif
            
            @if(count($drinks) > 0)
            <div>
                <h3 class="text-2xl font-bold text-gray-900 mb-6 flex items-center gap-3">
                    <span class="text-3xl">🥤</span>
                    <span>Nos Boissons</span>
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach($drinks as $drink)
                    <div class="bg-gradient-to-br from-white to-gray-50 border-2 rounded-2xl overflow-hidden shadow-md hover:shadow-xl transition-all {{ $drink['available'] ?? true ? 'border-gray-200 hover:border-orange-400' : 'border-red-300 opacity-60' }}">
                        @if(isset($drink['image']) && $drink['image'])
                        <div class="h-48 overflow-hidden">
                            <img src="{{ $drink['image'] }}" alt="{{ $drink['name'] ?? '' }}" class="w-full h-full object-cover">
                        </div>
                        @else
                        <div class="h-48 bg-gray-200 flex items-center justify-center">
                            <span class="text-6xl">🥤</span>
                        </div>
                        @endif
                        <div class="p-6">
                            <div class="flex items-start justify-between mb-2">
                                <h4 class="text-xl font-bold text-gray-900">{{ $drink['name'] ?? '' }}</h4>
                                <span class="px-3 py-1 rounded-full text-xs font-semibold {{ ($drink['available'] ?? true) ? 'bg-green-100 text-green-700 border border-green-300' : 'bg-red-100 text-red-700 border border-red-300' }}">
                                    {{ ($drink['available'] ?? true) ? 'Disponible' : 'Indisponible' }}
                                </span>
                            </div>
                            @if(isset($drink['price']) && $drink['price'])
                            <div class="text-2xl font-bold text-orange-600">
                                {{ number_format($drink['price'], 0, ',', ' ') }} FCFA
                            </div>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
            
            @if(count($dishes) === 0 && count($drinks) === 0)
            <div class="text-center py-12">
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-orange-100 flex items-center justify-center">
                    <i class="fas fa-utensils text-orange-600 text-2xl"></i>
                </div>
                <p class="text-gray-600 text-lg font-medium">Aucun plat ou boisson disponible pour le moment</p>
            </div>
            @endif
        </div>
    </div>
</div>
@endif

<!-- Modal pour afficher les détails d'un projet -->
<div id="projectModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-4">
    <div class="modal-content bg-white rounded-3xl shadow-2xl p-8 md:p-10 w-full max-w-3xl transform scale-100 border border-gray-200 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-start mb-6">
            <div class="flex-1">
                <h2 class="text-3xl md:text-4xl font-black bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent" id="modalProjectTitle"></h2>
            </div>
            <button onclick="closeProjectModal()" class="ml-4 w-10 h-10 flex items-center justify-center rounded-full bg-gray-100 hover:bg-gray-200 text-gray-600 hover:text-gray-800 transition-all">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="prose prose-lg max-w-none text-gray-700 text-justify" id="modalProjectContent"></div>
        <div class="mt-8 pt-6 border-t border-gray-200">
            <a id="modalProjectLink" href="#" target="_blank" class="hidden inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white font-semibold rounded-xl shadow-lg hover:shadow-xl transition-all">
                <span>Voir le Projet</span>
                <i class="fas fa-arrow-up-right-from-square"></i>
            </a>
        </div>
    </div>
</div>

<!-- Modal pour afficher les détails d'une formation -->
<div id="formationModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-4">
    <div class="modal-content bg-white rounded-3xl shadow-2xl p-8 md:p-10 w-full max-w-3xl transform scale-100 border border-gray-200 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-start mb-6">
            <div class="flex-1">
                <h2 class="text-3xl md:text-4xl font-black bg-gradient-to-r from-emerald-600 to-teal-600 bg-clip-text text-transparent" id="modalFormationTitle"></h2>
                <div id="modalFormationMeta" class="mt-2 space-y-1"></div>
            </div>
            <button onclick="closeFormationModal()" class="ml-4 w-10 h-10 flex items-center justify-center rounded-full bg-gray-100 hover:bg-gray-200 text-gray-600 hover:text-gray-800 transition-all">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="prose prose-lg max-w-none text-gray-700 text-justify" id="modalFormationContent"></div>
    </div>
</div>

<!-- Modal pour afficher les détails d'une expérience professionnelle -->
<div id="timelineModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center z-50 p-4">
    <div class="modal-content bg-white rounded-3xl shadow-2xl p-8 md:p-10 w-full max-w-3xl transform scale-100 border border-gray-200 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-start mb-6">
            <div class="flex-1">
                <h2 class="text-3xl md:text-4xl font-black bg-gradient-to-r from-purple-600 to-pink-600 bg-clip-text text-transparent" id="modalTimelineTitle"></h2>
                <div id="modalTimelineMeta" class="mt-2 space-y-1"></div>
            </div>
            <button onclick="closeTimelineModal()" class="ml-4 w-10 h-10 flex items-center justify-center rounded-full bg-gray-100 hover:bg-gray-200 text-gray-600 hover:text-gray-800 transition-all">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="prose prose-lg max-w-none text-gray-700 text-justify" id="modalTimelineContent"></div>
    </div>
</div>

{{-- Modal de Réservation de Rendez-vous --}}
@if(isset($appointmentSetting) && $appointmentSetting && $appointmentSetting->is_enabled)
<div id="bookingModal" class="fixed inset-0 bg-black bg-opacity-60 hidden items-center justify-center z-50 p-2 sm:p-4 backdrop-blur-sm overflow-y-auto">
    <div class="modal-content bg-gradient-to-br from-slate-800 to-slate-900 rounded-2xl shadow-2xl w-full max-w-lg border border-slate-700 my-auto flex flex-col max-h-[calc(100vh-1rem)] sm:max-h-[calc(100vh-2rem)]">
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
                    <p class="text-sm text-slate-400">avec {{ $portfolio->name ?? $user->name }}</p>
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
        <div class="p-4 sm:p-6 flex-1 overflow-y-auto min-h-0">
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
                        <i class="fas fa-chevron-left"></i> Modifier la date
                    </button>
                    <span id="selectedDateDisplay" class="text-sm text-sky-400 font-medium"></span>
                </div>
                <div id="slotsContainer" class="grid grid-cols-2 sm:grid-cols-3 gap-3"></div>
                <div id="noSlots" class="hidden text-center py-8">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-yellow-500/20 flex items-center justify-center">
                        <i class="fas fa-clock text-yellow-400 text-2xl"></i>
                    </div>
                    <p class="text-yellow-400 font-medium mb-1">Aucune disponibilité</p>
                    <p class="text-slate-400 text-sm">Aucun créneau disponible pour cette date.<br>Essayez une autre date.</p>
                </div>
            </div>

            <!-- Étape 3: Formulaire -->
            <div id="bookingStep3" class="hidden space-y-4">
                <button onclick="goToBookingStep(2)" class="flex items-center gap-1 text-sm text-slate-400 hover:text-sky-400 transition-colors mb-4">
                    <i class="fas fa-chevron-left"></i> Modifier le créneau
                </button>
                <!-- Récapitulatif -->
                <div class="bg-gradient-to-r from-sky-500/10 to-indigo-500/10 border border-sky-500/30 rounded-xl p-4 mb-4">
                    <p class="text-sm text-slate-300">
                        <span class="text-sky-400 font-medium">📅 Rendez-vous avec {{ $portfolio->name ?? $user->name }}</span><br>
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
                            <span id="submitBtnLoading" class="hidden"><i class="fas fa-spinner fa-spin"></i> Confirmation...</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Étape 4: Succès -->
            <div id="bookingStep4" class="hidden text-center py-8">
                <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-gradient-to-br from-green-400 to-emerald-500 flex items-center justify-center shadow-lg shadow-green-500/30">
                    <i class="fas fa-check text-white text-3xl"></i>
                </div>
                <h3 class="text-2xl font-bold text-white mb-2">Rendez-vous confirmé !</h3>
                <p class="text-slate-400 mb-6">
                    Votre rendez-vous avec <span class="text-sky-400">{{ $portfolio->name ?? $user->name }}</span> a été enregistré.<br>
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
@endif

<script>
const projects = @json($portfolio->projects ?? []);
const projectsData = Object.values(projects);
const formations = @json($portfolio->formations ?? []);
const formationsData = Object.values(formations);
const timeline = @json($portfolio->timeline ?? []);
const timelineData = Object.values(timeline);

// Fonction pour ouvrir le modal du menu (Restaurant)
function openMenuModal() {
    document.getElementById('menuModal').classList.remove('hidden');
    document.getElementById('menuModal').classList.add('flex');
}

function closeMenuModal() {
    document.getElementById('menuModal').classList.add('hidden');
    document.getElementById('menuModal').classList.remove('flex');
}

// Fonction pour ouvrir le modal d'un projet
function openProjectModal(index) {
    const project = projectsData[index];
    if (!project) return;

    document.getElementById('modalProjectTitle').textContent = project.title || '';

    // Construire le contenu
    let content = '';
    if (project.short_description) {
        content += '<p class="text-lg text-gray-800 mb-4">' + project.short_description + '</p>';
    }
    if (project.details_html) {
        content += project.details_html;
    } else if (!project.short_description) {
        content = '<p>Aucune description disponible.</p>';
    }
    
    document.getElementById('modalProjectContent').innerHTML = content;

    // Afficher le lien s'il existe
    const modalLink = document.getElementById('modalProjectLink');
    if (project.link) {
        modalLink.href = project.link;
        modalLink.classList.remove('hidden');
    } else {
        modalLink.classList.add('hidden');
    }

    document.getElementById('projectModal').classList.remove('hidden');
    document.getElementById('projectModal').classList.add('flex');
}

function closeProjectModal() {
    document.getElementById('projectModal').classList.add('hidden');
    document.getElementById('projectModal').classList.remove('flex');
}

// Fonction pour ouvrir le modal d'une formation
function openFormationModal(index) {
    const formation = formationsData[index];
    if (!formation) return;

    document.getElementById('modalFormationTitle').textContent = formation.title || '';

    // Construire les métadonnées (organisation et date)
    let meta = '';
    if (formation.organization) {
        meta += '<p class="text-lg font-semibold text-emerald-600">' + formation.organization + '</p>';
    }
    if (formation.date) {
        meta += '<time class="block text-sm font-medium text-gray-500 mt-1 bg-gray-100 px-3 py-1 rounded-md inline-block">' + formation.date + '</time>';
    }
    document.getElementById('modalFormationMeta').innerHTML = meta;

    // Construire le contenu
    let content = '';
    if (formation.description) {
        content = formation.description;
    } else {
        content = '<p>Aucun détail disponible.</p>';
    }
    
    document.getElementById('modalFormationContent').innerHTML = content;

    document.getElementById('formationModal').classList.remove('hidden');
    document.getElementById('formationModal').classList.add('flex');
}

function closeFormationModal() {
    document.getElementById('formationModal').classList.add('hidden');
    document.getElementById('formationModal').classList.remove('flex');
}

// Fonction pour ouvrir le modal d'une expérience professionnelle
function openTimelineModal(index) {
    const item = timelineData[index];
    if (!item) return;

    document.getElementById('modalTimelineTitle').textContent = item.title || '';

    // Construire les métadonnées (organisation et date)
    let meta = '';
    if (item.organization) {
        meta += '<p class="text-lg font-semibold text-indigo-600">' + item.organization + '</p>';
    }
    if (item.date) {
        meta += '<time class="block text-sm font-medium text-gray-500 mt-1 bg-gray-100 px-3 py-1 rounded-md inline-block">' + item.date + '</time>';
    } else if (item.dates) {
        meta += '<time class="block text-sm font-medium text-gray-500 mt-1 bg-gray-100 px-3 py-1 rounded-md inline-block">' + item.dates + '</time>';
    }
    document.getElementById('modalTimelineMeta').innerHTML = meta;

    // Construire le contenu
    let content = '';
    if (item.description) {
        content = item.description;
    } else if (item.details) {
        content = item.details;
    } else {
        content = '<p>Aucun détail disponible.</p>';
    }
    
    document.getElementById('modalTimelineContent').innerHTML = content;

    document.getElementById('timelineModal').classList.remove('hidden');
    document.getElementById('timelineModal').classList.add('flex');
}

function closeTimelineModal() {
    document.getElementById('timelineModal').classList.add('hidden');
    document.getElementById('timelineModal').classList.remove('flex');
}

// Fermer les modals en cliquant en dehors
document.addEventListener('click', function(e) {
    const projectModal = document.getElementById('projectModal');
    if (e.target === projectModal) {
        closeProjectModal();
    }
    const formationModal = document.getElementById('formationModal');
    if (e.target === formationModal) {
        closeFormationModal();
    }
    const timelineModal = document.getElementById('timelineModal');
    if (e.target === timelineModal) {
        closeTimelineModal();
    }
    const menuModal = document.getElementById('menuModal');
    if (menuModal && e.target === menuModal) {
        closeMenuModal();
    }
    const bookingModal = document.getElementById('bookingModal');
    if (e.target === bookingModal) {
        closeBookingModal();
    }
});

// ========== BOOKING MODAL ==========
@if(isset($appointmentSetting) && $appointmentSetting && $appointmentSetting->is_enabled)
const userId = {{ $user->id }};
const orderId = @if(isset($appointmentOrderId) && $appointmentOrderId){{ $appointmentOrderId }}@else null @endif;
const ownerName = "{{ $portfolio->name ?? $user->name }}";
const apiBaseUrl = "{{ config('app.url') }}";

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
        
        const response = await fetch(`${apiBaseUrl}/api/user/${userId}/available-dates?${params.toString()}`);
        const data = await response.json();
        
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
                            
                            // Retirer l'icône de vérification
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
        console.error('Erreur lors du chargement des dates disponibles:', error);
        loadingDiv.classList.add('hidden');
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
        
        // Retirer l'icône de vérification
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
    // Hide all steps
    document.getElementById('bookingStep1').classList.add('hidden');
    document.getElementById('bookingStep2').classList.add('hidden');
    document.getElementById('bookingStep3').classList.add('hidden');
    document.getElementById('bookingStep4').classList.add('hidden');
    // Show current step
    document.getElementById('bookingStep' + step).classList.remove('hidden');
    // Update indicators
    document.getElementById('step1Indicator').className = step >= 1 ? 'w-8 h-1 rounded-full bg-sky-500 transition-all' : 'w-8 h-1 rounded-full bg-slate-600 transition-all';
    document.getElementById('step2Indicator').className = step >= 2 ? 'w-8 h-1 rounded-full bg-sky-500 transition-all' : 'w-8 h-1 rounded-full bg-slate-600 transition-all';
    document.getElementById('step3Indicator').className = step >= 3 ? 'w-8 h-1 rounded-full bg-sky-500 transition-all' : 'w-8 h-1 rounded-full bg-slate-600 transition-all';
    // Update footer
    updateBookingFooter();
}

function goToBookingStep(step) {
    showBookingStep(step);
}

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
        const response = await fetch(url);
        const data = await response.json();
        availableSlots = data.available_slots || [];
        document.getElementById('loadingSlots').classList.add('hidden');
        // Toujours passer à l'étape 2 pour afficher les créneaux ou le message "aucun créneau"
        showBookingStep(2);
        renderSlots();
    } catch (error) {
        console.error('Error fetching slots:', error);
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
        start_time: selectedSlot.start,
        order_id: orderId || null
    };
    try {
        const response = await fetch(`${apiBaseUrl}/api/user/${userId}/appointments`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await response.json();
        if (!response.ok) {
            if (response.status === 409) {
                errorDiv.textContent = 'Ce créneau vient d\'être réservé. Veuillez en choisir un autre.';
                errorDiv.classList.remove('hidden');
                // Retourner à l'étape 2 et recharger les créneaux
                showBookingStep(2);
                document.getElementById('loadingSlots').classList.remove('hidden');
                let slotsUrl = `${apiBaseUrl}/api/user/${userId}/slots?date=${selectedDate}`;
                if (orderId) slotsUrl += `&order_id=${orderId}`;
                const slotsResponse = await fetch(slotsUrl);
                const slotsData = await slotsResponse.json();
                availableSlots = slotsData.available_slots || [];
                document.getElementById('loadingSlots').classList.add('hidden');
                renderSlots();
            } else {
                throw new Error(data.message || 'Une erreur est survenue');
            }
        } else {
            // Succès
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
@endif
</script>

</body>
</html>

