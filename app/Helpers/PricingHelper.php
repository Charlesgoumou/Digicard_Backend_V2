<?php

namespace App\Helpers;

use App\Models\Setting;

class PricingHelper
{
    // Valeurs par défaut (fallback si non configuré en base)
    const DEFAULT_BASE_PRICE = 180000;          // Première carte
    const DEFAULT_EXTRA_PRICE = 45000;          // Carte additionnelle
    const DEFAULT_ANNUAL_SUBSCRIPTION = 40000;  // Abonnement annuel

    /**
     * Calcule le prix total pour un nombre de cartes donné.
     * Système : Première carte = 180 000 GNF, cartes supplémentaires = 45 000 GNF chacune
     *
     * @param int $cardQuantity Nombre de cartes
     * @return int Prix total en GNF
     */
    public static function calculatePrice(int $cardQuantity): int
    {
        if ($cardQuantity <= 0) {
            return 0;
        }

        $base = self::getBasePrice();
        $extra = self::getExtraPrice();

        // Première carte + cartes supplémentaires
        return $base + (($cardQuantity - 1) * $extra);
    }

    /**
     * Calcule le prix total pour une commande entreprise avec plusieurs employés.
     * Chaque employé a sa première carte à 180 000 GNF, puis 45 000 GNF par carte supplémentaire.
     *
     * @param array $employeesData Tableau avec les quantités de cartes par employé
     * @return int Prix total en GNF
     */
    public static function calculateBusinessPrice(array $employeesData): int
    {
        $totalPrice = 0;

        foreach ($employeesData as $employee) {
            $cardQuantity = $employee['card_quantity'] ?? $employee['cards_quantity'] ?? 0;
            if ($cardQuantity > 0) {
                $totalPrice += self::calculatePrice($cardQuantity);
            }
        }

        return $totalPrice;
    }

    /**
     * Calcule le prix pour un nombre uniforme de cartes par employé.
     *
     * @param int $numberOfEmployees Nombre d'employés
     * @param int $cardsPerEmployee Nombre de cartes par employé
     * @return int Prix total en GNF
     */
    public static function calculateUniformBusinessPrice(int $numberOfEmployees, int $cardsPerEmployee): int
    {
        if ($numberOfEmployees <= 0 || $cardsPerEmployee <= 0) {
            return 0;
        }

        $pricePerEmployee = self::calculatePrice($cardsPerEmployee);
        return $numberOfEmployees * $pricePerEmployee;
    }

    /**
     * Obtient le prix de base (première carte).
     *
     * @return int Prix de base en GNF
     */
    public static function getBasePrice(): int
    {
        $value = Setting::get('card_price');
        return is_numeric($value) ? (int) $value : self::DEFAULT_BASE_PRICE;
    }

    /**
     * Obtient le prix d'une carte supplémentaire.
     *
     * @return int Prix d'une carte supplémentaire en GNF
     */
    public static function getExtraPrice(): int
    {
        $value = Setting::get('additional_card_price');
        return is_numeric($value) ? (int) $value : self::DEFAULT_EXTRA_PRICE;
    }

    /**
     * Obtient le montant de l'abonnement annuel.
     *
     * @return int Montant de l'abonnement annuel en GNF
     */
    public static function getAnnualSubscription(): int
    {
        $value = Setting::get('subscription_price');
        return is_numeric($value) ? (int) $value : self::DEFAULT_ANNUAL_SUBSCRIPTION;
    }
}
