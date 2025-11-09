<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserPortfolio extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'profile_type',
        'name',
        'photo_url',
        'hero_headline',
        'bio',
        'skills',
        'skills_title',
        'projects',
        'projects_title',
        'timeline',
        'timeline_title',
        'email',
        'phone',
        'linkedin_url',
        'github_url',
        'primary_color',
        'secondary_color',
        'profile_title',
    ];

    protected $casts = [
        'skills' => 'array',
        'projects' => 'array',
        'timeline' => 'array',
    ];

    /**
     * Relation avec l'utilisateur
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

