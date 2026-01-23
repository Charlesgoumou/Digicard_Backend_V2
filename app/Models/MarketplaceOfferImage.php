<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceOfferImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'marketplace_offer_id',
        'image_url',
        'order',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'order' => 'integer',
    ];

    /**
     * Relation avec l'offre
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOffer::class, 'marketplace_offer_id');
    }
}
