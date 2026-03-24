<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplacePurchase extends Model
{
    use HasFactory;

    public const FULFILLMENT_PENDING = 'pending';

    public const FULFILLMENT_IN_PROGRESS = 'in_progress';

    public const FULFILLMENT_AWAITING_BUYER = 'awaiting_buyer';

    public const FULFILLMENT_DISPUTE_REQUESTED = 'dispute_requested';

    public const FULFILLMENT_COMPLETED = 'completed';

    public const FULFILLMENT_REFUNDED = 'refunded';

    public const FULFILLMENT_CANCELLED = 'cancelled';

    protected $fillable = [
        'offer_id',
        'buyer_id',
        'price',
        'currency',
        'status',
        'fulfillment_status',
        'seller_fulfilled_at',
        'buyer_confirmed_at',
        'buyer_disputed_at',
        'dispute_reason',
        'admin_decided_at',
        'refund_processed_at',
        'refund_reason',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'seller_fulfilled_at' => 'datetime',
        'buyer_confirmed_at' => 'datetime',
        'buyer_disputed_at' => 'datetime',
        'admin_decided_at' => 'datetime',
        'refund_processed_at' => 'datetime',
    ];

    /**
     * Relation avec l'offre
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOffer::class, 'offer_id');
    }

    /**
     * Relation avec l'acheteur
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }
}
