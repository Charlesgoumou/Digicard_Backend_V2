<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderValidated extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $user;

    /**
     * Create a new message instance.
     */
    public $basePrice;
    public $extraPrice;
    public $annualPrice;

    public function __construct(Order $order, User $user)
    {
        $this->order = $order;
        $this->user = $user;
        // Récupérer les prix depuis les settings du super admin
        $this->basePrice = \App\Helpers\PricingHelper::getBasePrice();
        $this->extraPrice = \App\Helpers\PricingHelper::getExtraPrice();
        $this->annualPrice = \App\Helpers\PricingHelper::getAnnualSubscription();
    }

    /**
     * Build the message.
     * Utilise la même méthode que VerificationCodeMail qui fonctionne
     * Amélioré avec des en-têtes anti-spam pour une meilleure délivrabilité
     */
    public function build()
    {
        $fromAddress = config('mail.from.address', 'noreply@arccenciel.com');
        $fromName = config('mail.from.name', 'DigiCard');
        
        return $this->from($fromAddress, $fromName)
            ->replyTo($fromAddress, $fromName)
            ->subject('Confirmation de votre commande #' . $this->order->order_number . ' - DigiCard')
            ->view('emails.order-validated')
            ->with([
                'order' => $this->order,
                'user' => $this->user,
                'basePrice' => $this->basePrice,
                'extraPrice' => $this->extraPrice,
                'annualPrice' => $this->annualPrice,
            ])
            ->priority(1); // Priorité haute pour les emails transactionnels
    }

    /**
     * Get the message envelope (méthode moderne, gardée pour compatibilité)
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                config('mail.from.address', 'noreply@arccenciel.com'),
                config('mail.from.name', 'DigiCard')
            ),
            subject: 'Confirmation de votre commande - DigiCard',
        );
    }

    /**
     * Get the message content definition (méthode moderne, gardée pour compatibilité)
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.order-validated',
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

