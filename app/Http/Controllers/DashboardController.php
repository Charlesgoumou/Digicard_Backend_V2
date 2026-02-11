<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentSetting;
use App\Models\Order;
use App\Models\OrderEmployee;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Retourne les cartes du dashboard à afficher selon le contexte utilisateur.
     * Option A : calcul dynamique côté serveur pour éviter le flash de cartes non configurées.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCards(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        // Cartes toujours visibles
        $parametrer_carte = true;
        $afficher_profil = true;
        $mes_contacts = true;
        $mes_commandes = true;
        $marketplace = true;

        // Mes Rendez-vous : visible si Prise de RDV activée OU au moins 1 rendez-vous confirmé
        $mes_rendez_vous = $this->shouldShowAppointmentsCard($user);

        // Tableau de bord : visible uniquement pour business_admin avec commande entreprise et au moins 1 employé assigné
        $tableau_de_bord = $this->shouldShowDashboardCard($user);

        return response()->json([
            'dashboard_cards' => [
                'parametrer_carte' => $parametrer_carte,
                'afficher_profil' => $afficher_profil,
                'mes_contacts' => $mes_contacts,
                'mes_commandes' => $mes_commandes,
                'marketplace' => $marketplace,
                'mes_rendez_vous' => $mes_rendez_vous,
                'tableau_de_bord' => $tableau_de_bord,
            ],
        ]);
    }

    /**
     * Détermine si la carte "Mes Rendez-vous" doit être affichée.
     * Visible si : Prise de rendez-vous activée dans Ma Carte OU au moins 1 rendez-vous confirmé sur le profil public.
     */
    private function shouldShowAppointmentsCard($user): bool
    {
        // Condition 1 : Au moins un AppointmentSetting avec is_enabled = true pour cet utilisateur
        $hasEnabledSettings = AppointmentSetting::where('user_id', $user->id)
            ->where('is_enabled', true)
            ->exists();

        if ($hasEnabledSettings) {
            return true;
        }

        // Condition 2 : Au moins un rendez-vous confirmé sur le profil public de l'utilisateur
        $hasAppointments = Appointment::where('user_id', $user->id)
            ->where('status', 'confirmed')
            ->exists();

        return $hasAppointments;
    }

    /**
     * Détermine si la carte "Tableau de bord" doit être affichée.
     * Visible uniquement si : business_admin ET au moins une commande entreprise avec au moins 1 employé assigné.
     */
    private function shouldShowDashboardCard($user): bool
    {
        if ($user->role !== 'business_admin') {
            return false;
        }

        // Récupérer les commandes business de l'admin
        $businessOrderIds = Order::where('user_id', $user->id)
            ->where('order_type', 'business')
            ->where('status', '!=', 'cancelled')
            ->pluck('id');

        if ($businessOrderIds->isEmpty()) {
            return false;
        }

        // Vérifier qu'au moins une commande a au moins 1 employé assigné (OrderEmployee avec employee_id)
        $hasEmployeeAssigned = OrderEmployee::whereIn('order_id', $businessOrderIds)
            ->whereNotNull('employee_id')
            ->exists();

        return $hasEmployeeAssigned;
    }
}
