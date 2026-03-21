<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderEmployeePointage extends Model
{
    protected $fillable = [
        'order_employee_id',
        'work_date',
        'check_in_time',
        'check_in_lat',
        'check_in_lng',
        'check_out_time',
        'check_out_lat',
        'check_out_lng',
        'duration_minutes',
    ];

    protected $casts = [
        'work_date' => 'date',
        'check_in_time' => 'datetime',
        'check_out_time' => 'datetime',
        'check_in_lat' => 'float',
        'check_in_lng' => 'float',
        'check_out_lat' => 'float',
        'check_out_lng' => 'float',
        'duration_minutes' => 'integer',
    ];

    public function orderEmployee(): BelongsTo
    {
        return $this->belongsTo(OrderEmployee::class);
    }
}
