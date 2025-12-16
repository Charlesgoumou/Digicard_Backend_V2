<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     * Les champs qui peuvent être modifiés via $user->update(...) ou User::create(...)
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'company_name',
        'business_admin_id',
        'verification_code', // Techniquement pas modifié par l'utilisateur directement
        'verification_code_expires_at', // Idem
        'avatar_url', // Modifié via une route dédiée
        'initial_password_set',
        'password_reset_required', // Pour forcer le changement de mot de passe
        'is_admin', // Super Admin
        'is_suspended', // Compte suspendu
        'two_factor_enabled', // Activation/désactivation de la 2FA
        // --- Champs du Profil ajoutés ---
        'username',
        'title',
        'vcard_phone',
        'vcard_email',
        'vcard_company',
        'vcard_address',
        'whatsapp_url',
        'linkedin_url',
        'facebook_url',
        'twitter_url',
        'youtube_url',
        'deezer_url',
        'spotify_url',
        'tiktok_url',
        'threads_url',
        // --- Nouveaux champs de personnalisation avancée ---
        'profile_border_color',
        'save_contact_button_color',
        'services_button_color',
        'phone_numbers', // JSON
        'emails', // JSON
        'birth_day',
        'birth_month',
        'website_url',
        'address_neighborhood',
        'address_commune',
        'address_city',
        'address_country',
        // Champs pour le changement d'email
        'pending_email',
        'email_change_code',
        'email_change_code_expires_at',
        // --- Champs Google OAuth ---
        'google_id',
        'phone',
        'account_type',
        'is_profile_complete',
    ];

    /**
     * The attributes that should be hidden for serialization.
     * Champs à ne pas renvoyer dans les réponses JSON (sécurité/confidentialité)
     */
    protected $hidden = [
        'password',
        'remember_token',
        'verification_code', // Ne pas exposer le code
    ];

    /**
     * Get the attributes that should be cast.
     * Définit le type de certaines colonnes
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed', // Laravel s'occupe du hashage
            'verification_code_expires_at' => 'datetime',
            'initial_password_set' => 'boolean',
            'password_reset_required' => 'boolean',
            'is_admin' => 'boolean',
            'is_suspended' => 'boolean',
            'phone_numbers' => 'array', // Convertit JSON en tableau PHP
            'emails' => 'array', // Convertit JSON en tableau PHP
            'email_change_code_expires_at' => 'datetime',
            'is_profile_complete' => 'boolean',
        ];
    }

    /**
     * Relation : Récupère l'admin de cet employé (si applicable).
     */
    public function businessAdmin()
    {
        return $this->belongsTo(User::class, 'business_admin_id');
    }

    /**
     * Relation : Récupère les employés de cet admin (si applicable).
     */
    public function employees()
    {
        return $this->hasMany(User::class, 'business_admin_id');
    }

    /**
     * Relation : Récupère les commandes de cet utilisateur.
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Relation : Récupère la configuration des rendez-vous de cet utilisateur.
     */
    public function appointmentSetting()
    {
        return $this->hasOne(AppointmentSetting::class);
    }

    /**
     * Relation : Récupère les rendez-vous de cet utilisateur (en tant que propriétaire de carte).
     */
    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Accesseur pour le téléphone (compatibilité avec le frontend).
     * Retourne vcard_phone comme téléphone principal.
     */
    public function getPhoneAttribute()
    {
        return $this->vcard_phone;
    }
}
