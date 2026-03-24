<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketplaceMatchScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'offer_id',
        'match_score',
        'match_details',
        'notified',
        'notified_at',
        'last_calculated_at',
    ];

    protected $casts = [
        'match_score' => 'decimal:2',
        'match_details' => 'array',
        'notified' => 'boolean',
        'notified_at' => 'datetime',
        'last_calculated_at' => 'datetime',
    ];

    /**
     * Relation avec l'utilisateur
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relation avec l'offre
     */
    public function offer()
    {
        return $this->belongsTo(MarketplaceOffer::class, 'offer_id');
    }
}
