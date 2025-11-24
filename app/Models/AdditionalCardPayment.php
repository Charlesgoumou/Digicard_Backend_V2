<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdditionalCardPayment extends Model
{
    protected $fillable = [
        'order_id',
        'user_id',
        'quantity',
        'distribution',
        'unit_price',
        'total_price',
        'payment_status',
        'payment_provider',
        'payment_operation_id',
        'payment_url',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'distribution' => 'array',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    /**
     * Relation avec la commande
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Relation avec l'utilisateur
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Vérifier si le paiement est en attente
     */
    public function isPending(): bool
    {
        return $this->payment_status === 'pending';
    }

    /**
     * Vérifier si le paiement est payé
     */
    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }
}
