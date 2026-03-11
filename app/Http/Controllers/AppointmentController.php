<?php

namespace App\Http\Controllers;

use App\Mail\AppointmentBooked;
use App\Mail\AppointmentCreatedForVisitor;
use App\Mail\AppointmentCancelledForOwner;
use App\Mail\AppointmentCancelledForVisitor;
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
            // Pour les employés, vérifier via OrderEmployee
            if ($user->role === 'employee') {
                $orderEmployee = \App\Models\OrderEmployee::where('employee_id', $user->id)
                    ->where('order_id', $orderId)
                    ->first();

                if (!$orderEmployee) {
                    return response()->json([
                        'message' => 'Commande non trouvée ou non autorisée.',
                    ], 404);
                }
            } else {
                // Pour les autres utilisateurs (individual, business_admin), vérifier directement
                $order = \App\Models\Order::where('id', $orderId)
                    ->where('user_id', $user->id)
                    ->first();

                if (!$order) {
                    return response()->json([
                        'message' => 'Commande non trouvée ou non autorisée.',
                    ], 404);
                }
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
            // Pour les employés, vérifier via OrderEmployee
            if ($user->role === 'employee') {
                $orderEmployee = \App\Models\OrderEmployee::where('employee_id', $user->id)
                    ->where('order_id', $orderId)
                    ->first();

                if (!$orderEmployee) {
                    return response()->json([
                        'message' => 'Commande non trouvée ou non autorisée.',
                    ], 404);
                }
            } else {
                // Pour les autres utilisateurs (individual, business_admin), vérifier directement
                $order = \App\Models\Order::where('id', $orderId)
                    ->where('user_id', $user->id)
                    ->first();

                if (!$order) {
                    return response()->json([
                        'message' => 'Commande non trouvée ou non autorisée.',
                    ], 404);
                }
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
     * Récupérer toutes les dates disponibles avec des créneaux (route publique).
     * 
     * Cette méthode retourne toutes les dates qui ont au moins un créneau disponible
     * dans les prochains jours (par défaut 60 jours).
     *
     * @param Request $request
     * @param User $user Le propriétaire de la carte
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAvailableDates(Request $request, User $user)
    {
        $validated = $request->validate([
            'order_id' => 'nullable|integer',
            'days_ahead' => 'nullable|integer|min:1|max:90', // Maximum 90 jours
        ]);

        $orderId = isset($validated['order_id']) ? (int) $validated['order_id'] : ($request->query('order_id') ? (int) $request->query('order_id') : null);
        $daysAhead = isset($validated['days_ahead']) ? (int) $validated['days_ahead'] : 60; // Par défaut 60 jours, s'assurer que c'est un entier
        
        Log::info('[AppointmentController@getAvailableDates] Début', [
            'user_id' => $user->id,
            'order_id' => $orderId,
            'order_id_type' => gettype($orderId),
            'days_ahead' => $daysAhead,
            'all_query_params' => $request->query(),
            'validated' => $validated,
        ]);
        
        // Récupérer la configuration des rendez-vous spécifique à la commande
        $settings = null;
        if ($orderId) {
            $settings = AppointmentSetting::where('user_id', $user->id)
                ->where('order_id', $orderId)
                ->first();
            
            Log::info('[AppointmentController@getAvailableDates] Recherche de settings avec order_id', [
                'user_id' => $user->id,
                'order_id' => $orderId,
                'settings_found' => $settings ? 'yes' : 'no',
                'is_enabled' => $settings ? $settings->is_enabled : null,
            ]);
        } else {
            Log::warning('[AppointmentController@getAvailableDates] order_id non fourni', [
                'user_id' => $user->id,
            ]);
        }

        // Si les rendez-vous ne sont pas activés ou si la configuration n'existe pas
        if (!$settings || !$settings->is_enabled) {
            Log::warning('[AppointmentController@getAvailableDates] Settings non trouvés ou désactivés', [
                'user_id' => $user->id,
                'order_id' => $orderId,
                'settings_exists' => $settings ? 'yes' : 'no',
                'is_enabled' => $settings ? $settings->is_enabled : null,
            ]);
            return response()->json([
                'available_dates' => [],
                'message' => 'Les rendez-vous ne sont pas activés pour cette commande.',
            ]);
        }

        $today = Carbon::today();
        $endDate = $today->copy()->addDays($daysAhead);
        $availableDates = [];
        
        // OPTIMISATION: Calculer directement toutes les dates disponibles depuis les règles
        // au lieu de parcourir chaque jour individuellement
        $availability = $settings->weekly_availability ?? [];
        $dateRules = $availability['date_rules'] ?? [];
        
        // ✅ CORRECTION : Étendre $endDate pour inclure tous les mois configurés dans les règles
        // Cela permet d'afficher les créneaux même s'ils sont au-delà de la période par défaut (60 jours)
        foreach ($dateRules as $rule) {
            $type = $rule['type'] ?? 'specific';
            
            if ($type === 'specific') {
                // Pour les dates spécifiques, vérifier si elles dépassent $endDate
                $dates = $rule['dates'] ?? [];
                foreach ($dates as $dateString) {
                    try {
                        $ruleDate = Carbon::parse($dateString);
                        if ($ruleDate->gt($endDate)) {
                            $endDate = $ruleDate->copy();
                        }
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            } elseif ($type === 'recurring_month') {
                // Pour les règles récurrentes avec mois/année spécifiés, étendre $endDate
                $ruleMonth = $rule['month'] ?? null;
                $ruleYear = $rule['year'] ?? null;
                
                if ($ruleMonth && $ruleYear) {
                    try {
                        $lastDayOfRuleMonth = Carbon::create($ruleYear, $ruleMonth, 1)->endOfMonth();
                        if ($lastDayOfRuleMonth->gt($endDate) && $lastDayOfRuleMonth->gte($today)) {
                            $endDate = $lastDayOfRuleMonth->copy();
                        }
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }
        }
        
        // Récupérer tous les rendez-vous CONFIRMÉS dans la période étendue
        $existingAppointments = Appointment::where('user_id', $user->id)
            ->where('status', Appointment::STATUS_CONFIRMED)
            ->whereBetween('start_time', [$today->copy()->startOfDay(), $endDate->copy()->endOfDay()])
            ->get()
            ->groupBy(function ($appointment) {
                return $appointment->start_time->format('Y-m-d');
            });
        
        $startTime = microtime(true);
        
        Log::info('[AppointmentController@getAvailableDates] Structure détectée', [
            'user_id' => $user->id,
            'order_id' => $orderId,
            'has_date_rules' => !empty($dateRules),
            'date_rules_count' => count($dateRules),
            'has_old_structure' => !empty($availability) && empty($dateRules),
            'availability_keys' => array_keys($availability),
            'initial_end_date' => $today->copy()->addDays($daysAhead)->format('Y-m-d'),
            'extended_end_date' => $endDate->format('Y-m-d'),
            'end_date_extended' => $endDate->gt($today->copy()->addDays($daysAhead)),
        ]);
        
        // Si c'est la nouvelle structure (date_rules), calculer directement les dates
        if (!empty($dateRules)) {
            // Construire un index des dates avec leurs créneaux directement depuis les règles
            // Cela évite d'appeler getSlotsForDate() pour chaque date
            $datesWithSlots = [];
            
            foreach ($dateRules as $ruleIndex => $rule) {
                $type = $rule['type'] ?? 'specific';
                $slots = $rule['slots'] ?? [];
                
                Log::info('[AppointmentController@getAvailableDates] Traitement de la règle', [
                    'user_id' => $user->id,
                    'order_id' => $orderId,
                    'rule_index' => $ruleIndex,
                    'rule_type' => $type,
                    'rule_data' => $rule,
                    'slots_count' => count($slots),
                ]);
                
                if (empty($slots)) {
                    Log::warning('[AppointmentController@getAvailableDates] Règle ignorée (pas de créneaux)', [
                        'user_id' => $user->id,
                        'order_id' => $orderId,
                        'rule_index' => $ruleIndex,
                        'rule_type' => $type,
                    ]);
                    continue; // Pas de créneaux configurés pour cette règle
                }
                
                if ($type === 'specific') {
                    // Dates spécifiques : ajouter directement les dates avec leurs créneaux
                    $dates = $rule['dates'] ?? [];
                    foreach ($dates as $dateString) {
                        try {
                            $date = Carbon::parse($dateString);
                            // Vérifier que la date est dans la période et dans le futur
                            if ($date->gte($today) && $date->lte($endDate)) {
                                $dateKey = $date->format('Y-m-d');
                                // Ajouter les créneaux pour cette date (peut être fusionné avec d'autres règles)
                                if (!isset($datesWithSlots[$dateKey])) {
                                    $datesWithSlots[$dateKey] = [
                                        'date' => $date,
                                        'slots' => []
                                    ];
                                }
                                // Fusionner les créneaux (éviter les doublons)
                                foreach ($slots as $slot) {
                                    $slotKey = $slot['start'] . '_' . $slot['duration'];
                                    if (!isset($datesWithSlots[$dateKey]['slots'][$slotKey])) {
                                        $datesWithSlots[$dateKey]['slots'][$slotKey] = $slot;
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            // Ignorer les dates invalides
                            continue;
                        }
                    }
                } elseif ($type === 'recurring_month') {
                    // Récurrence mensuelle : calculer tous les jours correspondants
                    // Si month/year sont spécifiés, calculer seulement pour ce mois
                    // Sinon, calculer toutes les dates futures qui correspondent au jour de la semaine
                    $ruleDayOfWeek = $rule['day_of_week'] ?? null;
                    $ruleMonth = $rule['month'] ?? null;
                    $ruleYear = $rule['year'] ?? null;
                    
                    if ($ruleDayOfWeek) {
                        try {
                            if ($ruleMonth && $ruleYear) {
                                // Mois et année spécifiés : calculer UNIQUEMENT les dates de ce mois spécifique
                                $firstDayOfMonth = Carbon::create($ruleYear, $ruleMonth, 1);
                                $lastDayOfMonth = $firstDayOfMonth->copy()->endOfMonth();
                                
                                // Vérifier que le mois n'est pas dans le passé
                                if ($lastDayOfMonth->lt($today)) {
                                    // Le mois est dans le passé, ignorer cette règle
                                    continue;
                                }
                                
                                // Parcourir tous les jours du mois pour trouver ceux qui correspondent au jour de la semaine
                                // Commencer au premier jour du mois ou aujourd'hui, selon le plus récent
                                $startDate = $firstDayOfMonth->copy();
                                if ($today->gt($firstDayOfMonth)) {
                                    $startDate = $today->copy();
                                }
                                $currentDate = $startDate->copy();
                                
                                $datesFoundForThisRule = [];
                                
                                Log::info('[AppointmentController@getAvailableDates] Calcul des dates pour recurring_month', [
                                    'user_id' => $user->id,
                                    'order_id' => $orderId,
                                    'rule_day_of_week' => $ruleDayOfWeek,
                                    'rule_month' => $ruleMonth,
                                    'rule_year' => $ruleYear,
                                    'today' => $today->format('Y-m-d'),
                                    'first_day_of_month' => $firstDayOfMonth->format('Y-m-d'),
                                    'last_day_of_month' => $lastDayOfMonth->format('Y-m-d'),
                                    'start_date' => $startDate->format('Y-m-d'),
                                    'end_date' => $endDate->format('Y-m-d'),
                                ]);
                                
                                // ✅ CORRECTION : Parcourir tous les jours du mois configuré, même s'ils dépassent $endDate initial
                                // Car $endDate a déjà été étendu pour inclure ce mois
                                while ($currentDate->lte($lastDayOfMonth)) {
                                    // Ne garder que les dates dans le futur (pas dans le passé)
                                    if ($currentDate->gte($today) && $currentDate->dayOfWeekIso == $ruleDayOfWeek) {
                                        $dateKey = $currentDate->format('Y-m-d');
                                        $datesFoundForThisRule[] = $dateKey;
                                        
                                        // Ajouter les créneaux pour cette date
                                        if (!isset($datesWithSlots[$dateKey])) {
                                            $datesWithSlots[$dateKey] = [
                                                'date' => $currentDate->copy(),
                                                'slots' => []
                                            ];
                                        }
                                        // Fusionner les créneaux (éviter les doublons)
                                        foreach ($slots as $slot) {
                                            $slotKey = $slot['start'] . '_' . $slot['duration'];
                                            if (!isset($datesWithSlots[$dateKey]['slots'][$slotKey])) {
                                                $datesWithSlots[$dateKey]['slots'][$slotKey] = $slot;
                                            }
                                        }
                                    }
                                    $currentDate->addDay();
                                }
                                
                                Log::info('[AppointmentController@getAvailableDates] Règle recurring_month traitée', [
                                    'user_id' => $user->id,
                                    'order_id' => $orderId,
                                    'rule_day_of_week' => $ruleDayOfWeek,
                                    'rule_month' => $ruleMonth,
                                    'rule_year' => $ruleYear,
                                    'first_day_of_month' => $firstDayOfMonth->format('Y-m-d'),
                                    'last_day_of_month' => $lastDayOfMonth->format('Y-m-d'),
                                    'start_date' => $startDate->format('Y-m-d'),
                                    'dates_found' => $datesFoundForThisRule,
                                    'dates_count' => count($datesFoundForThisRule),
                                ]);
                            } else {
                                // Pas de mois/année spécifiés : calculer toutes les dates futures qui correspondent au jour de la semaine
                                // Trouver le prochain jour de la semaine à partir d'aujourd'hui
                                $currentDate = $today->copy();
                                
                                // Avancer jusqu'au prochain jour de la semaine correspondant
                                while ($currentDate->dayOfWeekIso != $ruleDayOfWeek && $currentDate->lte($endDate)) {
                                    $currentDate->addDay();
                                }
                                
                                // Maintenant, calculer toutes les dates qui correspondent à ce jour de la semaine
                                while ($currentDate->lte($endDate)) {
                                    $dateKey = $currentDate->format('Y-m-d');
                                    
                                    // Ajouter les créneaux pour cette date
                                    if (!isset($datesWithSlots[$dateKey])) {
                                        $datesWithSlots[$dateKey] = [
                                            'date' => $currentDate->copy(),
                                            'slots' => []
                                        ];
                                    }
                                    // Fusionner les créneaux (éviter les doublons)
                                    foreach ($slots as $slot) {
                                        $slotKey = $slot['start'] . '_' . $slot['duration'];
                                        if (!isset($datesWithSlots[$dateKey]['slots'][$slotKey])) {
                                            $datesWithSlots[$dateKey]['slots'][$slotKey] = $slot;
                                        }
                                    }
                                    
                                    // Passer à la semaine suivante (ajouter 7 jours)
                                    $currentDate->addWeek();
                                }
                            }
                        } catch (\Exception $e) {
                            // Ignorer les erreurs
                            Log::warning('[AppointmentController@getAvailableDates] Erreur lors du calcul des récurrences mensuelles', [
                                'error' => $e->getMessage(),
                                'rule' => $rule,
                            ]);
                            continue;
                        }
                    }
                }
            }
            
            Log::info('[AppointmentController@getAvailableDates] Dates calculées depuis les règles', [
                'user_id' => $user->id,
                'order_id' => $orderId,
                'total_rules_processed' => count($dateRules),
                'total_dates_calculated' => count($datesWithSlots),
                'dates_keys' => array_keys($datesWithSlots),
                'first_10_dates' => array_slice(array_keys($datesWithSlots), 0, 10),
                'dates_with_multiple_rules' => array_filter($datesWithSlots, function($dateData) {
                    return count($dateData['slots']) > 0;
                }),
            ]);
            
            // Maintenant, vérifier pour chaque date s'il reste au moins un créneau disponible
            foreach ($datesWithSlots as $dateKey => $dateData) {
                $checkDate = $dateData['date'];
                $configuredSlots = array_values($dateData['slots']); // Convertir en array indexé
                
                if (empty($configuredSlots)) {
                    continue; // Pas de créneaux configurés pour ce jour
                }
                
                // Récupérer les rendez-vous de ce jour (depuis le groupe)
                $dayAppointments = $existingAppointments->get($dateKey, collect());
                
                // Vérifier s'il reste au moins un créneau disponible
                $hasAvailableSlot = false;
                
                foreach ($configuredSlots as $slot) {
                    $slotStart = Carbon::parse($checkDate->format('Y-m-d') . ' ' . $slot['start']);
                    $slotEnd = $slotStart->copy()->addMinutes($slot['duration']);
                    
                    // Si c'est aujourd'hui, vérifier que le créneau n'est pas déjà passé
                    if ($checkDate->isToday() && $slotStart->isBefore(Carbon::now())) {
                        continue;
                    }
                    
                    // Vérifier s'il y a un conflit avec un rendez-vous existant
                    $hasConflict = $this->hasConflict($slotStart, $slotEnd, $dayAppointments);
                    
                    if (!$hasConflict) {
                        $hasAvailableSlot = true;
                        break; // Au moins un créneau disponible, on peut ajouter la date
                    }
                }
                
                // Si au moins un créneau est disponible, ajouter la date
                if ($hasAvailableSlot) {
                    $availableDates[] = [
                        'date' => $dateKey,
                        'formatted_date' => $checkDate->locale('fr')->isoFormat('dddd D MMMM YYYY'),
                        'day_name' => $checkDate->locale('fr')->isoFormat('dddd'),
                    ];
                } else {
                    Log::info('[AppointmentController@getAvailableDates] Date exclue (pas de créneau disponible)', [
                        'user_id' => $user->id,
                        'order_id' => $orderId,
                        'date' => $dateKey,
                        'configured_slots_count' => count($configuredSlots),
                        'existing_appointments_count' => $dayAppointments->count(),
                    ]);
                }
            }
        } else {
            // OPTIMISATION : Ancienne structure (jours de la semaine)
            // Au lieu de parcourir tous les jours, calculer directement les jours de la semaine activés
            $enabledDays = [];
            $daySlotsMap = []; // Map des jours avec leurs créneaux
            
            // Identifier les jours de la semaine activés (1=Lundi, 7=Dimanche)
            for ($dayOfWeek = 1; $dayOfWeek <= 7; $dayOfWeek++) {
                $dayKey = (string) $dayOfWeek;
                if (isset($availability[$dayKey]) && ($availability[$dayKey]['enabled'] ?? false)) {
                    $enabledDays[] = $dayOfWeek;
                    $daySlotsMap[$dayOfWeek] = $availability[$dayKey]['slots'] ?? [];
                }
            }
            
            Log::info('[AppointmentController@getAvailableDates] Jours activés détectés', [
                'user_id' => $user->id,
                'order_id' => $orderId,
                'enabled_days' => $enabledDays,
                'days_with_slots' => array_map(function($day) use ($daySlotsMap) {
                    return ['day' => $day, 'slots_count' => count($daySlotsMap[$day] ?? [])];
                }, $enabledDays),
            ]);
            
            if (empty($enabledDays)) {
                // Aucun jour activé, retourner vide
                Log::warning('[AppointmentController@getAvailableDates] Aucun jour activé dans l\'ancienne structure', [
                    'user_id' => $user->id,
                    'order_id' => $orderId,
                    'availability_structure' => $availability,
                ]);
            } else {
                // Calculer toutes les dates correspondant aux jours activés dans la période
                $currentDate = $today->copy();
                $datesChecked = 0;
                $datesWithSlots = 0;
                $datesWithConflicts = 0;
                $maxChecks = $daysAhead; // Limite de sécurité
                
                while ($currentDate->lte($endDate) && $datesChecked < $maxChecks) {
                    $dayOfWeek = $currentDate->dayOfWeekIso; // 1=Lundi, 7=Dimanche
                    
                    // Vérifier si ce jour de la semaine est activé
                    if (in_array($dayOfWeek, $enabledDays)) {
                        $dateKey = $currentDate->format('Y-m-d');
                        $configuredSlots = $daySlotsMap[$dayOfWeek];
                        
                        if (!empty($configuredSlots)) {
                            $datesWithSlots++;
                            
                            // Récupérer les rendez-vous de ce jour (depuis le groupe)
                            $dayAppointments = $existingAppointments->get($dateKey, collect());
                            
                            // Vérifier s'il reste au moins un créneau disponible
                            $hasAvailableSlot = false;
                            
                            foreach ($configuredSlots as $slot) {
                                $slotStart = Carbon::parse($currentDate->format('Y-m-d') . ' ' . $slot['start']);
                                $slotEnd = $slotStart->copy()->addMinutes($slot['duration']);
                                
                                // Si c'est aujourd'hui, vérifier que le créneau n'est pas déjà passé
                                if ($currentDate->isToday() && $slotStart->isBefore(Carbon::now())) {
                                    continue;
                                }
                                
                                // Vérifier s'il y a un conflit avec un rendez-vous existant
                                $hasConflict = $this->hasConflict($slotStart, $slotEnd, $dayAppointments);
                                
                                if (!$hasConflict) {
                                    $hasAvailableSlot = true;
                                    break; // Au moins un créneau disponible, on peut ajouter la date
                                } else {
                                    $datesWithConflicts++;
                                }
                            }
                            
                            // Si au moins un créneau est disponible, ajouter la date
                            if ($hasAvailableSlot) {
                                $availableDates[] = [
                                    'date' => $dateKey,
                                    'formatted_date' => $currentDate->locale('fr')->isoFormat('dddd D MMMM YYYY'),
                                    'day_name' => $currentDate->locale('fr')->isoFormat('dddd'),
                                ];
                            }
                        }
                    }
                    
                    $currentDate->addDay();
                    $datesChecked++;
                }
                
                Log::info('[AppointmentController@getAvailableDates] Traitement ancienne structure terminé', [
                    'user_id' => $user->id,
                    'order_id' => $orderId,
                    'dates_checked' => $datesChecked,
                    'dates_with_slots' => $datesWithSlots,
                    'dates_with_conflicts' => $datesWithConflicts,
                    'available_dates_found' => count($availableDates),
                ]);
            }
        }
        
        // Trier les dates par ordre chronologique
        usort($availableDates, function($a, $b) {
            return strcmp($a['date'], $b['date']);
        });
        
        $endTime = microtime(true);
        $executionTime = round(($endTime - $startTime) * 1000, 2); // en millisecondes
        
        Log::info('[AppointmentController@getAvailableDates] Résultat', [
            'user_id' => $user->id,
            'order_id' => $orderId,
            'available_dates_count' => count($availableDates),
            'execution_time_ms' => $executionTime,
            'first_5_dates' => array_slice($availableDates, 0, 5),
        ]);
        
        return response()->json([
            'available_dates' => $availableDates,
            'count' => count($availableDates),
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

                // Créer le rendez-vous avec un token d'annulation unique
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
                    'cancellation_token' => $this->generateCancellationToken(),
                ]);
            });

            // Recharger le rendez-vous avec la relation user pour l'email
            $appointment->load('user');
            
            // S'assurer que le user est bien défini sur le rendez-vous
            if (!$appointment->user) {
                $appointment->user = $user;
            }

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

            // ✅ Envoyer les emails de notification IMMÉDIATEMENT (synchrone) aux deux parties
            // Le Mailable n'implémente pas ShouldQueue pour garantir l'envoi
            try {
                Log::info('📧 Tentative d\'envoi des emails de notification de rendez-vous (SYNCHRONE)', [
                    'appointment_id' => $appointment->id,
                    'owner_email' => $user->email,
                    'visitor_email' => $appointment->visitor_email,
                    'mail_driver' => config('mail.default'),
                    'mail_host' => config('mail.mailers.smtp.host'),
                    'mail_from' => config('mail.from.address'),
                ]);
                
                // Envoi synchrone direct - pas de queue
                // Email au propriétaire
                Mail::to($user->email)->send(new AppointmentBooked($appointment));
                
                // ✅ Email au demandeur (visitor)
                Mail::to($appointment->visitor_email)->send(new AppointmentCreatedForVisitor($appointment));
                
                Log::info('✅ Emails de notification de rendez-vous envoyés avec succès (SYNCHRONE)', [
                    'appointment_id' => $appointment->id,
                    'owner_email' => $user->email,
                    'visitor_email' => $appointment->visitor_email,
                ]);
            } catch (\Exception $mailException) {
                Log::error('❌ Erreur critique lors de l\'envoi des emails de rendez-vous', [
                    'appointment_id' => $appointment->id,
                    'owner_email' => $user->email,
                    'visitor_email' => $appointment->visitor_email,
                    'error' => $mailException->getMessage(),
                    'error_code' => $mailException->getCode(),
                    'trace' => $mailException->getTraceAsString(),
                    'mail_config' => [
                        'driver' => config('mail.default'),
                        'host' => config('mail.mailers.smtp.host'),
                        'port' => config('mail.mailers.smtp.port'),
                        'from_address' => config('mail.from.address'),
                    ],
                ]);
                
                // Ne pas faire échouer la requête si l'email échoue
                // L'utilisateur a quand même son rendez-vous enregistré
                // Mais on log l'erreur pour investigation
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
     * Génère un token d'annulation unique et sécurisé pour un rendez-vous.
     * 
     * @return string
     */
    private function generateCancellationToken(): string
    {
        do {
            $token = bin2hex(random_bytes(32)); // 64 caractères hexadécimaux
        } while (Appointment::where('cancellation_token', $token)->exists());
        
        return $token;
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
            // Pour les employés, vérifier via OrderEmployee
            if ($user->role === 'employee') {
                $orderEmployee = \App\Models\OrderEmployee::where('employee_id', $user->id)
                    ->where('order_id', $orderId)
                    ->first();

                if (!$orderEmployee) {
                    return response()->json([
                        'message' => 'Commande non trouvée ou non autorisée.',
                    ], 404);
                }
            } else {
                // Pour les autres utilisateurs (individual, business_admin), vérifier directement
                $order = \App\Models\Order::where('id', $orderId)
                    ->where('user_id', $user->id)
                    ->first();

                if (!$order) {
                    return response()->json([
                        'message' => 'Commande non trouvée ou non autorisée.',
                    ], 404);
                }
            }
        }

        // Récupérer les rendez-vous de l'utilisateur
        // IMPORTANT: Afficher uniquement les rendez-vous confirmés et à venir (ou aujourd'hui)
        $today = Carbon::today();
        
        $query = Appointment::where('user_id', $user->id)
            ->where('status', Appointment::STATUS_CONFIRMED)
            ->where('start_time', '>=', $today->startOfDay())
            ->orderBy('start_time', 'asc'); // Ordre croissant pour afficher les plus proches en premier

        // Filtrer par order_id si fourni
        // Si order_id est fourni, inclure les rendez-vous de cette commande ET ceux sans order_id (créés avant l'implémentation)
        // Si order_id n'est pas fourni, retourner TOUS les rendez-vous confirmés de l'utilisateur
        if ($orderId !== null) {
            $query->where(function($q) use ($orderId) {
                $q->where('order_id', $orderId)
                  ->orWhereNull('order_id');
            });
        }
        // Si order_id est null, ne pas filtrer par order_id (retourner tous les rendez-vous)

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
                'is_downloaded' => $appointment->is_downloaded,
                'downloaded_at' => $appointment->downloaded_at ? $appointment->downloaded_at->toIso8601String() : null,
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

        // ✅ Envoyer les emails de notification d'annulation aux deux parties
        try {
            Log::info('📧 Envoi des emails d\'annulation de rendez-vous', [
                'appointment_id' => $appointment->id,
                'owner_email' => $user->email,
                'visitor_email' => $appointment->visitor_email,
            ]);
            
            // Email au propriétaire
            Mail::to($user->email)->send(new AppointmentCancelledForOwner($appointment));
            
            // ✅ Email au demandeur (visitor)
            Mail::to($appointment->visitor_email)->send(new AppointmentCancelledForVisitor($appointment));
            
            Log::info('✅ Emails d\'annulation envoyés avec succès', [
                'appointment_id' => $appointment->id,
            ]);
        } catch (\Exception $mailException) {
            Log::error('❌ Erreur lors de l\'envoi des emails d\'annulation', [
                'appointment_id' => $appointment->id,
                'error' => $mailException->getMessage(),
            ]);
            // Ne pas faire échouer la requête si l'email échoue
        }

        return response()->json([
            'message' => 'Rendez-vous annulé avec succès. Le créneau est maintenant disponible.',
            'appointment' => [
                'id' => $appointment->id,
                'status' => $appointment->status,
            ],
        ]);
    }

    /**
     * Annule un rendez-vous par token (route publique pour le demandeur).
     * 
     * Permet au demandeur d'annuler son rendez-vous directement depuis l'email
     * sans authentification, en utilisant un token sécurisé.
     * 
     * @param Request $request
     * @param string $token
     * @return \Illuminate\Http\JsonResponse|\Illuminate\View\View
     */
    public function cancelByToken(Request $request, string $token)
    {
        // Rechercher le rendez-vous par token
        $appointment = Appointment::where('cancellation_token', $token)
            ->where('status', Appointment::STATUS_CONFIRMED)
            ->first();

        if (!$appointment) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Token invalide ou rendez-vous introuvable.',
                ], 404);
            }
            
            // Si c'est une requête web (depuis le lien dans l'email), afficher une page d'erreur simple
            return response('<html><body><h1>Rendez-vous introuvable</h1><p>Le token d\'annulation est invalide ou le rendez-vous n\'existe plus.</p></body></html>', 404)
                ->header('Content-Type', 'text/html');
        }

        // Vérifier que le rendez-vous peut être annulé (pas dans le passé)
        if ($appointment->start_time->isPast()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Impossible d\'annuler un rendez-vous passé.',
                ], 422);
            }
            
            return response('<html><body><h1>Rendez-vous passé</h1><p>Impossible d\'annuler un rendez-vous qui a déjà eu lieu.</p></body></html>', 422)
                ->header('Content-Type', 'text/html');
        }

        // Annuler le rendez-vous
        $appointment->status = Appointment::STATUS_CANCELLED;
        $appointment->save();

        // Recharger la relation user pour l'email
        $appointment->load('user');

        // ✅ Envoyer les emails de notification d'annulation aux deux parties
        try {
            Log::info('📧 Envoi des emails d\'annulation de rendez-vous (par token)', [
                'appointment_id' => $appointment->id,
                'owner_email' => $appointment->user->email,
                'visitor_email' => $appointment->visitor_email,
            ]);
            
            // Email au propriétaire
            Mail::to($appointment->user->email)->send(new AppointmentCancelledForOwner($appointment));
            
            // ✅ Email au demandeur (visitor)
            Mail::to($appointment->visitor_email)->send(new AppointmentCancelledForVisitor($appointment));
            
            Log::info('✅ Emails d\'annulation envoyés avec succès (par token)', [
                'appointment_id' => $appointment->id,
            ]);
        } catch (\Exception $mailException) {
            Log::error('❌ Erreur lors de l\'envoi des emails d\'annulation (par token)', [
                'appointment_id' => $appointment->id,
                'error' => $mailException->getMessage(),
            ]);
            // Ne pas faire échouer la requête si l'email échoue
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Rendez-vous annulé avec succès. Le créneau est maintenant disponible.',
                'appointment' => [
                    'id' => $appointment->id,
                    'status' => $appointment->status,
                ],
            ]);
        }

        // Si c'est une requête web (depuis le lien dans l'email), afficher une page de confirmation
        return response('<html><body style="font-family: Arial, sans-serif; text-align: center; padding: 50px;"><h1 style="color: #10b981;">✅ Rendez-vous annulé</h1><p>Votre rendez-vous a été annulé avec succès.</p><p>Le créneau est maintenant disponible pour d\'autres réservations.</p><p style="color: #64748b; margin-top: 30px;">Vous et le propriétaire avez reçu une confirmation par email.</p></body></html>', 200)
            ->header('Content-Type', 'text/html');
    }

    /**
     * Télécharge un rendez-vous au format ICS pour l'importer dans un agenda.
     * Route protégée par auth:sanctum.
     * 
     * @param Request $request
     * @param int $id
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function downloadIcs(Request $request, $id)
    {
        $user = $request->user();

        $appointment = Appointment::where('id', $id)
            ->where('user_id', $user->id)
            ->where('status', Appointment::STATUS_CONFIRMED)
            ->first();

        if (!$appointment) {
            return response()->json([
                'message' => 'Rendez-vous non trouvé ou non autorisé.',
            ], 404);
        }

        // Charger la relation user
        $appointment->load('user');

        // Générer le contenu ICS
        $icsContent = $appointment->generateIcsContent();

        // Marquer comme téléchargé
        $appointment->is_downloaded = true;
        $appointment->downloaded_at = now();
        $appointment->save();

        // Nom du fichier
        $fileName = 'rdv-' . $appointment->visitor_name . '-' . $appointment->start_time->format('Y-m-d') . '.ics';
        $fileName = preg_replace('/[^a-zA-Z0-9\-_\.]/', '-', $fileName);

        return response($icsContent, 200)
            ->header('Content-Type', 'text/calendar; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }

    /**
     * Télécharge tous les rendez-vous non téléchargés au format ICS.
     * Route protégée par auth:sanctum.
     * 
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function downloadAllIcs(Request $request)
    {
        $user = $request->user();
        $orderId = $request->query('order_id');

        $query = Appointment::where('user_id', $user->id)
            ->where('status', Appointment::STATUS_CONFIRMED)
            ->where('is_downloaded', false)
            ->with('user')
            ->orderBy('start_time', 'asc');

        if ($orderId) {
            $query->where('order_id', $orderId);
        }

        $appointments = $query->get();

        if ($appointments->isEmpty()) {
            return response()->json([
                'message' => 'Aucun nouveau rendez-vous à télécharger.',
            ], 404);
        }

        // Générer le contenu ICS combiné
        $icsContent = "BEGIN:VCALENDAR\r\n";
        $icsContent .= "VERSION:2.0\r\n";
        $icsContent .= "PRODID:-//DigiCard//Appointment System//FR\r\n";
        $icsContent .= "CALSCALE:GREGORIAN\r\n";
        $icsContent .= "METHOD:PUBLISH\r\n";
        $icsContent .= "X-WR-CALNAME:Mes Rendez-vous DigiCard\r\n";

        foreach ($appointments as $appointment) {
            $icsContent .= $this->generateEventContent($appointment);
            
            // Marquer comme téléchargé
            $appointment->is_downloaded = true;
            $appointment->downloaded_at = now();
            $appointment->save();
        }

        $icsContent .= "END:VCALENDAR\r\n";

        // Nom du fichier
        $fileName = 'mes-rendez-vous-digicard-' . now()->format('Y-m-d') . '.ics';

        return response($icsContent, 200)
            ->header('Content-Type', 'text/calendar; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }

    /**
     * Génère le contenu VEVENT pour un rendez-vous (sans l'enveloppe VCALENDAR).
     * 
     * @param Appointment $appointment
     * @return string
     */
    private function generateEventContent(Appointment $appointment): string
    {
        $owner = $appointment->user;
        
        // Formater les dates au format iCalendar (UTC)
        $dtStart = $appointment->start_time->setTimezone('UTC')->format('Ymd\THis\Z');
        $dtEnd = $appointment->end_time->setTimezone('UTC')->format('Ymd\THis\Z');
        $dtStamp = now()->setTimezone('UTC')->format('Ymd\THis\Z');
        $created = $appointment->created_at->setTimezone('UTC')->format('Ymd\THis\Z');
        
        // Identifiant unique pour l'événement
        $uid = 'digicard-appointment-' . $appointment->id . '@arccenciel.com';
        
        // Description de l'événement
        $description = "Rendez-vous avec {$appointment->visitor_name}";
        if ($appointment->message) {
            $description .= "\\n\\nMotif : {$appointment->message}";
        }
        $description .= "\\n\\nContact visiteur :";
        $description .= "\\nEmail : {$appointment->visitor_email}";
        if ($appointment->visitor_phone) {
            $description .= "\\nTéléphone : {$appointment->visitor_phone}";
        }
        $description .= "\\n\\nRéservé via DigiCard";
        
        // Titre de l'événement
        $summary = "RDV: {$appointment->visitor_name}";
        
        // Email organisateur
        $organizerEmail = config('mail.from.address', 'noreply@arccenciel.com');
        $organizerName = 'DigiCard System';
        
        // Email du propriétaire (attendee)
        $attendeeEmail = $owner ? $owner->email : '';
        $attendeeName = $owner ? $owner->name : '';

        // Construction de l'événement
        $event = "BEGIN:VEVENT\r\n";
        $event .= "UID:{$uid}\r\n";
        $event .= "DTSTAMP:{$dtStamp}\r\n";
        $event .= "DTSTART:{$dtStart}\r\n";
        $event .= "DTEND:{$dtEnd}\r\n";
        $event .= "CREATED:{$created}\r\n";
        $event .= "SUMMARY:{$summary}\r\n";
        $event .= "DESCRIPTION:{$description}\r\n";
        if ($attendeeEmail) {
            $event .= "ORGANIZER;CN={$organizerName}:mailto:{$organizerEmail}\r\n";
            $event .= "ATTENDEE;CUTYPE=INDIVIDUAL;ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED;CN={$attendeeName}:mailto:{$attendeeEmail}\r\n";
        }
        $event .= "STATUS:CONFIRMED\r\n";
        $event .= "SEQUENCE:0\r\n";
        $event .= "TRANSP:OPAQUE\r\n";
        // Rappel 30 minutes avant
        $event .= "BEGIN:VALARM\r\n";
        $event .= "TRIGGER:-PT30M\r\n";
        $event .= "ACTION:DISPLAY\r\n";
        $event .= "DESCRIPTION:Rappel: {$summary}\r\n";
        $event .= "END:VALARM\r\n";
        // Rappel 10 minutes avant
        $event .= "BEGIN:VALARM\r\n";
        $event .= "TRIGGER:-PT10M\r\n";
        $event .= "ACTION:DISPLAY\r\n";
        $event .= "DESCRIPTION:Rappel: {$summary}\r\n";
        $event .= "END:VALARM\r\n";
        $event .= "END:VEVENT\r\n";

        return $event;
    }

    /**
     * Compte les rendez-vous non téléchargés.
     * Route protégée par auth:sanctum.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function countNotDownloaded(Request $request)
    {
        $user = $request->user();
        $orderId = $request->query('order_id');

        $query = Appointment::where('user_id', $user->id)
            ->where('status', Appointment::STATUS_CONFIRMED)
            ->where('is_downloaded', false);

        if ($orderId) {
            $query->where('order_id', $orderId);
        }

        $count = $query->count();

        return response()->json([
            'count' => $count,
        ]);
    }
}

