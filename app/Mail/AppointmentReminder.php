<?php

namespace App\Mail;

use App\Models\Appointment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;

/**
 * Email de rappel pour un rendez-vous (30 min ou 10 min avant).
 */
class AppointmentReminder extends Mailable
{
    /**
     * Le rendez-vous.
     */
    public Appointment $appointment;

    /**
     * Minutes avant le rendez-vous (30 ou 10).
     */
    public int $minutesBefore;

    /**
     * Type de destinataire ('owner' ou 'visitor').
     */
    public string $recipientType;

    /**
     * Create a new message instance.
     */
    public function __construct(Appointment $appointment, int $minutesBefore, string $recipientType = 'owner')
    {
        $this->appointment = $appointment;
        $this->minutesBefore = $minutesBefore;
        $this->recipientType = $recipientType;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $fromAddress = config('mail.from.address', 'noreply@arccenciel.com');
        $fromName = config('mail.from.name', 'DigiCard');
        
        $subject = $this->minutesBefore === 30 
            ? '⏰ Rappel : Rendez-vous dans 30 minutes'
            : '⏰ Rappel : Rendez-vous dans 10 minutes';
        
        return new Envelope(
            from: new Address($fromAddress, $fromName),
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.appointments.reminder',
            with: [
                'appointment' => $this->appointment,
                'ownerName' => $this->appointment->user->name,
                'visitorName' => $this->appointment->visitor_name,
                'visitorEmail' => $this->appointment->visitor_email,
                'visitorPhone' => $this->appointment->visitor_phone,
                'startTime' => $this->appointment->start_time,
                'endTime' => $this->appointment->end_time,
                'minutesBefore' => $this->minutesBefore,
                'recipientType' => $this->recipientType,
            ],
        );
    }
}
