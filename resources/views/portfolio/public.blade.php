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
            background-color: #f3f4f6;
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
        }
    </style>
</head>
<body>

<div class="bg-gray-100 min-h-screen font-inter">

    <header class="bg-white shadow-lg">
        <div class="container mx-auto max-w-5xl p-8 md:flex items-center">
            @php
                $avatarUrl = $portfolio->photo_url;
                if ($avatarUrl && str_starts_with($avatarUrl, '/storage/')) {
                    $avatarUrl = url($avatarUrl);
                }
            @endphp
            <img
                src="{{ $avatarUrl ?? 'https://ui-avatars.com/api/?name='.urlencode($portfolio->name ?? $user->name).'&background=6366f1&color=ffffff&size=128' }}"
                alt="{{ $portfolio->name ?? $user->name }}"
                class="w-32 h-32 rounded-full mx-auto md:mx-0 md:mr-8 border-4 border-indigo-600"
            />
            <div class="text-center md:text-left mt-4 md:mt-0">
                <h1 class="text-4xl font-black text-gray-900">
                    {{ $portfolio->name ?? $user->name }}
                </h1>
                @if($portfolio->hero_headline)
                    <h2 class="text-2xl font-semibold text-indigo-600 mt-1">
                        {{ $portfolio->hero_headline }}
                    </h2>
                @endif
                @if($portfolio->bio)
                    <div class="text-gray-600 mt-4 prose max-w-none">
                        {!! $portfolio->bio !!}
                    </div>
                @endif
                <div class="mt-6 flex justify-center md:justify-start space-x-4">
                    @if($portfolio->email)
                        <a href="mailto:{{ $portfolio->email }}" class="text-gray-500 hover:text-indigo-600" aria-label="Email">
                            <i class="fas fa-envelope fa-2x"></i>
                        </a>
                    @endif
                    @if($portfolio->linkedin_url)
                        <a href="{{ $portfolio->linkedin_url }}" target="_blank" class="text-gray-500 hover:text-indigo-600" aria-label="LinkedIn">
                            <i class="fab fa-linkedin fa-2x"></i>
                        </a>
                    @endif
                    @if($portfolio->github_url)
                        <a href="{{ $portfolio->github_url }}" target="_blank" class="text-gray-500 hover:text-indigo-600" aria-label="GitHub">
                            <i class="fab fa-github fa-2x"></i>
                        </a>
                    @endif
                    @if($portfolio->phone)
                        <a href="tel:{{ $portfolio->phone }}" class="text-gray-500 hover:text-indigo-600" aria-label="Téléphone">
                            <i class="fas fa-phone fa-2x"></i>
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </header>

    <main class="container mx-auto max-w-5xl p-8">
        @if($portfolio->skills && count($portfolio->skills) > 0)
        <section id="skills" class="mb-12">
            <h2 class="text-3xl font-bold text-gray-900 mb-6">
                {{ $portfolio->skills_title ?? 'Mes Compétences' }}
            </h2>
            <div class="flex flex-wrap gap-4">
                @foreach($portfolio->skills as $skill)
                <div class="bg-white shadow-md rounded-full px-6 py-2 flex items-center">
                    <span class="text-indigo-600 text-xl mr-3">{{ $skill['icon'] ?? '🏷️' }}</span>
                    <span class="font-semibold text-gray-700">{{ $skill['name'] ?? '' }}</span>
                </div>
                @endforeach
            </div>
        </section>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            @if($portfolio->projects && count($portfolio->projects) > 0)
            <div class="lg:col-span-2">
                <section id="projects">
                    <h2 class="text-3xl font-bold text-gray-900 mb-6">
                        {{ $portfolio->projects_title ?? 'Mes Projets' }}
                    </h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        @foreach($portfolio->projects as $index => $project)
                        <div
                            class="bg-white shadow-xl rounded-lg overflow-hidden transform hover:scale-105 transition-transform duration-300 cursor-pointer project-card"
                            data-project-index="{{ $index }}"
                            onclick="openProjectModal({{ $index }})"
                        >
                            <div class="p-6">
                                <div class="flex items-center mb-3">
                                    <span class="fas {{ $project['icon'] ?? 'fa-cube' }} text-indigo-600 text-2xl mr-3"></span>
                                    <h3 class="text-xl font-bold text-gray-800">
                                        {{ $project['title'] ?? '' }}
                                    </h3>
                                </div>
                                @if(isset($project['short_description']) && $project['short_description'])
                                    <p class="text-gray-600 text-sm">
                                        {{ $project['short_description'] }}
                                    </p>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                </section>
            </div>
            @endif

            @if($portfolio->timeline && count($portfolio->timeline) > 0)
            <div class="{{ ($portfolio->projects && count($portfolio->projects) > 0) ? 'lg:col-span-1' : 'lg:col-span-3' }}">
                <section id="timeline">
                    <h2 class="text-3xl font-bold text-gray-900 mb-6">
                        {{ $portfolio->timeline_title ?? 'Mon Parcours' }}
                    </h2>
                    <div class="relative border-l-2 border-indigo-200 ml-3">
                        @foreach($portfolio->timeline as $index => $item)
                        <div class="mb-8 ml-8">
                            <span class="absolute -left-4 flex items-center justify-center w-8 h-8 bg-indigo-600 rounded-full text-white">
                                {{ $item['icon'] ?? '🎓' }}
                            </span>
                            <h3 class="text-lg font-semibold text-gray-900">
                                {{ $item['title'] ?? '' }}
                            </h3>
                            @if(isset($item['organization']) && $item['organization'])
                                <span class="block text-sm font-normal text-indigo-500">{{ $item['organization'] }}</span>
                            @endif
                            @if(isset($item['dates']) && $item['dates'])
                                <time class="block text-sm font-normal text-gray-500">{{ $item['dates'] }}</time>
                            @endif
                            @if(isset($item['details']) && $item['details'])
                                <div class="mt-2 text-base font-normal text-gray-600 prose prose-sm max-w-none">
                                    {!! $item['details'] !!}
                                </div>
                            @endif
                        </div>
                        @endforeach
                    </div>
                </section>
            </div>
            @endif
        </div>
    </main>
