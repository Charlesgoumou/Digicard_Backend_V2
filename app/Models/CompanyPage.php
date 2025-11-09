<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'order_id',
        'company_name',
        'company_name_short',
        'company_website_url',
        'website_featured_in_services_button',
        'logo_url',
        'primary_color',
        'secondary_color',
        'services',
        'hero_headline',
        'hero_subheadline',
        'hero_description',
        'chart_labels',
        'chart_data',
        'chart_colors',
        'chart_title',
        'chart_description',
        'pillars',
        'pillars_title',
        'engagement_description',
        'products_button_text',
        'products_button_icon',
        'products_modal_title',
        'processes_title',
        'process_order_title',
        'process_order_description',
        'process_order_steps',
        'process_logistics_title',
        'process_logistics_description',
        'process_logistics_steps',
        'contact_email', // Email uniquement, les autres infos sont récupérées depuis "Ma Carte"
        'is_published',
    ];

    protected $casts = [
        'services' => 'array',
        'chart_labels' => 'array',
        'chart_data' => 'array',
        'chart_colors' => 'array',
        'pillars' => 'array',
        'process_order_steps' => 'array',
        'process_logistics_steps' => 'array',
        'is_published' => 'boolean',
        'website_featured_in_services_button' => 'boolean',
    ];

    /**
     * Relation avec le user (business_admin)
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relation avec la commande
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
