<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceUserNeed extends Model
{
    use HasFactory;

    protected $table = 'marketplace_user_needs';

    protected $fillable = [
        'user_id',
        'source',
        'source_ref',
        'keywords',
        'needs',
        'last_error',
        'last_extracted_at',
    ];

    protected $casts = [
        'keywords' => 'array',
        'needs' => 'array',
        'last_extracted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