</div>

<!-- Modal pour afficher les détails d'un projet -->
<div id="projectModal" class="fixed inset-0 bg-black bg-opacity-75 hidden items-center justify-center z-50 p-4">
    <div class="modal-content bg-white rounded-lg shadow-2xl p-8 w-full max-w-3xl transform scale-100">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-3xl font-bold text-indigo-700" id="modalTitle"></h2>
            <button onclick="closeProjectModal()" class="text-gray-500 hover:text-indigo-500 text-3xl">
                &times;
            </button>
        </div>
        <div class="prose max-w-none" id="modalContent"></div>
        <div class="mt-6 pt-4 border-t">
            <a id="modalLink" href="#" target="_blank" class="hidden inline-flex items-center px-4 py-2 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-800">
                Voir le Projet
                <i class="fas fa-arrow-up-right-from-square ml-2"></i>
            </a>
        </div>
    </div>
</div>

<script>
const projects = @json($portfolio->projects ?? []);
const projectsData = Object.values(projects);

function openProjectModal(index) {
    const project = projectsData[index];
    if (!project) return;

    document.getElementById('modalTitle').textContent = project.title || '';

    // Afficher la description courte si details_html n'existe pas
    document.getElementById('modalContent').innerHTML = project.details_html ||
        '<p>' + (project.short_description || '') + '</p>';

    // Afficher le lien s'il existe
    const modalLink = document.getElementById('modalLink');
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

// Fermer le modal en cliquant en dehors
document.addEventListener('click', function(e) {
    const modal = document.getElementById('projectModal');
    if (e.target === modal) {
        closeProjectModal();
    }
});
</script>

</body>
</html>

