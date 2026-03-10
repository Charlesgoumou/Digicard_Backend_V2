<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SharedContact extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'order_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'company',
        'note',
        'vcard_path',
        'vcard_generated_at',
        'is_downloaded',
        'downloaded_at',
    ];

    protected $casts = [
        'is_downloaded' => 'boolean',
        'vcard_generated_at' => 'datetime',
        'downloaded_at' => 'datetime',
    ];

    /**
     * Relation avec l'utilisateur propriétaire de la carte
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relation avec la commande (optionnel)
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Obtenir le nom complet du contact
     */
    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    /**
     * Générer le contenu vCard
     */
    public function generateVCardContent(): string
    {
        $vcard = "BEGIN:VCARD\r\n";
        $vcard .= "VERSION:3.0\r\n";
        $vcard .= "N:{$this->last_name};{$this->first_name};;;\r\n";
        $vcard .= "FN:{$this->full_name}\r\n";
        
        if ($this->email) {
            $vcard .= "EMAIL;TYPE=INTERNET:{$this->email}\r\n";
        }
        
        if ($this->phone) {
            $vcard .= "TEL;TYPE=CELL:{$this->phone}\r\n";
        }
        
        if ($this->company) {
            $vcard .= "ORG:{$this->company}\r\n";
        }
        
        if ($this->note) {
            // Échapper les caractères spéciaux dans la note
            $note = str_replace(["\r\n", "\n", "\r"], "\\n", $this->note);
            $vcard .= "NOTE:{$note}\r\n";
        }
        
        $vcard .= "REV:" . now()->format('Ymd\THis\Z') . "\r\n";
        $vcard .= "END:VCARD\r\n";
        
        return $vcard;
    }

    /**
     * Scope pour les contacts non téléchargés
     */
    public function scopeNotDownloaded($query)
    {
        return $query->where('is_downloaded', false);
    }

    /**
     * Scope pour les contacts téléchargés depuis plus de 24h
     */
    public function scopeExpiredVCards($query)
    {
        return $query->where('is_downloaded', true)
                     ->whereNotNull('downloaded_at')
                     ->where('downloaded_at', '<', now()->subHours(24));
    }
}

