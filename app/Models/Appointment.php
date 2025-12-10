<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    use HasFactory;

    /**
     * Les statuts possibles pour un rendez-vous.
     */
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_COMPLETED = 'completed';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'order_id',
        'visitor_name',
        'visitor_email',
        'visitor_phone',
        'message',
        'start_time',
        'end_time',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
        ];
    }

    /**
     * Relation : Récupère le propriétaire de la carte (celui qui reçoit le rendez-vous).
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope : Filtre les rendez-vous confirmés.
     */
    public function scopeConfirmed($query)
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }

    /**
     * Scope : Filtre les rendez-vous annulés.
     */
    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    /**
     * Scope : Filtre les rendez-vous terminés.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope : Filtre les rendez-vous à venir.
     */
    public function scopeUpcoming($query)
    {
        return $query->where('start_time', '>', now())
                     ->where('status', self::STATUS_CONFIRMED);
    }

    /**
     * Scope : Filtre les rendez-vous passés.
     */
    public function scopePast($query)
    {
        return $query->where('end_time', '<', now());
    }

    /**
     * Scope : Filtre les rendez-vous pour une date donnée.
     */
    public function scopeForDate($query, $date)
    {
        return $query->whereDate('start_time', $date);
    }

    /**
     * Vérifie si le rendez-vous est confirmé.
     */
    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    /**
     * Vérifie si le rendez-vous est annulé.
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Vérifie si le rendez-vous est terminé.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Vérifie si le rendez-vous est à venir.
     */
    public function isUpcoming(): bool
    {
        return $this->start_time->isFuture() && $this->isConfirmed();
    }

    /**
     * Vérifie si le rendez-vous est passé.
     */
    public function isPast(): bool
    {
        return $this->end_time->isPast();
    }

    /**
     * Retourne la durée du rendez-vous en minutes.
     */
    public function getDurationInMinutes(): int
    {
        return $this->start_time->diffInMinutes($this->end_time);
    }
}

