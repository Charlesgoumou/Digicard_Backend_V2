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
        'tiktok_url',
        'threads_url',
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
        'short_code',
        'payment_session_token', // ✅ NOUVEAU: Token pour restaurer la session après redirection externe
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
     * ✅ CORRECTION : Utiliser uniquement des caractères URL-safe pour éviter les problèmes d'encodage
     * Le token est composé de chiffres, lettres et quelques caractères spéciaux sécurisés (URL-safe)
     */
    public function generateAccessToken()
    {
        // ✅ CORRECTION : Utiliser uniquement des caractères URL-safe
        // Éviter : # (fragment), & (séparateur), % (encodage), ^ (peut causer des problèmes)
        // Utiliser : lettres, chiffres, - (tiret), _ (underscore), . (point)
        // Ces caractères ne nécessitent pas d'encodage URL et sont sécurisés
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ-_';
        $token = '';
        
        // Générer un token de 64 caractères pour plus de sécurité et d'unicité
        for ($i = 0; $i < 64; $i++) {
            $token .= $characters[random_int(0, strlen($characters) - 1)];
        }
        
        // Vérifier que le token est unique
        while (self::where('access_token', $token)->exists()) {
            $token = '';
            for ($i = 0; $i < 64; $i++) {
                $token .= $characters[random_int(0, strlen($characters) - 1)];
            }
        }
        
        return $token;
    }

    /**
     * Génère un code court URL-safe (base62) pour les URLs publiques.
     */
    public function generateShortCode(int $length = 8): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $maxIndex = strlen($characters) - 1;

        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $characters[random_int(0, $maxIndex)];
            }
        } while (self::where('short_code', $code)->exists());

        return $code;
    }

    /**
     * Boot method pour générer automatiquement le token
     * ✅ CORRECTION : Générer le token dès la création de la commande (pas seulement à la validation)
     * Cela garantit que chaque commande a son URL unique dès le départ
     */
    protected static function boot()
    {
        parent::boot();

        // ✅ NOUVEAU : Générer le token automatiquement lors de la création de la commande
        // Cela garantit que chaque nouvelle commande a son URL unique dès le départ
        static::creating(function ($order) {
            // Ne générer le token que pour les nouvelles commandes (pas de token existant)
            // Les anciennes commandes gardent leur token existant ou null (fallback sur ?order=id)
            if (!$order->access_token) {
                $order->access_token = $order->generateAccessToken();
            }

            // Générer aussi le code court si la colonne existe et si absent
            if (!$order->short_code) {
                $order->short_code = $order->generateShortCode();
            }
        });

        // Générer le token automatiquement quand la commande est validée (si pas déjà généré)
        static::updating(function ($order) {
            // ✅ CORRECTION : Ne générer le token que si la commande est validée ET n'a pas encore de token
            // Cela permet aux anciennes commandes de garder leur comportement (pas de token = fallback sur ?order=id)
            if ($order->isDirty('status') && $order->status === 'validated' && !$order->access_token) {
                $order->access_token = $order->generateAccessToken();
            }

            // Assurer un short_code pour les commandes existantes quand elles passent en validated
            if ($order->isDirty('status') && $order->status === 'validated' && !$order->short_code) {
                $order->short_code = $order->generateShortCode();
            }
        });

        // ✅ SUPPRIMÉ : Ne plus générer le token lors de la récupération pour les anciennes commandes
        // Les anciennes commandes sans token utiliseront le fallback ?order=id
        // Seules les nouvelles commandes auront un token unique dès la création
    }
}
