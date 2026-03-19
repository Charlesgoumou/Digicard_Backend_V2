<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderEmployee extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'employee_id',
        'employee_email',
        'employee_name',
        'employee_matricule',
        'employee_department',
        'employee_group',
        'card_quantity',
        'is_configured',
        // Champs de profil individuels
        'profile_name',
        'profile_title',
        'employee_avatar_url',
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
        // Champs de design
        'card_design_type',
        'card_design_number',
        'card_design_custom_url',
        'no_design_yet',
    ];

    protected $casts = [
        'is_configured' => 'boolean',
        'phone_numbers' => 'array',
        'emails' => 'array',
        'no_design_yet' => 'boolean',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }
}
