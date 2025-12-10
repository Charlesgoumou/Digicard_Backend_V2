<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppointmentSetting extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'order_id',
        'is_enabled',
        'weekly_availability',
    ];

    /**
     * Get the attributes that should be cast.
     * 
     * weekly_availability stocke maintenant des règles de dates :
     * {
     *   "date_rules": [
     *     {
     *       "day_of_week": 1,  // Lundi (1 = Lundi, 7 = Dimanche)
     *       "type": "specific", // "specific" ou "recurring_month"
     *       "dates": ["2025-12-08", "2025-12-15"], // dates spécifiques si type = "specific"
     *       "month": 12, // si type = "recurring_month"
     *       "year": 2025, // si type = "recurring_month"
     *       "slots": [
     *         { "start": "08:00", "duration": 30 }
     *       ]
     *     }
     *   ]
     * }
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'weekly_availability' => 'array',
        ];
    }

    /**
     * Relation : Récupère le propriétaire de cette configuration.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relation : Récupère la commande associée à cette configuration.
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Retourne la configuration par défaut pour weekly_availability.
     * Structure vide avec date_rules.
     *
     * @return array
     */
    public static function getDefaultWeeklyAvailability(): array
    {
        return [
            'date_rules' => [],
        ];
    }

    /**
     * Récupère les créneaux disponibles pour une date donnée.
     * 
     * Compatible avec l'ancienne structure (jours de la semaine) et la nouvelle (date_rules).
     * 
     * @param \Carbon\Carbon $date
     * @return array Array de slots avec start, duration
     */
    public function getSlotsForDate(\Carbon\Carbon $date): array
    {
        $availability = $this->weekly_availability ?? [];
        
        // Vérifier si c'est la nouvelle structure (date_rules)
        if (isset($availability['date_rules']) && is_array($availability['date_rules'])) {
            return $this->getSlotsFromDateRules($date, $availability['date_rules']);
        }
        
        // Sinon, utiliser l'ancienne structure (jours de la semaine)
        return $this->getSlotsFromOldStructure($date, $availability);
    }
    
    /**
     * Récupère les créneaux depuis la nouvelle structure (date_rules).
     * 
     * @param \Carbon\Carbon $date
     * @param array $dateRules
     * @return array
     */
    private function getSlotsFromDateRules(\Carbon\Carbon $date, array $dateRules): array
    {
        $dayOfWeek = $date->dayOfWeekIso; // 1 = Lundi, 7 = Dimanche
        $dateString = $date->format('Y-m-d');
        $month = (int) $date->format('n'); // 1-12
        $year = (int) $date->format('Y');
        
        $matchingSlots = [];
        
        foreach ($dateRules as $rule) {
            $type = $rule['type'] ?? 'specific';
            
            if ($type === 'specific') {
                // Pour les dates spécifiques, vérifier d'abord si la date est dans la liste
                // indépendamment du jour de la semaine (car l'utilisateur peut avoir sélectionné
                // des dates qui ne correspondent pas exactement au jour configuré)
                $dates = $rule['dates'] ?? [];
                if (in_array($dateString, $dates)) {
                    $matchingSlots = array_merge($matchingSlots, $rule['slots'] ?? []);
                }
            } elseif ($type === 'recurring_month') {
                // Pour les récurrences mensuelles, vérifier le jour de la semaine ET le mois/année
                $ruleDayOfWeek = $rule['day_of_week'] ?? null;
                $ruleMonth = $rule['month'] ?? null;
                $ruleYear = $rule['year'] ?? null;
                
                if ($ruleDayOfWeek == $dayOfWeek && $ruleMonth == $month && $ruleYear == $year) {
                    $matchingSlots = array_merge($matchingSlots, $rule['slots'] ?? []);
                }
            }
        }
        
        return $matchingSlots;
    }
    
    /**
     * Récupère les créneaux depuis l'ancienne structure (jours de la semaine).
     * 
     * @param \Carbon\Carbon $date
     * @param array $availability
     * @return array
     */
    private function getSlotsFromOldStructure(\Carbon\Carbon $date, array $availability): array
    {
        $dayOfWeek = $date->dayOfWeekIso; // 1 = Lundi, 7 = Dimanche
        $dayKey = (string) $dayOfWeek;
        
        // Vérifier si le jour est activé dans l'ancienne structure
        if (!isset($availability[$dayKey]) || !($availability[$dayKey]['enabled'] ?? false)) {
            return [];
        }
        
        return $availability[$dayKey]['slots'] ?? [];
    }
}

