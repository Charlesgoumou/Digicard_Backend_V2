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
                    {{-- Bouton Prendre Rendez-vous --}}
                    @if(isset($appointmentSetting) && $appointmentSetting && $appointmentSetting->is_enabled)
                        <button
                            onclick="openBookingModal()"
                            class="text-gray-500 hover:text-sky-500 transition-colors relative group"
                            aria-label="Prendre rendez-vous"
                            title="Prendre rendez-vous"
                        >
                            <i class="fas fa-calendar-check fa-2x"></i>
                            <span class="absolute -top-1 -right-1 w-3 h-3 bg-sky-500 rounded-full animate-pulse"></span>
                        </button>
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

{{-- Modal de Réservation de Rendez-vous --}}
@if(isset($appointmentSetting) && $appointmentSetting && $appointmentSetting->is_enabled)
<div id="bookingModal" class="fixed inset-0 bg-black bg-opacity-60 hidden items-center justify-center z-50 p-4 backdrop-blur-sm">
    <div class="modal-content bg-gradient-to-br from-slate-800 to-slate-900 rounded-2xl shadow-2xl w-full max-w-lg border border-slate-700 overflow-hidden">
        <!-- Header -->
        <div class="relative p-6 border-b border-slate-700/50 bg-gradient-to-r from-sky-500/10 to-indigo-500/10">
            <button onclick="closeBookingModal()" class="absolute top-4 right-4 w-8 h-8 flex items-center justify-center rounded-full bg-slate-700/50 hover:bg-red-500/80 text-slate-400 hover:text-white transition-all">
                <i class="fas fa-times"></i>
            </button>
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-sky-500 to-indigo-500 flex items-center justify-center shadow-lg">
                    <i class="fas fa-calendar text-white text-xl"></i>
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
        <div class="p-6 max-h-96 overflow-y-auto">
            <!-- Étape 1: Choix de la date -->
            <div id="bookingStep1" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">📅 Choisissez une date</label>
                    <input type="date" id="bookingDate" class="w-full bg-slate-700/50 border border-slate-600 rounded-xl py-3 px-4 text-white focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500" />
                </div>
                <div id="loadingSlots" class="hidden flex items-center justify-center py-8">
                    <div class="animate-spin rounded-full h-10 w-10 border-b-2 border-sky-500"></div>
                </div>
                <div id="noDateSelected" class="text-center py-8">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-slate-700/50 flex items-center justify-center">
                        <i class="fas fa-calendar text-slate-500 text-2xl"></i>
                    </div>
                    <p class="text-slate-400">Sélectionnez une date pour voir les créneaux disponibles</p>
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
        <div id="bookingFooter" class="p-6 border-t border-slate-700/50">
            <button id="continueBtn" onclick="goToNextBookingStep()" class="hidden w-full bg-sky-500 hover:bg-sky-600 text-white py-3 px-6 rounded-xl font-semibold transition-colors flex items-center justify-center gap-2">
                <span id="continueBtnText">Choisir un créneau</span>
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </div>
</div>
@endif

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

function openBookingModal() {
    resetBookingModal();
    document.getElementById('bookingModal').classList.remove('hidden');
    document.getElementById('bookingModal').classList.add('flex');
    // Set min date to today
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('bookingDate').setAttribute('min', today);
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
    document.getElementById('bookingDate').value = '';
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

// Fetch slots when date changes
document.getElementById('bookingDate').addEventListener('change', async function() {
    selectedDate = this.value;
    selectedSlot = null;
    if (!selectedDate) {
        document.getElementById('noDateSelected').classList.remove('hidden');
        updateBookingFooter();
        return;
    }
    document.getElementById('noDateSelected').classList.add('hidden');
    document.getElementById('loadingSlots').classList.remove('hidden');
    try {
        let url = `${apiBaseUrl}/api/user/${userId}/slots?date=${selectedDate}`;
        if (orderId) url += `&order_id=${orderId}`;
        const response = await fetch(url);
        const data = await response.json();
        availableSlots = data.available_slots || [];
        document.getElementById('loadingSlots').classList.add('hidden');
        if (availableSlots.length > 0) {
            showBookingStep(2);
            renderSlots();
        } else {
            updateBookingFooter();
        }
    } catch (error) {
        console.error('Error fetching slots:', error);
        document.getElementById('loadingSlots').classList.add('hidden');
        availableSlots = [];
        updateBookingFooter();
    }
});

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

