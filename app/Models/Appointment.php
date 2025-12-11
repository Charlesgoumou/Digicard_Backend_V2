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
        'is_downloaded',
        'downloaded_at',
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
            'is_downloaded' => 'boolean',
            'downloaded_at' => 'datetime',
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
     * Scope : Filtre les rendez-vous non téléchargés.
     */
    public function scopeNotDownloaded($query)
    {
        return $query->where('is_downloaded', false);
    }

    /**
     * Scope : Filtre les rendez-vous téléchargés.
     */
    public function scopeDownloaded($query)
    {
        return $query->where('is_downloaded', true);
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

    /**
     * Vérifie si le rendez-vous a été téléchargé.
     */
    public function isDownloaded(): bool
    {
        return $this->is_downloaded === true;
    }

    /**
     * Génère le contenu ICS (iCalendar) pour ce rendez-vous.
     * 
     * @return string
     */
    public function generateIcsContent(): string
    {
        $owner = $this->user;
        
        // Formater les dates au format iCalendar (UTC)
        $dtStart = $this->start_time->setTimezone('UTC')->format('Ymd\THis\Z');
        $dtEnd = $this->end_time->setTimezone('UTC')->format('Ymd\THis\Z');
        $dtStamp = now()->setTimezone('UTC')->format('Ymd\THis\Z');
        $created = $this->created_at->setTimezone('UTC')->format('Ymd\THis\Z');
        
        // Identifiant unique pour l'événement
        $uid = 'digicard-appointment-' . $this->id . '@arccenciel.com';
        
        // Description de l'événement
        $description = "Rendez-vous avec {$this->visitor_name}";
        if ($this->message) {
            $description .= "\\n\\nMotif : {$this->message}";
        }
        $description .= "\\n\\nContact visiteur :";
        $description .= "\\nEmail : {$this->visitor_email}";
        if ($this->visitor_phone) {
            $description .= "\\nTéléphone : {$this->visitor_phone}";
        }
        $description .= "\\n\\nRéservé via DigiCard";
        
        // Titre de l'événement
        $summary = "RDV: {$this->visitor_name}";
        
        // Email organisateur
        $organizerEmail = config('mail.from.address', 'noreply@arccenciel.com');
        $organizerName = 'DigiCard System';
        
        // Email du propriétaire (attendee)
        $attendeeEmail = $owner ? $owner->email : '';
        $attendeeName = $owner ? $owner->name : '';

        // Construction du fichier ICS
        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//DigiCard//Appointment System//FR\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:PUBLISH\r\n";
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:{$uid}\r\n";
        $ics .= "DTSTAMP:{$dtStamp}\r\n";
        $ics .= "DTSTART:{$dtStart}\r\n";
        $ics .= "DTEND:{$dtEnd}\r\n";
        $ics .= "CREATED:{$created}\r\n";
        $ics .= "SUMMARY:{$summary}\r\n";
        $ics .= "DESCRIPTION:{$description}\r\n";
        if ($attendeeEmail) {
            $ics .= "ORGANIZER;CN={$organizerName}:mailto:{$organizerEmail}\r\n";
            $ics .= "ATTENDEE;CUTYPE=INDIVIDUAL;ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED;CN={$attendeeName}:mailto:{$attendeeEmail}\r\n";
        }
        $ics .= "STATUS:CONFIRMED\r\n";
        $ics .= "SEQUENCE:0\r\n";
        $ics .= "TRANSP:OPAQUE\r\n";
        // Rappel 30 minutes avant
        $ics .= "BEGIN:VALARM\r\n";
        $ics .= "TRIGGER:-PT30M\r\n";
        $ics .= "ACTION:DISPLAY\r\n";
        $ics .= "DESCRIPTION:Rappel: {$summary}\r\n";
        $ics .= "END:VALARM\r\n";
        $ics .= "END:VEVENT\r\n";
        $ics .= "END:VCALENDAR\r\n";

        return $ics;
    }
}

