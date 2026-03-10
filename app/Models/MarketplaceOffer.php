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
        'price',
        'currency',
        'image_url',
        'is_active',
    ];

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
