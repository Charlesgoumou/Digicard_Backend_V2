<?php

namespace App\Mail;

use Illuminate\Bus\Queueable; // <-- IMPORT ADDED
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels; // <-- IMPORT ADDED

class VerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public $code;

    /**
     * Create a new message instance.
     * @param string $code The verification code.
     * @return void
     */
    public function __construct($code)
    {
        $this->code = $code;
    }

    /**
     * Get the message envelope.
     * Use this method for newer Laravel versions (optional but recommended)
     */
    // public function envelope(): Envelope
    // {
    //     return new Envelope(
    //         subject: 'Votre Code de Vérification Arcc En Ciel',
    //     );
    // }

    /**
     * Get the message content definition.
     * Use this method for newer Laravel versions (optional but recommended)
     */
    // public function content(): Content
    // {
    //     return new Content(
    //         view: 'emails.verification_code',
    //     );
    // }

    /**
     * Build the message.
     * Kept for compatibility, still works.
     * @return $this
     */
    public function build()
    {
        return $this->subject('Votre Code de Vérification Arcc En Ciel')
                    ->view('emails.verification_code')
                    ->with(['code' => $this->code]); // Ensure code is passed to the view
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
