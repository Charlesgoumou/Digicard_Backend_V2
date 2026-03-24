<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminRolePermission extends Model
{
    use HasFactory;

    protected $table = 'admin_role_permissions';

    protected $fillable = [
        'role',
        'permission_key',
    ];
}

