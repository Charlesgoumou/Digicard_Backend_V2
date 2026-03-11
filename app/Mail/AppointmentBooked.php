<?php

namespace App\Mail;

use App\Models\Appointment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Address;
use Carbon\Carbon;

/**
 * Email de notification de rendez-vous confirmé.
 * 
 * IMPORTANT: Ce Mailable n'implémente PAS ShouldQueue pour garantir l'envoi synchrone immédiat.
 * L'email est envoyé directement lors de la confirmation du rendez-vous.
 */
class AppointmentBooked extends Mailable
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
     * 
     * Utilise l'email configuré dans MAIL_FROM_ADDRESS (le même que pour les codes 2FA et les commandes validées).
     */
    public function envelope(): Envelope
    {
        $fromAddress = config('mail.from.address', 'noreply@arccenciel.com');
        $fromName = config('mail.from.name', 'DigiCard');
        
        return new Envelope(
            from: new Address($fromAddress, $fromName),
            subject: '📅 Nouveau rendez-vous confirmé - ' . $this->appointment->visitor_name,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.appointments.booked',
            with: [
                'appointment' => $this->appointment,
                'ownerName' => $this->appointment->user->name,
                'visitorName' => $this->appointment->visitor_name,
                'visitorEmail' => $this->appointment->visitor_email,
                'visitorPhone' => $this->appointment->visitor_phone,
                'visitorMessage' => $this->appointment->message, // Renommé pour éviter le conflit avec $message global
                'startTime' => $this->appointment->start_time,
                'endTime' => $this->appointment->end_time,
                'duration' => $this->appointment->start_time->diffInMinutes($this->appointment->end_time),
            ],
        );
    }

    /**
     * Get the attachments for the message.
     * 
     * IMPORTANT: Le MIME type 'text/calendar; charset=UTF-8; method=REQUEST'
     * et le METHOD:REQUEST dans le contenu ICS sont CRITIQUES pour que
     * l'email affiche les boutons Accepter/Refuser dans Gmail/Outlook.
     */
    public function attachments(): array
    {
        $icsContent = $this->generateIcsContent();

        return [
            Attachment::fromData(fn() => $icsContent, 'invite.ics')
                ->withMime('text/calendar; charset=UTF-8; method=REQUEST'),
        ];
    }

    /**
     * Génère le contenu ICS (iCalendar) pour l'invitation.
     * 
     * Le format iCalendar est le standard pour les invitations calendrier.
     * METHOD:REQUEST indique que c'est une demande de rendez-vous (pas juste un événement).
     */
    private function generateIcsContent(): string
    {
        $appointment = $this->appointment;
        $owner = $appointment->user;
        
        // Formater les dates au format iCalendar (UTC)
        $dtStart = $appointment->start_time->setTimezone('UTC')->format('Ymd\THis\Z');
        $dtEnd = $appointment->end_time->setTimezone('UTC')->format('Ymd\THis\Z');
        $dtStamp = Carbon::now()->setTimezone('UTC')->format('Ymd\THis\Z');
        $created = $appointment->created_at->setTimezone('UTC')->format('Ymd\THis\Z');
        
        // Identifiant unique pour l'événement
        $uid = 'digicard-appointment-' . $appointment->id . '@arccenciel.com';
        
        // Description de l'événement
        $description = "Rendez-vous avec {$appointment->visitor_name}";
        if ($appointment->message) {
            $description .= "\\n\\nMotif : {$appointment->message}";
        }
        $description .= "\\n\\nContact visiteur :";
        $description .= "\\nEmail : {$appointment->visitor_email}";
        if ($appointment->visitor_phone) {
            $description .= "\\nTéléphone : {$appointment->visitor_phone}";
        }
        $description .= "\\n\\nRéservé via DigiCard";
        
        // Titre de l'événement
        $summary = "RDV: {$appointment->visitor_name}";
        
        // Email organisateur (DigiCard System)
        $organizerEmail = config('mail.from.address', 'noreply@arccenciel.com');
        $organizerName = 'DigiCard System';
        
        // Email du propriétaire (attendee)
        $attendeeEmail = $owner->email;
        $attendeeName = $owner->name;

        // Construction du fichier ICS
        // IMPORTANT: METHOD:REQUEST est essentiel pour déclencher l'UI d'invitation
        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//DigiCard//Appointment System//FR\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:REQUEST\r\n"; // CRITIQUE: Déclenche les boutons Accepter/Refuser
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:{$uid}\r\n";
        $ics .= "DTSTAMP:{$dtStamp}\r\n";
        $ics .= "DTSTART:{$dtStart}\r\n";
        $ics .= "DTEND:{$dtEnd}\r\n";
        $ics .= "CREATED:{$created}\r\n";
        $ics .= "SUMMARY:{$summary}\r\n";
        $ics .= "DESCRIPTION:{$description}\r\n";
        $ics .= "ORGANIZER;CN={$organizerName}:mailto:{$organizerEmail}\r\n";
        $ics .= "ATTENDEE;CUTYPE=INDIVIDUAL;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE;CN={$attendeeName}:mailto:{$attendeeEmail}\r\n";
        $ics .= "STATUS:CONFIRMED\r\n";
        $ics .= "SEQUENCE:0\r\n";
        $ics .= "TRANSP:OPAQUE\r\n";
        // Rappel 30 minutes avant
        $ics .= "BEGIN:VALARM\r\n";
        $ics .= "TRIGGER:-PT30M\r\n";
        $ics .= "ACTION:DISPLAY\r\n";
        $ics .= "DESCRIPTION:Rappel: {$summary}\r\n";
        $ics .= "END:VALARM\r\n";
        // Rappel 10 minutes avant
        $ics .= "BEGIN:VALARM\r\n";
        $ics .= "TRIGGER:-PT10M\r\n";
        $ics .= "ACTION:DISPLAY\r\n";
        $ics .= "DESCRIPTION:Rappel: {$summary}\r\n";
        $ics .= "END:VALARM\r\n";
        $ics .= "END:VEVENT\r\n";
        $ics .= "END:VCALENDAR\r\n";

        return $ics;
    }
}

