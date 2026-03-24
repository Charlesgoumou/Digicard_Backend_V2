<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceOffer extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'type',
        'category',
        'price',
        'currency',
        'image_url',
        'is_active',
    ];

    /** @return list<string> */
    public static function allowedCategoryKeys(): array
    {
        return [
            'electronique_informatique',
            'commerce_vente',
            'services_pro',
            'alimentation',
            'transport_logistique',
            'immobilier',
            'formation_education',
            'sante_beaute',
            'art_culture_loisirs',
            'autre',
        ];
    }

    public static function categoryLabel(?string $key): string
    {
        return match ($key) {
            'electronique_informatique' => 'Électronique & informatique',
            'commerce_vente' => 'Commerce & vente',
            'services_pro' => 'Services professionnels',
            'alimentation' => 'Alimentation',
            'transport_logistique' => 'Transport & logistique',
            'immobilier' => 'Immobilier',
            'formation_education' => 'Formation & éducation',
            'sante_beaute' => 'Santé & beauté',
            'art_culture_loisirs' => 'Art, culture & loisirs',
            'autre', null, '' => 'Autre / divers',
            default => 'Autre / divers',
        };
    }

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Relation avec l'utilisateur vendeur
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Relation avec les avis
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(MarketplaceReview::class, 'offer_id');
    }

    /**
     * Relation avec les favoris
     */
    public function favorites(): HasMany
    {
        return $this->hasMany(MarketplaceFavorite::class, 'offer_id');
    }

    /**
     * Relation avec les achats
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(MarketplacePurchase::class, 'offer_id');
    }

    /**
     * Relation avec les images
     */
    public function images(): HasMany
    {
        return $this->hasMany(MarketplaceOfferImage::class, 'marketplace_offer_id');
    }

    /**
     * Relation avec les messages
     */
    public function messages(): HasMany
    {
        return $this->hasMany(MarketplaceMessage::class, 'offer_id');
    }
}
