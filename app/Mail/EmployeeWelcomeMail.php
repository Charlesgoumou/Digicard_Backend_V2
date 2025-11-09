<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Address;

class EmployeeWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $temporaryPassword;
    public string $employeeEmail;
    public string $companyName;
    public string $loginUrl;

    /**
     * Create a new message instance.
     *
     * @param string $temporaryPassword The generated temporary password.
     * @param string $employeeEmail The employee's email address.
     * @param ?string $companyName The name of the company (peut être null).
     * @param string $loginUrl The URL for the application's login page.
     * @return void
     */
    public function __construct(string $temporaryPassword, string $employeeEmail, ?string $companyName, string $loginUrl)
    {
        $this->temporaryPassword = $temporaryPassword;
        $this->employeeEmail = $employeeEmail;
        $this->companyName = $companyName ?: 'votre entreprise'; // Définit un fallback si null
        $this->loginUrl = $loginUrl;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Bienvenue chez ' . $this->companyName . ' - Votre Compte Carte Digitale Arcc En Ciel',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.employee_welcome',
            with: [
                'password' => $this->temporaryPassword,
                'employeeEmail' => $this->employeeEmail,
                'loginUrl' => $this->loginUrl,
                'companyName' => $this->companyName,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
