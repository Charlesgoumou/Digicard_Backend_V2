<?php

namespace App\Mail;

use App\Models\Appointment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;

/**
 * Email de notification au propriétaire lors de l'annulation d'un rendez-vous.
 */
class AppointmentCancelledForOwner extends Mailable
{
    /**
     * Le rendez-vous.
     */
    public Appointment $appointment;

    /**
     * Create a new message instance.
     */
    public function __construct(Appointment $appointment)
    {
        $this->appointment = $appointment;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $fromAddress = config('mail.from.address', 'noreply@arccenciel.com');
        $fromName = config('mail.from.name', 'DigiCard');
        
        return new Envelope(
            from: new Address($fromAddress, $fromName),
            subject: '❌ Rendez-vous annulé - ' . $this->appointment->visitor_name,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.appointments.cancelled-for-owner',
            with: [
                'appointment' => $this->appointment,
                'ownerName' => $this->appointment->user->name,
                'visitorName' => $this->appointment->visitor_name,
                'visitorEmail' => $this->appointment->visitor_email,
                'startTime' => $this->appointment->start_time,
                'endTime' => $this->appointment->end_time,
            ],
        );
    }
}
