<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\User;
use App\Models\AdditionalCardPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdditionalCardsAdded extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $user;
    public $additionalPayment;

    /**
     * Create a new message instance.
     */
    public function __construct(Order $order, User $user, AdditionalCardPayment $additionalPayment)
    {
        $this->order = $order;
        $this->user = $user;
        $this->additionalPayment = $additionalPayment;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $fromAddress = config('mail.from.address', 'contact@arccenciel.com');
        $fromName = config('mail.from.name', 'Arcc En Ciel');
        
        return $this->from($fromAddress, $fromName)
            ->replyTo($fromAddress, $fromName)
            ->subject('Cartes supplémentaires ajoutées - Commande #' . $this->order->order_number . ' - Arcc En Ciel')
            ->view('emails.additional-cards-added')
            ->with([
                'order' => $this->order,
                'user' => $this->user,
                'additionalPayment' => $this->additionalPayment,
            ])
            ->priority(1); // Priorité haute pour les emails transactionnels
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                config('mail.from.address', 'contact@arccenciel.com'),
                config('mail.from.name', 'Arcc En Ciel')
            ),
            subject: 'Cartes supplémentaires ajoutées - Commande #' . $this->order->order_number . ' - Arcc En Ciel',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.additional-cards-added',
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

