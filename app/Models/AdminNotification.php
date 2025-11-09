<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'user_id',
        'order_id',
        'employee_id',
        'message',
        'url',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];
}


