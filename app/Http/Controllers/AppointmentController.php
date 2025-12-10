<?php

namespace App\Http\Controllers;

use App\Mail\AppointmentBooked;
use App\Models\Appointment;
use App\Models\AppointmentSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AppointmentController extends Controller
{
    /**
     * Mettre à jour ou créer la configuration des rendez-vous du propriétaire.
     * Route protégée par auth:sanctum.
     * 
     * IMPORTANT: Chaque commande peut avoir sa propre configuration de rendez-vous.
     * Si order_id est fourni, la configuration est spécifique à cette commande.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateSettings(Request $request)
    {
        Log::info('[AppointmentController@updateSettings] Données reçues', [
            'all_data' => $request->all(),
            'user_id' => $request->user()->id,
        ]);

        $validated = $request->validate([
            'order_id' => 'nullable|exists:orders,id',
            'is_enabled' => 'sometimes|boolean',
            'weekly_availability' => 'sometimes|array',
        ]);

        Log::info('[AppointmentController@updateSettings] Données validées', [
            'validated' => $validated,
            'has_weekly_availability' => isset($validated['weekly_availability']),
            'has_date_rules' => isset($validated['weekly_availability']['date_rules']),
            'date_rules_count' => isset($validated['weekly_availability']['date_rules']) ? count($validated['weekly_availability']['date_rules']) : 0,
        ]);

        $user = $request->user();
        $orderId = $validated['order_id'] ?? null;

        // Vérifier que la commande appartient bien à l'utilisateur si order_id est fourni
        if ($orderId) {
            $order = \App\Models\Order::where('id', $orderId)
                ->where('user_id', $user->id)
                ->first();

            if (!$order) {
                return response()->json([
                    'message' => 'Commande non trouvée ou non autorisée.',
                ], 404);
            }
        }

        // Valider les règles de dates si weekly_availability est fourni
        if (isset($validated['weekly_availability']) && isset($validated['weekly_availability']['date_rules'])) {
            $errors = $this->validateDateRules($validated['weekly_availability']['date_rules']);
            if (!empty($errors)) {
                return response()->json([
                    'message' => 'Erreurs de validation dans les règles de dates.',
                    'errors' => $errors,
                ], 422);
            }
        }

        // Mettre à jour ou créer la configuration pour cette commande spécifique
        $settings = AppointmentSetting::updateOrCreate(
            [
                'user_id' => $user->id,
                'order_id' => $orderId,
            ],
            [
                'is_enabled' => $validated['is_enabled'] ?? false,
                'weekly_availability' => $validated['weekly_availability'] ?? AppointmentSetting::getDefaultWeeklyAvailability(),
            ]
        );

        Log::info('[AppointmentController@updateSettings] Configuration sauvegardée', [
            'settings_id' => $settings->id,
            'user_id' => $settings->user_id,
            'order_id' => $settings->order_id,
            'is_enabled' => $settings->is_enabled,
            'weekly_availability_date_rules_count' => isset($settings->weekly_availability['date_rules']) 
                ? count($settings->weekly_availability['date_rules']) 
                : 0,
        ]);

        return response()->json([
            'message' => 'Configuration des rendez-vous mise à jour avec succès.',
            'settings' => $settings,
        ]);
    }

    /**
     * Valider les règles de dates pour s'assurer qu'elles sont valides.
     * 
     * @param array $dateRules
     * @return array Tableau d'erreurs (vide si aucune erreur)
     */
    private function validateDateRules(array $dateRules): array
    {
        $errors = [];
        $today = Carbon::today();

        foreach ($dateRules as $index => $rule) {
            // Vérifier le type de règle
            $type = $rule['type'] ?? null;
            if (!in_array($type, ['specific', 'recurring_month'])) {
                $errors["rule_{$index}_type"] = "Le type de règle doit être 'specific' ou 'recurring_month'.";
                continue;
            }

            // Pour les dates spécifiques, vérifier qu'elles sont dans le futur
            if ($type === 'specific') {
                $dates = $rule['dates'] ?? [];
                foreach ($dates as $dateIndex => $dateString) {
                    try {
                        $date = Carbon::parse($dateString);
                        if ($date->isBefore($today)) {
                            $errors["rule_{$index}_date_{$dateIndex}"] = "La date {$dateString} est dans le passé. Seules les dates futures sont autorisées.";
                        }
                    } catch (\Exception $e) {
                        $errors["rule_{$index}_date_{$dateIndex}"] = "Format de date invalide : {$dateString}.";
                    }
                }
            }

            // Pour la récurrence mensuelle, vérifier que le mois/année contient encore des jours futurs
            if ($type === 'recurring_month') {
                $month = $rule['month'] ?? null;
                $year = $rule['year'] ?? null;
                $dayOfWeek = $rule['day_of_week'] ?? null;
                
                if ($month && $year && $dayOfWeek) {
                    try {
                        $firstDayOfMonth = Carbon::create($year, $month, 1);
                        $lastDayOfMonth = $firstDayOfMonth->copy()->endOfMonth();
                        
                        // Si le mois est complètement dans le passé, rejeter
                        if ($lastDayOfMonth->isBefore($today)) {
                            $errors["rule_{$index}_month"] = "Le mois {$month}/{$year} est complètement dans le passé. Seuls les mois contenant des jours futurs sont autorisés.";
                        } else {
                            // Vérifier qu'il reste au moins un jour de ce type dans le mois
                            $hasFutureDay = false;
                            $currentDate = max($today, $firstDayOfMonth);
                            
                            while ($currentDate->lte($lastDayOfMonth)) {
                                if ($currentDate->dayOfWeekIso == $dayOfWeek) {
                                    $hasFutureDay = true;
                                    break;
                                }
                                $currentDate->addDay();
                            }
                            
                            if (!$hasFutureDay) {
                                $errors["rule_{$index}_month"] = "Il ne reste plus de {$this->getDayName($dayOfWeek)} dans le mois {$month}/{$year}.";
                            }
                        }
                    } catch (\Exception $e) {
                        $errors["rule_{$index}_month"] = "Mois/année invalide : {$month}/{$year}.";
                    }
                }
            }

            // Vérifier les chevauchements de créneaux dans cette règle
            $slots = $rule['slots'] ?? [];
            for ($i = 0; $i < count($slots); $i++) {
                for ($j = $i + 1; $j < count($slots); $j++) {
                    if ($this->slotsOverlap($slots[$i], $slots[$j])) {
                        $errors["rule_{$index}_slots"] = "Les créneaux se chevauchent : {$slots[$i]['start']} ({$slots[$i]['duration']}min) et {$slots[$j]['start']} ({$slots[$j]['duration']}min).";
                        break 2; // Sortir des deux boucles
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Vérifier si deux créneaux se chevauchent.
     * 
     * @param array $slot1 ['start' => 'HH:mm', 'duration' => int]
     * @param array $slot2 ['start' => 'HH:mm', 'duration' => int]
     * @return bool
     */
    private function slotsOverlap(array $slot1, array $slot2): bool
    {
        $start1 = Carbon::parse("2000-01-01 {$slot1['start']}");
        $end1 = $start1->copy()->addMinutes($slot1['duration']);
        
        $start2 = Carbon::parse("2000-01-01 {$slot2['start']}");
        $end2 = $start2->copy()->addMinutes($slot2['duration']);
        
        // Deux créneaux se chevauchent si : (start1 < end2) && (end1 > start2)
        return $start1->lt($end2) && $end1->gt($start2);
    }

    /**
     * Obtenir le nom du jour de la semaine en français.
     * 
     * @param int $dayOfWeek (1 = Lundi, 7 = Dimanche)
     * @return string
     */
    private function getDayName(int $dayOfWeek): string
    {
        $days = [
            1 => 'lundi',
            2 => 'mardi',
            3 => 'mercredi',
            4 => 'jeudi',
            5 => 'vendredi',
            6 => 'samedi',
            7 => 'dimanche',
        ];
        
        return $days[$dayOfWeek] ?? 'jour';
    }

    /**
     * Récupérer la configuration actuelle de l'utilisateur pour une commande spécifique.
     * Route protégée par auth:sanctum.
     * 
     * IMPORTANT: Si order_id est fourni, retourne la configuration de cette commande.
     * Sinon, retourne les valeurs par défaut (chaque commande a sa propre config).
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSettings(Request $request)
    {
        $user = $request->user();
        $orderId = $request->query('order_id');

        // Vérifier que la commande appartient bien à l'utilisateur si order_id est fourni
        if ($orderId) {
            $order = \App\Models\Order::where('id', $orderId)
                ->where('user_id', $user->id)
                ->first();

            if (!$order) {
                return response()->json([
                    'message' => 'Commande non trouvée ou non autorisée.',
                ], 404);
            }

            // Récupérer la configuration spécifique à cette commande
            $settings = AppointmentSetting::where('user_id', $user->id)
                ->where('order_id', $orderId)
                ->first();
        } else {
            // Sans order_id, retourner les valeurs par défaut
            $settings = null;
        }

        // Si aucune configuration n'existe, retourner les valeurs par défaut
        if (!$settings) {
            return response()->json([
                'settings' => [
                    'id' => null,
                    'order_id' => $orderId ? (int) $orderId : null,
                    'is_enabled' => false,
                    'weekly_availability' => AppointmentSetting::getDefaultWeeklyAvailability(),
                ],
            ]);
        }

        return response()->json([
            'settings' => $settings,
        ]);
    }

    /**
     * Récupérer les créneaux disponibles pour une date donnée (route publique).
     * 
     * Cette méthode :
     * 1. Détermine le jour de la semaine (1=Lundi, 7=Dimanche)
     * 2. Récupère la configuration de ce jour (spécifique à la commande si order_id fourni)
     * 3. Récupère les rendez-vous confirmés de ce jour
     * 4. Calcule les créneaux libres en excluant les conflits
     *
     * @param Request $request
     * @param User $user Le propriétaire de la carte
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPublicSlots(Request $request, User $user)
    {
        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'order_id' => 'nullable|integer',
        ]);

        $date = Carbon::parse($validated['date']);
        $orderId = isset($validated['order_id']) ? (int) $validated['order_id'] : ($request->query('order_id') ? (int) $request->query('order_id') : null);
        $dayOfWeek = $date->dayOfWeekIso; // 1 = Lundi, 7 = Dimanche
        
        // Log pour déboguer
        Log::info('getPublicSlots called', [
            'user_id' => $user->id,
            'order_id' => $orderId,
            'order_id_type' => gettype($orderId),
            'order_id_from_validated' => $validated['order_id'] ?? 'not set',
            'order_id_from_query' => $request->query('order_id'),
            'date' => $validated['date'],
            'all_query_params' => $request->query(),
        ]);
        
        // Vérifier que la date n'est pas dans le passé
        if ($date->isBefore(Carbon::today())) {
            return response()->json([
                'available_slots' => [],
                'message' => 'La date sélectionnée est dans le passé.',
            ]);
        }

        // Récupérer la configuration des rendez-vous spécifique à la commande
        // IMPORTANT: Ne PAS faire de fallback sur la configuration générale
        // Les créneaux doivent être récupérés UNIQUEMENT pour la commande spécifique
        $settings = null;
        if ($orderId) {
            $settings = AppointmentSetting::where('user_id', $user->id)
                ->where('order_id', $orderId)
                ->first();
            
            Log::info('AppointmentSetting query result', [
                'user_id' => $user->id,
                'order_id' => $orderId,
                'found' => $settings ? 'yes' : 'no',
                'is_enabled' => $settings ? $settings->is_enabled : null,
            ]);
        } else {
            Log::warning('getPublicSlots: order_id not provided', [
                'user_id' => $user->id,
            ]);
        }

        // Si les rendez-vous ne sont pas activés ou si la configuration n'existe pas
        if (!$settings) {
            return response()->json([
                'available_slots' => [],
                'message' => 'Aucune configuration de rendez-vous trouvée pour cette commande.',
            ]);
        }
        
        if (!$settings->is_enabled) {
            return response()->json([
                'available_slots' => [],
                'message' => 'Les rendez-vous ne sont pas activés pour cette commande.',
            ]);
        }

        // Récupérer les créneaux configurés pour cette date spécifique
        $configuredSlots = $settings->getSlotsForDate($date);
        
        Log::info('Configured slots for date', [
            'date' => $date->format('Y-m-d'),
            'day_of_week' => $date->dayOfWeekIso,
            'day_name' => $date->locale('fr')->isoFormat('dddd'),
            'slots_count' => count($configuredSlots),
            'slots' => $configuredSlots,
            'is_today' => $date->isToday(),
            'now' => Carbon::now()->format('Y-m-d H:i:s'),
            'weekly_availability_structure' => isset($settings->weekly_availability['date_rules']) ? 'new (date_rules)' : 'old (days)',
            'weekly_availability_sample' => $settings->weekly_availability ? array_slice($settings->weekly_availability, 0, 2, true) : null,
        ]);

        if (empty($configuredSlots)) {
            Log::warning('getPublicSlots: No configured slots found for date', [
                'date' => $date->format('Y-m-d'),
                'day_of_week' => $date->dayOfWeekIso,
                'order_id' => $orderId,
                'user_id' => $user->id,
            ]);
            return response()->json([
                'available_slots' => [],
                'message' => 'Aucun créneau configuré pour ce jour.',
            ]);
        }

        // Récupérer tous les rendez-vous CONFIRMÉS de ce jour pour cet utilisateur
        $existingAppointments = Appointment::where('user_id', $user->id)
            ->whereDate('start_time', $date)
            ->where('status', Appointment::STATUS_CONFIRMED)
            ->get();

        $availableSlots = [];

        foreach ($configuredSlots as $slot) {
            $slotStart = Carbon::parse($validated['date'] . ' ' . $slot['start']);
            $slotEnd = $slotStart->copy()->addMinutes($slot['duration']);

            // Si c'est aujourd'hui, vérifier que le créneau n'est pas déjà passé
            if ($date->isToday() && $slotStart->isBefore(Carbon::now())) {
                Log::info('getPublicSlots: Slot filtered (past time for today)', [
                    'slot_start' => $slotStart->format('Y-m-d H:i:s'),
                    'now' => Carbon::now()->format('Y-m-d H:i:s'),
                ]);
                continue;
            }

            // Vérifier s'il y a un conflit avec un rendez-vous existant
            $hasConflict = $this->hasConflict($slotStart, $slotEnd, $existingAppointments);

            if ($hasConflict) {
                Log::info('getPublicSlots: Slot filtered (conflict)', [
                    'slot_start' => $slotStart->format('Y-m-d H:i:s'),
                    'slot_end' => $slotEnd->format('Y-m-d H:i:s'),
                ]);
            }

            if (!$hasConflict) {
                $availableSlots[] = [
                    'start' => $slotStart->format('H:i'),
                    'end' => $slotEnd->format('H:i'),
                    'duration' => $slot['duration'],
                ];
            }
        }

        Log::info('getPublicSlots: Final response', [
            'available_slots_count' => count($availableSlots),
            'configured_slots_count' => count($configuredSlots),
            'existing_appointments_count' => $existingAppointments->count(),
        ]);

        return response()->json([
            'available_slots' => $availableSlots,
            'date' => $validated['date'],
            'day_of_week' => $dayOfWeek,
            'message' => count($availableSlots) > 0 ? null : 'Aucun créneau disponible pour cette date.',
        ]);
    }

    /**
     * Créer un nouveau rendez-vous (route publique).
     *
     * @param Request $request
     * @param User $user Le propriétaire de la carte
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request, User $user)
    {
        $validated = $request->validate([
            'visitor_name' => 'required|string|max:255',
            'visitor_email' => 'required|email|max:255',
            'visitor_phone' => 'nullable|string|max:50',
            'message' => 'nullable|string|max:1000',
            'date' => 'required|date_format:Y-m-d',
            'start_time' => 'required|date_format:H:i',
            'order_id' => 'nullable|integer',
        ]);

        $date = Carbon::parse($validated['date']);
        $orderId = $validated['order_id'] ?? $request->query('order_id');

        // Vérifier que la date n'est pas dans le passé
        if ($date->isBefore(Carbon::today())) {
            return response()->json([
                'message' => 'La date sélectionnée est dans le passé.',
            ], 422);
        }

        // Récupérer la configuration des rendez-vous spécifique à la commande
        $settings = null;
        if ($orderId) {
            $settings = AppointmentSetting::where('user_id', $user->id)
                ->where('order_id', $orderId)
                ->first();
        }
        
        // Fallback sur la configuration générale de l'utilisateur si pas de config spécifique
        if (!$settings) {
            $settings = AppointmentSetting::where('user_id', $user->id)
                ->whereNull('order_id')
                ->first();
        }

        if (!$settings || !$settings->is_enabled) {
            return response()->json([
                'message' => 'Les rendez-vous ne sont pas activés pour cet utilisateur.',
            ], 422);
        }

        // Récupérer les créneaux configurés pour cette date spécifique
        $configuredSlots = $settings->getSlotsForDate($date);

        // Trouver le créneau correspondant à l'heure demandée
        $matchingSlot = null;
        foreach ($configuredSlots as $slot) {
            if ($slot['start'] === $validated['start_time']) {
                $matchingSlot = $slot;
                break;
            }
        }

        if (!$matchingSlot) {
            return response()->json([
                'message' => 'Le créneau demandé n\'existe pas dans la configuration.',
            ], 422);
        }

        // Calculer les heures de début et fin
        $startTime = Carbon::parse($validated['date'] . ' ' . $validated['start_time']);
        $endTime = $startTime->copy()->addMinutes($matchingSlot['duration']);

        // Si c'est aujourd'hui, vérifier que le créneau n'est pas déjà passé
        if ($date->isToday() && $startTime->isBefore(Carbon::now())) {
            return response()->json([
                'message' => 'Ce créneau est déjà passé.',
            ], 422);
        }

        // Utiliser une transaction avec verrouillage pour éviter les race conditions
        try {
            $appointment = DB::transaction(function () use ($user, $validated, $startTime, $endTime) {
                // Verrouiller les rendez-vous existants pour ce créneau (FOR UPDATE)
                $conflictingAppointment = Appointment::where('user_id', $user->id)
                    ->where('status', Appointment::STATUS_CONFIRMED)
                    ->where(function ($query) use ($startTime, $endTime) {
                        // Logique de détection de chevauchement :
                        // (NewStart < ExistingEnd) && (NewEnd > ExistingStart)
                        $query->where('start_time', '<', $endTime)
                              ->where('end_time', '>', $startTime);
                    })
                    ->lockForUpdate()
                    ->first();

                if ($conflictingAppointment) {
                    throw new \Exception('SLOT_TAKEN');
                }

                // Créer le rendez-vous
                $orderId = isset($validated['order_id']) ? (int) $validated['order_id'] : null;
                return Appointment::create([
                    'user_id' => $user->id,
                    'order_id' => $orderId,
                    'visitor_name' => $validated['visitor_name'],
                    'visitor_email' => $validated['visitor_email'],
                    'visitor_phone' => $validated['visitor_phone'] ?? null,
                    'message' => $validated['message'] ?? null,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'status' => Appointment::STATUS_CONFIRMED,
                ]);
            });

            // Recharger le rendez-vous avec la relation user pour l'email
            $appointment->load('user');

            // Préparer la réponse immédiatement
            $responseData = [
                'message' => 'Rendez-vous réservé avec succès.',
                'appointment' => [
                    'id' => $appointment->id,
                    'visitor_name' => $appointment->visitor_name,
                    'visitor_email' => $appointment->visitor_email,
                    'start_time' => $appointment->start_time->format('Y-m-d H:i'),
                    'end_time' => $appointment->end_time->format('Y-m-d H:i'),
                    'status' => $appointment->status,
                ],
            ];

            // Envoyer l'email de notification en file d'attente (non-bloquant)
            // Le Mailable implémente ShouldQueue donc il sera traité en arrière-plan
            try {
                Mail::to($user->email)->queue(new AppointmentBooked($appointment));
                Log::info('Email de notification de rendez-vous mis en file d\'attente', [
                    'appointment_id' => $appointment->id,
                    'owner_email' => $user->email,
                    'visitor_email' => $appointment->visitor_email,
                ]);
            } catch (\Exception $mailException) {
                // Si la queue échoue, envoyer de manière synchrone
                Log::warning('Queue non disponible, envoi synchrone de l\'email', [
                    'appointment_id' => $appointment->id,
                    'error' => $mailException->getMessage(),
                ]);
                try {
                    Mail::to($user->email)->send(new AppointmentBooked($appointment));
                } catch (\Exception $syncMailException) {
                    Log::error('Erreur lors de l\'envoi synchrone de l\'email', [
                        'appointment_id' => $appointment->id,
                        'error' => $syncMailException->getMessage(),
                    ]);
                }
            }

            return response()->json($responseData, 201);

        } catch (\Exception $e) {
            if ($e->getMessage() === 'SLOT_TAKEN') {
                return response()->json([
                    'message' => 'Ce créneau vient d\'être réservé par quelqu\'un d\'autre. Veuillez en choisir un autre.',
                ], 409);
            }

            throw $e;
        }
    }

    /**
     * Vérifie s'il y a un conflit entre un créneau et les rendez-vous existants.
     * Logique de chevauchement : (NewStart < ExistingEnd) && (NewEnd > ExistingStart)
     *
     * @param Carbon $slotStart
     * @param Carbon $slotEnd
     * @param \Illuminate\Support\Collection $existingAppointments
     * @return bool
     */
    private function hasConflict(Carbon $slotStart, Carbon $slotEnd, $existingAppointments): bool
    {
        foreach ($existingAppointments as $appointment) {
            $existingStart = $appointment->start_time;
            $existingEnd = $appointment->end_time;

            // Logique de détection de chevauchement
            if ($slotStart < $existingEnd && $slotEnd > $existingStart) {
                return true;
            }
        }

        return false;
    }

    /**
     * Récupère tous les rendez-vous de l'utilisateur connecté.
     * Route protégée par auth:sanctum.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        // Pour les requêtes GET, utiliser query() au lieu de validate()
        $orderId = $request->query('order_id');
        
        // Valider et convertir en entier si fourni
        if ($orderId !== null) {
            $orderId = filter_var($orderId, FILTER_VALIDATE_INT);
            if ($orderId === false) {
                return response()->json([
                    'message' => 'Le paramètre order_id doit être un entier valide.',
                ], 422);
            }
        }

        // Vérifier que la commande appartient bien à l'utilisateur si order_id est fourni
        if ($orderId) {
            $order = \App\Models\Order::where('id', $orderId)
                ->where('user_id', $user->id)
                ->first();

            if (!$order) {
                return response()->json([
                    'message' => 'Commande non trouvée ou non autorisée.',
                ], 404);
            }
        }

        // Récupérer les rendez-vous de l'utilisateur
        $query = Appointment::where('user_id', $user->id)
            ->orderBy('start_time', 'desc');

        // Filtrer par order_id si fourni
        // Inclure aussi les rendez-vous avec order_id=NULL (créés avant l'implémentation)
        if ($orderId) {
            $query->where(function($q) use ($orderId) {
                $q->where('order_id', $orderId)
                  ->orWhereNull('order_id');
            });
        }

        $appointments = $query->get()->map(function ($appointment) {
            return [
                'id' => $appointment->id,
                'visitor_name' => $appointment->visitor_name,
                'visitor_email' => $appointment->visitor_email,
                'visitor_phone' => $appointment->visitor_phone,
                'message' => $appointment->message,
                'start_time' => $appointment->start_time->toIso8601String(),
                'end_time' => $appointment->end_time->toIso8601String(),
                'status' => $appointment->status,
                'duration' => $appointment->getDurationInMinutes(),
                'order_id' => $appointment->order_id,
            ];
        });

        // Log pour débogage
        \Log::info('[AppointmentController@index] Rendez-vous récupérés', [
            'user_id' => $user->id,
            'order_id' => $orderId,
            'count' => $appointments->count(),
            'appointments' => $appointments->toArray(),
        ]);

        return response()->json([
            'appointments' => $appointments,
        ]);
    }

    /**
     * Annule un rendez-vous.
     * Route protégée par auth:sanctum.
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancel(Request $request, $id)
    {
        $user = $request->user();

        $appointment = Appointment::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$appointment) {
            return response()->json([
                'message' => 'Rendez-vous non trouvé ou non autorisé.',
            ], 404);
        }

        // Vérifier que le rendez-vous peut être annulé (confirmé et à venir)
        if ($appointment->status !== Appointment::STATUS_CONFIRMED) {
            return response()->json([
                'message' => 'Ce rendez-vous ne peut pas être annulé.',
            ], 422);
        }

        if ($appointment->start_time->isPast()) {
            return response()->json([
                'message' => 'Impossible d\'annuler un rendez-vous passé.',
            ], 422);
        }

        // Annuler le rendez-vous
        $appointment->status = Appointment::STATUS_CANCELLED;
        $appointment->save();

        return response()->json([
            'message' => 'Rendez-vous annulé avec succès. Le créneau est maintenant disponible.',
            'appointment' => [
                'id' => $appointment->id,
                'status' => $appointment->status,
            ],
        ]);
    }
}

