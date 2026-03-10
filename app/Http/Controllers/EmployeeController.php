<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use App\Mail\EmployeeWelcomeMail;
use App\Mail\EmployeeDeletionNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

class EmployeeController extends Controller
{
    /**
     * Stocke un nouvel employé.
     */
    public function store(Request $request)
    {
        $admin = $request->user();
        if ($admin->role !== 'business_admin') {
            return response()->json(['message' => 'Action non autorisée.'], 403);
        }

        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
        ]);

        $temporaryPassword = Str::random(10);

        // Générer un username unique basé sur le nom
        $baseUsername = Str::slug($validatedData['name']);
        $username = $baseUsername;
        $counter = 1;
        while (User::where('username', $username)->exists()) {
            $username = $baseUsername . '-' . $counter;
            $counter++;
        }

        try {
            $employee = User::create([
                'name' => $validatedData['name'],
                'email' => $validatedData['email'],
                'username' => $username,
                'password' => Hash::make($temporaryPassword),
                'role' => 'employee',
                'company_name' => $admin->company_name,
                'business_admin_id' => $admin->id,
                'password_reset_required' => true, // Forcer le changement de mot de passe
                'is_profile_complete' => true, // Permet la connexion avec le mot de passe temporaire
            ]);

            // --- Bloc d'envoi d'email sécurisé ---
            try {
                $loginUrl = config('app.url_frontend', 'https://digicard.arccenciel.com/');

                Mail::to($employee->email)->send(new EmployeeWelcomeMail(
                    $temporaryPassword,
                    $employee->email,
                    $employee->company_name,
                    $loginUrl
                ));

            } catch (\Throwable $t) { // Attrape toutes les erreurs (y compris l'échec de Mailtrap)
                Log::error("Échec de l'envoi de l'email de bienvenue à " . $employee->email . ": " . $t->getMessage());
            }
            // ------------------------------------

            // L'employé est créé, on renvoie une réponse de succès (JSON)
            return response()->json($employee->refresh()->makeHidden('password'), 201);

        } catch (\Throwable $t) { // Attrape les erreurs de création (ex: DB)
            Log::error('Échec de la création de l\'employé: ' . $t->getMessage());
            return response()->json(['message' => 'Erreur lors de la création de l\'employé.'], 500);
        }
    }

    /**
     * Affiche la liste des employés de l'admin avec le nombre de cartes assignées.
     */
    public function index(Request $request)
    {
         $admin = $request->user();
         if ($admin->role !== 'business_admin') {
            return response()->json(['message' => 'Action non autorisée.'], 403);
        }

         // Récupérer les employés avec leurs cartes
         $employees = $admin->employees()
             ->select('id', 'name', 'email', 'username', 'email_verified_at', 'created_at')
             ->get()
             ->map(function ($employee) {
                 // Calculer le nombre total de cartes pour cet employé
                 $totalCards = \App\Models\OrderEmployee::where('employee_id', $employee->id)
                     ->sum('card_quantity');

                 $employee->total_cards = $totalCards ?? 0;
                 return $employee;
             });

         return response()->json($employees);
    }

     /**
     * Permet à un employé de définir son mot de passe.
     */
    public function setPassword(Request $request)
    {
         $user = $request->user();
         if ($user->role !== 'employee') {
             return response()->json(['message' => 'Action non autorisée.'], 403);
         }

         $validatedData = $request->validate([
             'password' => ['required', 'string', Password::min(8), 'confirmed'],
         ]);

         $user->password = Hash::make($validatedData['password']);
         $user->initial_password_set = true;
         $user->password_reset_required = false; // Désactiver le flag après changement
         $user->save();

         return response()->json(['message' => 'Mot de passe mis à jour avec succès.']);
    }

    /**
     * Supprime un employé d'une commande spécifique et libère le slot
     * Si l'employé n'a plus d'autres commandes, son compte est supprimé
     *
     * @param Request $request - Peut contenir order_id pour spécifier la commande
     * @param User $employee
     */
    public function destroy(Request $request, User $employee)
    {
        $admin = $request->user();

        // Vérifier que l'utilisateur est un business admin
        if ($admin->role !== 'business_admin') {
            return response()->json(['message' => 'Action non autorisée.'], 403);
        }

        // Vérifier que l'employé appartient bien à cet admin
        if ($employee->business_admin_id !== $admin->id) {
            return response()->json(['message' => 'Employé non trouvé ou non autorisé.'], 404);
        }

        try {
            // ✅ NOUVEAU: Si un order_id est spécifié, retirer l'employé seulement de cette commande
            $specificOrderId = $request->input('order_id');

            Log::info('Suppression employé demandée', [
                'employee_id' => $employee->id,
                'employee_email' => $employee->email,
                'admin_id' => $admin->id,
                'specific_order_id' => $specificOrderId,
            ]);

            // Récupérer les OrderEmployee entries pour cet employé
            if ($specificOrderId) {
                // Seulement pour la commande spécifiée
                $orderEmployees = \App\Models\OrderEmployee::where('employee_id', $employee->id)
                    ->where('order_id', $specificOrderId)
                    ->get();
            } else {
                // Toutes les commandes (comportement legacy)
                $orderEmployees = \App\Models\OrderEmployee::where('employee_id', $employee->id)->get();
            }

            if ($orderEmployees->isEmpty()) {
                return response()->json(['message' => 'Aucune assignation trouvée pour cet employé.'], 404);
            }

            // Tableau pour stocker les commandes à supprimer
            $ordersToDelete = [];
            $slotsFreed = 0;

            // Supprimer les OrderEmployee entries et mettre à jour les slots
            foreach ($orderEmployees as $orderEmployee) {
                $order = $orderEmployee->order;

                // ✅ Si la commande est validée, configurée ou annulée, ne rien modifier concernant la commande
                if ($order && ($order->status === 'validated' || $order->status === 'configured' || $order->status === 'cancelled')) {
                    // Supprimer seulement l'OrderEmployee entry, sans affecter la commande
                    $orderEmployee->delete();
                    continue; // Passer à la commande suivante
                }

                $employeeCardQuantity = $orderEmployee->card_quantity; // Sauvegarder le nombre de cartes de l'employé

                // ✅ NOUVEAU: Libérer le slot et le rendre disponible pour un nouvel employé
                if ($order && $order->employee_slots && is_array($order->employee_slots)) {
                    $slots = $order->employee_slots;

                    // Réinitialiser le slot de cet employé pour qu'il soit réassignable
                    foreach ($slots as $index => $slot) {
                        if (isset($slot['employee_id']) && $slot['employee_id'] == $employee->id) {
                            // ✅ IMPORTANT: Garder cards_quantity pour permettre la réassignation
                            $slots[$index]['employee_id'] = null;
                            $slots[$index]['employee_name'] = null;
                            $slots[$index]['employee_email'] = null;
                            $slots[$index]['is_assigned'] = false;
                            $slots[$index]['is_configured'] = false;
                            // ✅ NE PAS mettre cards_quantity à 0 - garder la valeur pour le slot
                            $slotsFreed++;

                            Log::info('Slot libéré pour réassignation', [
                                'order_id' => $order->id,
                                'slot_index' => $index,
                                'cards_quantity' => $slots[$index]['cards_quantity'] ?? 1,
                            ]);
                        }
                    }

                    $order->employee_slots = $slots;
                }

                // Supprimer l'OrderEmployee entry
                $orderEmployee->delete();

                $order->save();

                // ✅ Recalculer les totaux de la commande
                if ($order && $order->status !== 'validated') {
                    // Recalculer le nombre total de cartes basé sur les employee_slots
                    $totalCards = 0;
                    $totalPrice = 0;
                    $activePersonnelCount = 0;

                    if ($order->employee_slots && is_array($order->employee_slots)) {
                        foreach ($order->employee_slots as $slot) {
                            // Compter TOUS les slots avec des cartes (assignés ou non)
                            $cardsQuantity = $slot['cards_quantity'] ?? 1;
                            if ($cardsQuantity > 0) {
                                $totalCards += $cardsQuantity;
                                $totalPrice += \App\Helpers\PricingHelper::calculatePrice($cardsQuantity);
                            }

                            // Compter le personnel actif (hors business admin)
                            if (isset($slot['employee_id']) && $slot['employee_id'] && $slot['employee_id'] != $order->user_id) {
                                $activePersonnelCount++;
                            }
                        }
                    }

                    $order->card_quantity = $totalCards;
                    $order->total_price = $totalPrice;
                    $order->save();
                }
            }

            // ✅ NOUVEAU: Vérifier si l'employé a d'autres commandes actives
            $remainingOrderEmployees = \App\Models\OrderEmployee::where('employee_id', $employee->id)->count();

            Log::info('Vérification des commandes restantes pour l\'employé', [
                'employee_id' => $employee->id,
                'remaining_orders' => $remainingOrderEmployees,
                'slots_freed' => $slotsFreed,
            ]);

            // Message de base
            $message = "L'employé a été retiré de cette commande. Le slot est maintenant disponible pour assigner un nouvel employé.";
            $accountDeleted = false;

            // ✅ Si l'employé n'a plus aucune commande, supprimer son compte
            if ($remainingOrderEmployees === 0) {
                // Sauvegarder les informations avant suppression pour l'email
                $employeeEmail = $employee->email;
                $employeeName = $employee->name;
                $companyName = $employee->company_name;

                // Envoyer un email de notification à l'employé
                try {
                    Mail::to($employeeEmail)->send(new EmployeeDeletionNotification(
                        $employeeName,
                        $companyName
                    ));
                    Log::info('Email de suppression envoyé à ' . $employeeEmail);
                } catch (\Throwable $t) {
                    Log::error("Échec de l'envoi de l'email de suppression à " . $employeeEmail . ": " . $t->getMessage());
                    // On continue même si l'email échoue
                }

                // Révoquer les tokens de l'employé (empêche toute connexion future)
                $employee->tokens()->delete();

                // Supprimer l'avatar de l'employé s'il existe
                if ($employee->avatar_url) {
                    // ✅ CORRECTION : Gérer les deux formats (/storage/ et /api/storage/)
                    $oldPath = preg_replace('#^/api/storage/#', '', $employee->avatar_url);
                    $oldPath = preg_replace('#^/storage/#', '', $oldPath);
                    $oldPath = preg_replace('#^https?://[^/]+/(api/)?storage/#', '', $oldPath);
                    if (Storage::disk('public')->exists($oldPath)) {
                        Storage::disk('public')->delete($oldPath);
                    }
                }

                // Supprimer l'employé de la base de données
                $employee->delete();
                $accountDeleted = true;

                $message = "L'employé a été retiré de cette commande et son compte a été supprimé de la plateforme car il n'avait plus d'autres commandes. Le slot est maintenant disponible pour assigner un nouvel employé.";

                Log::info('Compte employé supprimé (plus aucune commande)', [
                    'employee_id' => $employee->id ?? 'deleted',
                    'employee_email' => $employeeEmail,
                ]);
            } else {
                // L'employé a encore d'autres commandes, on ne supprime pas son compte
                Log::info('Employé retiré de la commande mais compte conservé (autres commandes existantes)', [
                    'employee_id' => $employee->id,
                    'employee_email' => $employee->email,
                    'remaining_orders' => $remainingOrderEmployees,
                ]);
            }

            return response()->json([
                'message' => $message,
                'slots_freed' => $slotsFreed,
                'account_deleted' => $accountDeleted,
                'remaining_orders' => $remainingOrderEmployees,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Échec de la suppression de l\'employé ID ' . $employee->id . ': ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Erreur lors de la suppression de l\'employé.'], 500);
        }
    }

    /**
     * Ajoute une carte à un employé (dans sa commande la plus récente).
     */
    public function addCard(Request $request, User $employee)
    {
        $admin = $request->user();

        // Vérifier que l'utilisateur est un business admin
        if ($admin->role !== 'business_admin') {
            return response()->json(['message' => 'Action non autorisée.'], 403);
        }

        // Vérifier que l'employé appartient bien à cet admin
        if ($employee->business_admin_id !== $admin->id) {
            return response()->json(['message' => 'Employé non trouvé ou non autorisé.'], 404);
        }

        try {
            // Trouver la commande d'entreprise la plus récente de cet admin
            $latestOrder = \App\Models\Order::where('user_id', $admin->id)
                ->where('order_type', 'business')
                ->latest()
                ->first();

            if (!$latestOrder) {
                return response()->json(['message' => 'Aucune commande d\'entreprise trouvée.'], 404);
            }

            // Vérifier si la commande est annulée ou configurée - si oui, ne rien modifier
            // Note: pour une commande validée, on peut toujours ajouter des cartes supplémentaires
            if ($latestOrder->status === 'configured' || $latestOrder->status === 'cancelled') {
                return response()->json([
                    'message' => 'Impossible d\'ajouter une carte : la commande est configurée ou annulée.',
                ], 400);
            }

            // Trouver ou créer l'entrée OrderEmployee pour cet employé dans cette commande
            $orderEmployee = \App\Models\OrderEmployee::firstOrCreate(
                [
                    'order_id' => $latestOrder->id,
                    'employee_id' => $employee->id,
                ],
                [
                    'employee_email' => $employee->email,
                    'employee_name' => $employee->name,
                    'card_quantity' => 0,
                    'is_configured' => false,
                ]
            );

            // Incrémenter le nombre de cartes dans order_employees (source de vérité)
            $orderEmployee->card_quantity += 1;
            $orderEmployee->save();

            \Log::info('Carte ajoutée à un employé', [
                'order_id' => $latestOrder->id,
                'employee_id' => $employee->id,
                'employee_name' => $employee->name,
                'order_employee_id' => $orderEmployee->id,
                'new_card_quantity' => $orderEmployee->card_quantity,
                'order_status' => $latestOrder->status,
            ]);

            // Mettre à jour aussi le champ JSON employee_slots pour cohérence
            if ($latestOrder->employee_slots && is_array($latestOrder->employee_slots)) {
                $slots = $latestOrder->employee_slots;
                foreach ($slots as $index => $slot) {
                    if (isset($slot['employee_id']) && $slot['employee_id'] == $employee->id) {
                        $slots[$index]['cards_quantity'] = $orderEmployee->card_quantity;
                        break;
                    }
                }
                $latestOrder->employee_slots = $slots;
            }

            // Pour une commande validée : ajouter seulement le prix unitaire d'une carte supplémentaire
            // Pour une commande pending : recalculer tout le prix
            if ($latestOrder->status === 'validated') {
                // Ajouter seulement le prix d'une carte supplémentaire au total existant
                $additionalCardPrice = \App\Helpers\PricingHelper::getExtraPrice();
                $latestOrder->card_quantity += 1;
                $latestOrder->total_price += $additionalCardPrice;

                // Mettre à jour aussi les compteurs de cartes supplémentaires
                $latestOrder->increment('additional_cards_count', 1);
                $latestOrder->increment('additional_cards_total_price', $additionalCardPrice);

                \Log::info('Commande validée mise à jour après ajout de carte', [
                    'order_id' => $latestOrder->id,
                    'new_card_quantity' => $latestOrder->card_quantity,
                    'new_total_price' => $latestOrder->total_price,
                    'additional_cards_count' => $latestOrder->additional_cards_count,
                ]);
            } else {
                // Recalculer le nombre total de cartes basé sur les order_employees (source de vérité)
                // plutôt que sur employee_slots pour garantir la cohérence
                $totalCards = 0;
                $totalPrice = 0;

                // Utiliser order_employees pour calculer le total (plus fiable)
                $orderEmployees = \App\Models\OrderEmployee::where('order_id', $latestOrder->id)->get();
                foreach ($orderEmployees as $oe) {
                    $totalCards += $oe->card_quantity;
                    $totalPrice += \App\Helpers\PricingHelper::calculatePrice($oe->card_quantity);
                }

                $latestOrder->card_quantity = $totalCards;
                $latestOrder->total_price = $totalPrice;

                \Log::info('Commande non validée mise à jour après ajout de carte', [
                    'order_id' => $latestOrder->id,
                    'new_card_quantity' => $latestOrder->card_quantity,
                    'new_total_price' => $latestOrder->total_price,
                    'calculation_method' => 'order_employees',
                ]);
            }

            $latestOrder->save();

            // Recharger les relations pour s'assurer que les données sont à jour
            $latestOrder->refresh();
            $orderEmployee->refresh();

            // Notification super admin : nouvelle carte ajoutée sur une commande validée
            try {
                if ($latestOrder->status === 'validated') {
                    $profileUrl = route('profile.public.show', ['user' => $employee->username]) . '?order=' . $latestOrder->id;
                    \App\Models\AdminNotification::create([
                        'type' => 'card_added',
                        'user_id' => $admin->id,
                        'order_id' => $latestOrder->id,
                        'employee_id' => $employee->id,
                        'message' => $admin->name . " a ajouté une carte pour " . $employee->name . " (#" . $latestOrder->order_number . ")",
                        'url' => $profileUrl,
                        'meta' => [
                            'order_number' => $latestOrder->order_number,
                            'employee' => $employee->only(['id','name','email','username']),
                        ],
                    ]);
                }
            } catch (\Throwable $t) {}

            // Retourner aussi les informations de la commande mise à jour pour le frontend
            return response()->json([
                'message' => 'Carte ajoutée avec succès. L\'employé a maintenant ' . $orderEmployee->card_quantity . ' carte(s).',
                'card_quantity' => $orderEmployee->card_quantity,
                'order' => [
                    'id' => $latestOrder->id,
                    'order_number' => $latestOrder->order_number,
                    'card_quantity' => $latestOrder->card_quantity,
                    'total_price' => $latestOrder->total_price,
                    'status' => $latestOrder->status,
                ],
                'order_employee' => [
                    'id' => $orderEmployee->id,
                    'employee_id' => $orderEmployee->employee_id,
                    'employee_name' => $orderEmployee->employee_name,
                    'employee_email' => $orderEmployee->employee_email,
                    'card_quantity' => $orderEmployee->card_quantity,
                    'is_configured' => $orderEmployee->is_configured,
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Échec de l\'ajout de carte pour l\'employé ID ' . $employee->id . ': ' . $e->getMessage());
            return response()->json(['message' => 'Erreur lors de l\'ajout de la carte.'], 500);
        }
    }

    /**
     * Retire une carte à un employé (dans sa commande la plus récente).
     */
    public function removeCard(Request $request, User $employee)
    {
        $admin = $request->user();

        // Vérifier que l'utilisateur est un business admin
        if ($admin->role !== 'business_admin') {
            return response()->json(['message' => 'Action non autorisée.'], 403);
        }

        // Vérifier que l'employé appartient bien à cet admin
        if ($employee->business_admin_id !== $admin->id) {
            return response()->json(['message' => 'Employé non trouvé ou non autorisé.'], 404);
        }

        try {
            // Trouver la commande d'entreprise la plus récente de cet admin
            $latestOrder = \App\Models\Order::where('user_id', $admin->id)
                ->where('order_type', 'business')
                ->latest()
                ->first();

            if (!$latestOrder) {
                return response()->json(['message' => 'Aucune commande d\'entreprise trouvée.'], 404);
            }

            // Trouver l'entrée OrderEmployee pour cet employé dans cette commande
            $orderEmployee = \App\Models\OrderEmployee::where('order_id', $latestOrder->id)
                ->where('employee_id', $employee->id)
                ->first();

            if (!$orderEmployee) {
                return response()->json(['message' => 'Aucune carte assignée à cet employé.'], 404);
            }

            // Vérifier qu'il y a au moins une carte à retirer
            if ($orderEmployee->card_quantity <= 0) {
                return response()->json(['message' => 'Cet employé n\'a plus de carte à retirer.'], 400);
            }

            // Vérifier si la commande est validée, configurée ou annulée - si oui, ne rien modifier
            if ($latestOrder->status === 'validated' || $latestOrder->status === 'configured' || $latestOrder->status === 'cancelled') {
                return response()->json([
                    'message' => 'Impossible de retirer une carte : la commande est déjà validée ou annulée.',
                ], 400);
            }

            // ✅ NOUVEAU: Vérifier si l'employé n'a qu'une seule carte avant le retrait
            $hadOnlyOneCard = $orderEmployee->card_quantity === 1;
            $willHaveNoCards = $orderEmployee->card_quantity <= 1; // Après décrémentation, il n'aura plus de cartes

            // Décrémenter le nombre de cartes
            $orderEmployee->card_quantity -= 1;
            $newCardQuantity = $orderEmployee->card_quantity;

            // Si le nombre de cartes tombe à 0, supprimer l'entrée
            if ($orderEmployee->card_quantity <= 0) {
                $orderEmployee->delete();
                $message = 'Dernière carte retirée. L\'employé n\'a plus de carte dans cette commande.';
            } else {
                $orderEmployee->save();
                $message = 'Carte retirée avec succès. L\'employé a maintenant ' . $orderEmployee->card_quantity . ' carte(s).';
            }

            // ✅ NOUVEAU: Si l'employé n'avait qu'une seule carte et qu'on l'a retirée, vérifier s'il a d'autres cartes
            // Si non, supprimer son compte de la plateforme
            if ($hadOnlyOneCard && $newCardQuantity <= 0) {
                // Recharger l'employé depuis la base de données pour avoir les données à jour
                $employee->refresh();

                // Vérifier si cet employé a des cartes dans d'autres commandes
                $otherOrderEmployees = \App\Models\OrderEmployee::where('employee_id', $employee->id)
                    ->where('order_id', '!=', $latestOrder->id)
                    ->get();

                $hasOtherCards = false;
                foreach ($otherOrderEmployees as $otherOrderEmployee) {
                    if ($otherOrderEmployee->card_quantity > 0) {
                        $hasOtherCards = true;
                        break;
                    }
                }

                // Si l'employé n'a plus de cartes nulle part, supprimer son compte
                if (!$hasOtherCards) {
                    Log::info('Suppression du compte employé après retrait de sa dernière carte', [
                        'employee_id' => $employee->id,
                        'employee_email' => $employee->email,
                        'order_id' => $latestOrder->id,
                    ]);

                    // ✅ NOUVEAU: Libérer le slot de cet employé dans la commande
                    if ($latestOrder->employee_slots && is_array($latestOrder->employee_slots)) {
                        $slots = $latestOrder->employee_slots;
                        foreach ($slots as $index => $slot) {
                            if (isset($slot['employee_id']) && $slot['employee_id'] == $employee->id) {
                                $slots[$index]['employee_id'] = null;
                                $slots[$index]['employee_name'] = null;
                                $slots[$index]['employee_email'] = null;
                                $slots[$index]['is_assigned'] = false;
                                $slots[$index]['is_configured'] = false;
                                $slots[$index]['cards_quantity'] = 0;
                            }
                        }
                        $latestOrder->employee_slots = $slots;
                        $latestOrder->save();

                        Log::info('Slot libéré après suppression de l\'employé', [
                            'employee_id' => $employee->id,
                            'order_id' => $latestOrder->id,
                        ]);
                    }

                    // Sauvegarder les informations avant suppression pour l'email
                    $employeeId = $employee->id;
                    $employeeEmail = $employee->email;
                    $employeeName = $employee->name;
                    $companyName = $employee->company_name;

                    // Envoyer un email de notification à l'employé
                    try {
                        Mail::to($employeeEmail)->send(new EmployeeDeletionNotification(
                            $employeeName,
                            $companyName
                        ));
                        Log::info('Email de suppression envoyé à ' . $employeeEmail . ' (retrait de carte)');
                    } catch (\Throwable $t) {
                        Log::error("Échec de l'envoi de l'email de suppression à " . $employeeEmail . ": " . $t->getMessage());
                        // On continue même si l'email échoue
                    }

                    // Révoquer les tokens de l'employé (empêche toute connexion future)
                    $employee->tokens()->delete();

                    // Supprimer l'avatar de l'employé s'il existe
                    if ($employee->avatar_url) {
                        $oldPath = preg_replace('#^/api/storage/#', '', $employee->avatar_url);
                        $oldPath = preg_replace('#^/storage/#', '', $oldPath);
                        $oldPath = preg_replace('#^https?://[^/]+/(api/)?storage/#', '', $oldPath);
                        if (Storage::disk('public')->exists($oldPath)) {
                            Storage::disk('public')->delete($oldPath);
                        }
                    }

                    // Supprimer toutes les OrderEmployee entries restantes pour cet employé
                    \App\Models\OrderEmployee::where('employee_id', $employeeId)->delete();

                    // Supprimer l'employé de la base de données
                    $employee->delete();

                    $message = 'Dernière carte retirée. Le compte de l\'employé a été supprimé de la plateforme car il n\'avait plus de cartes. Le slot est maintenant disponible pour assigner un nouvel employé.';

                    Log::info('Compte employé supprimé après retrait de sa dernière carte', [
                        'employee_id' => $employeeId,
                        'employee_email' => $employeeEmail,
                    ]);
                }
            }

            // Mettre à jour aussi le champ JSON employee_slots
            if ($latestOrder->employee_slots && is_array($latestOrder->employee_slots)) {
                $slots = $latestOrder->employee_slots;
                foreach ($slots as $index => $slot) {
                    if (isset($slot['employee_id']) && $slot['employee_id'] == $employee->id) {
                        $slots[$index]['cards_quantity'] = $orderEmployee->card_quantity ?? 0;
                        break;
                    }
                }
                $latestOrder->employee_slots = $slots;
            }

            // Recalculer le nombre total de cartes basé sur les employee_slots actifs
            $totalCards = 0;
            $totalPrice = 0;

            if ($latestOrder->employee_slots && is_array($latestOrder->employee_slots)) {
                foreach ($latestOrder->employee_slots as $slot) {
                    // Compter seulement les slots qui ne sont PAS marqués comme supprimés
                    if (!isset($slot['is_assigned']) || $slot['is_assigned'] !== false) {
                        $cardsQuantity = $slot['cards_quantity'] ?? 1;
                        $totalCards += $cardsQuantity;
                        $totalPrice += \App\Helpers\PricingHelper::calculatePrice($cardsQuantity);
                    }
                }
            }

            $latestOrder->card_quantity = $totalCards;
            $latestOrder->total_price = $totalPrice;
            $latestOrder->save();

            return response()->json([
                'message' => $message,
                'card_quantity' => $orderEmployee->card_quantity ?? 0,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Échec du retrait de carte pour l\'employé ID ' . $employee->id . ': ' . $e->getMessage());
            return response()->json(['message' => 'Erreur lors du retrait de la carte.'], 500);
        }
    }

    /**
     * Assigne un employé à un slot dans une commande.
     */
    public function assignSlot(Request $request, $orderId, $slotNumber)
    {
        $admin = $request->user();

        // Vérifier que l'utilisateur est un business admin
        if ($admin->role !== 'business_admin') {
            return response()->json(['message' => 'Action non autorisée.'], 403);
        }

        // Valider les données
        $validated = $request->validate([
            'employee_name' => 'required|string|max:255',
            'employee_email' => 'required|email|max:255',
        ]);

        try {
            // Récupérer la commande
            $order = \App\Models\Order::where('id', $orderId)
                ->where('user_id', $admin->id)
                ->where('order_type', 'business')
                ->firstOrFail();

            // Récupérer les slots
            $slots = $order->employee_slots ?? [];

            // Trouver le slot concerné
            $slotIndex = null;
            foreach ($slots as $index => $slot) {
                if ($slot['slot_number'] == $slotNumber) {
                    $slotIndex = $index;
                    break;
                }
            }

            if ($slotIndex === null) {
                return response()->json(['message' => 'Slot non trouvé.'], 404);
            }

            // ✅ LOGIQUE SIMPLIFIÉE: Vérifier si le slot est déjà assigné
// Un slot est assigné seulement s'il a un employee_id valide dans order_employees avec des cartes
$slot = $slots[$slotIndex];
$isSlotAssigned = false;

// Vérifier si le slot a un employee_id
if (isset($slot['employee_id']) && $slot['employee_id']) {
    // Vérifier si l'employé existe toujours dans order_employees avec des cartes
    $orderEmployee = \App\Models\OrderEmployee::where('order_id', $order->id)
        ->where('employee_id', $slot['employee_id'])
        ->where('card_quantity', '>', 0)
        ->first();

    if ($orderEmployee) {
        $isSlotAssigned = true; // L'employé existe toujours et a des cartes

        Log::info('assignSlot - Slot déjà assigné', [
            'slot_number' => $slotNumber,
            'employee_id' => $slot['employee_id'],
            'employee_name' => $orderEmployee->employee_name,
            'card_quantity' => $orderEmployee->card_quantity,
        ]);
    } else {
        // L'employé n'existe plus ou n'a plus de cartes, le slot est libre
        Log::info('assignSlot - Slot libre (employé supprimé ou sans cartes)', [
            'slot_number' => $slotNumber,
            'old_employee_id' => $slot['employee_id'],
        ]);
    }
}

if ($isSlotAssigned) {
    return response()->json(['message' => 'Ce slot est déjà assigné.'], 400);
}


// Vérifier si cet email est déjà utilisé par un autre employé actif de cet admin
$existingEmployee = User::where('email', $validated['employee_email'])
    ->where('role', 'employee')
    ->where('business_admin_id', $admin->id)
    ->first();

if ($existingEmployee) {
    // ✅ NOUVEAU: Vérifier si cet employé est déjà assigné à CETTE commande (même commande)
    $alreadyInThisOrder = \App\Models\OrderEmployee::where('employee_id', $existingEmployee->id)
        ->where('order_id', $order->id)
        ->exists();

    if ($alreadyInThisOrder) {
        return response()->json([
            'message' => 'Cet employé est déjà assigné à cette commande.'
        ], 400);
    }

    // ✅ AUTORISER la réassignation pour une NOUVELLE commande
    // L'employé peut être réutilisé dans plusieurs commandes différentes
    Log::info('Réutilisation d\'un employé existant pour une nouvelle commande', [
        'employee_id' => $existingEmployee->id,
        'employee_email' => $existingEmployee->email,
        'order_id' => $order->id,
        'order_number' => $order->order_number,
    ]);
}
            // Créer ou récupérer l'employé
            $employee = User::where('email', $validated['employee_email'])->first();

            if (!$employee) {
                // Créer un nouvel employé
                $temporaryPassword = Str::random(10);

                // Générer un username unique basé sur le nom
                $baseUsername = Str::slug($validated['employee_name']);
                $username = $baseUsername;
                $counter = 1;
                while (User::where('username', $username)->exists()) {
                    $username = $baseUsername . '-' . $counter;
                    $counter++;
                }

                $employee = User::create([
                    'name' => $validated['employee_name'],
                    'email' => $validated['employee_email'],
                    'username' => $username,
                    'password' => Hash::make($temporaryPassword),
                    'role' => 'employee',
                    'company_name' => $admin->company_name,
                    'business_admin_id' => $admin->id,
                    'password_reset_required' => true, // Forcer le changement de mot de passe
                    'is_profile_complete' => true, // Permet la connexion avec le mot de passe temporaire
                ]);

                                // Envoyer l'email de bienvenue
                                try {
                                    $loginUrl = config('app.url_frontend', 'https://digicard.arccenciel.com/');
                                    Mail::to($employee->email)->send(new EmployeeWelcomeMail(
                                        $temporaryPassword,
                                        $employee->email,
                                        $employee->company_name,
                                        $loginUrl
                                    ));

                                    // ✅ AJOUT: Log pour confirmer l'envoi
                                    Log::info('Email de bienvenue envoyé au nouvel employé', [
                                        'employee_email' => $employee->email,
                                        'order_id' => $order->id,
                                    ]);
                                } catch (\Throwable $t) {
                                    Log::error("Échec de l'envoi de l'email à " . $employee->email . ": " . $t->getMessage());
                                }
                            } else {
                                // Vérifier que l'employé appartient bien à cet admin
                                if ($employee->business_admin_id !== $admin->id) {
                                    return response()->json(['message' => 'Cet email appartient à un autre compte.'], 400);
                                }

                                // ✅ NOUVEAU: Email pour employé existant recevant de nouvelles cartes
                                try {
                                    $loginUrl = config('app.url_frontend', 'https://digicard.arccenciel.com/');
                                    Mail::to($employee->email)->send(new \App\Mail\EmployeeNewCardsNotification(
                                        $employee->name,
                                        $slots[$slotIndex]['cards_quantity'],
                                        $order->order_number,
                                        $employee->company_name,
                                        $loginUrl
                                    ));

                                    Log::info('Email de nouvelles cartes envoyé à l\'employé existant', [
                                        'employee_email' => $employee->email,
                                        'order_number' => $order->order_number,
                                        'card_quantity' => $slots[$slotIndex]['cards_quantity'],
                                    ]);
                                } catch (\Throwable $t) {
                                    Log::error("Échec de l'envoi de l'email de nouvelles cartes à " . $employee->email . ": " . $t->getMessage());
                                }
                            }

            // Mettre à jour le slot
            $slots[$slotIndex]['employee_id'] = $employee->id;
            $slots[$slotIndex]['employee_name'] = $employee->name;
            $slots[$slotIndex]['employee_email'] = $employee->email;
            $slots[$slotIndex]['employee_username'] = $employee->username; // Ajouter le username
            $slots[$slotIndex]['is_assigned'] = true;

            // S'assurer que la quantité de cartes est définie
            if (!isset($slots[$slotIndex]['cards_quantity'])) {
                $slots[$slotIndex]['cards_quantity'] = 1; // Par défaut 1 carte
            }

            // ✅ NOUVEAU: Si le slot avait un ancien employee_id différent, supprimer l'ancien OrderEmployee
            $oldEmployeeId = $slot['employee_id'] ?? null;
            if ($oldEmployeeId && $oldEmployeeId != $employee->id) {
                // Supprimer l'ancien OrderEmployee pour ce slot
                \App\Models\OrderEmployee::where('order_id', $order->id)
                    ->where('employee_id', $oldEmployeeId)
                    ->delete();

                Log::info('Ancien OrderEmployee supprimé lors de la réassignation du slot', [
                    'order_id' => $order->id,
                    'slot_number' => $slotNumber,
                    'old_employee_id' => $oldEmployeeId,
                    'new_employee_id' => $employee->id,
                ]);
            }

            // Sauvegarder les slots mis à jour
            $order->employee_slots = $slots;
            $order->save();

            // Créer l'entrée OrderEmployee (ou mettre à jour si elle existe déjà)
            $orderEmployee = \App\Models\OrderEmployee::updateOrCreate(
                [
                    'order_id' => $order->id,
                    'employee_id' => $employee->id,
                ],
                [
                    'employee_email' => $employee->email,
                    'employee_name' => $employee->name,
                    'card_quantity' => max(1, $slots[$slotIndex]['cards_quantity']), // ✅ MINIMUM 1 carte
                    'is_configured' => false,
                ]
            );

            Log::info('OrderEmployee créé/récupéré', [
                'order_employee_id' => $orderEmployee->id,
                'order_id' => $order->id,
                'employee_id' => $employee->id,
                'card_quantity' => $orderEmployee->card_quantity,
            ]);

            return response()->json([
                'message' => 'Employé assigné avec succès !',
                'order' => $order->fresh(),
                'order_employee' => $orderEmployee,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Échec de l\'assignation du slot: ' . $e->getMessage());
            return response()->json(['message' => 'Erreur lors de l\'assignation.'], 500);
        }
    }
}
