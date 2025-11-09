<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'order_number',
        'order_type',
        'order_avatar_url',
        'profile_name',
        'profile_title',
        'profile_border_color',
        'save_contact_button_color',
        'services_button_color',
        'phone_numbers',
        'emails',
        'birth_day',
        'birth_month',
        'website_url',
        'address_neighborhood',
        'address_commune',
        'address_city',
        'address_country',
        'whatsapp_url',
        'linkedin_url',
        'facebook_url',
        'twitter_url',
        'youtube_url',
        'deezer_url',
        'spotify_url',
        'card_design_type',
        'card_design_number',
        'card_design_custom_url',
        'no_design_yet',
        'card_quantity',
        'total_employees',
        'employee_slots',
        'cards_per_employee',
        'unit_price',
        'total_price',
        'additional_cards_count',
        'additional_cards_total_price',
        'annual_subscription',
        'subscription_start_date',
        'status',
        'is_configured',
        'access_token',
    ];

    protected $casts = [
        'subscription_start_date' => 'date',
        'is_configured' => 'boolean',
        'employee_slots' => 'array',
        'phone_numbers' => 'array',
        'emails' => 'array',
    ];

    /**
     * Relation : Une commande appartient à un utilisateur
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relation : Une commande peut avoir plusieurs employés (pour les commandes business)
     */
    public function orderEmployees()
    {
        return $this->hasMany(OrderEmployee::class);
    }

    /**
     * Génère un numéro de commande unique
     */
    public static function generateOrderNumber()
    {
        do {
            $orderNumber = 'CMD-' . strtoupper(uniqid());
        } while (self::where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }

    /**
     * Génère un token d'accès unique pour la commande
     * Le token est composé de chiffres, lettres et caractères spéciaux
     */
    public function generateAccessToken()
    {
        // Générer un token aléatoire de 32 caractères
        // Utiliser des caractères alphanumériques et quelques caractères spéciaux sécurisés
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*';
        $token = '';
        
        for ($i = 0; $i < 32; $i++) {
            $token .= $characters[random_int(0, strlen($characters) - 1)];
        }
        
        // Vérifier que le token est unique
        while (self::where('access_token', $token)->exists()) {
            $token = '';
            for ($i = 0; $i < 32; $i++) {
                $token .= $characters[random_int(0, strlen($characters) - 1)];
            }
        }
        
        return $token;
    }

    /**
     * Boot method pour générer automatiquement le token lors de la validation
     */
    protected static function boot()
    {
        parent::boot();

        // Générer le token automatiquement quand la commande est validée
        static::updating(function ($order) {
            if ($order->isDirty('status') && $order->status === 'validated' && !$order->access_token) {
                $order->access_token = $order->generateAccessToken();
            }
        });

        // Générer le token pour les commandes déjà validées lors de la récupération
        static::retrieved(function ($order) {
            if ($order->status === 'validated' && !$order->access_token) {
                $order->access_token = $order->generateAccessToken();
                $order->saveQuietly(); // Sauvegarder sans déclencher les événements
            }
        });
    }
}
