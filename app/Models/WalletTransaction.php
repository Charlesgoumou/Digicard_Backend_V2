<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    protected $fillable = [
        'wallet_id',
        'user_id',
        'direction',
        'type',
        'amount_minor',
        'currency',
        'status',
        'marketplace_offer_id',
        'marketplace_purchase_id',
        'external_provider',
        'external_reference',
        'idempotency_key',
        'meta',
        'completed_at',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'meta' => 'array',
        'completed_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

