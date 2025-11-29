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

class AdminOrderPaymentNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $user;
    public $isAdditionalCards;
    public $additionalPayment;
    public $messageContent;

    /**
     * Create a new message instance.
     * 
     * @param Order $order La commande concernée
     * @param User $user L'utilisateur qui a payé
     * @param bool $isAdditionalCards Si c'est un paiement de cartes supplémentaires
     * @param AdditionalCardPayment|null $additionalPayment Le paiement supplémentaire (si applicable)
     */
    public function __construct(Order $order, User $user, bool $isAdditionalCards = false, ?AdditionalCardPayment $additionalPayment = null)
    {
        $this->order = $order;
        $this->user = $user;
        $this->isAdditionalCards = $isAdditionalCards;
        $this->additionalPayment = $additionalPayment;
        $this->messageContent = $this->buildMessageContent();
    }

    /**
     * Construire le contenu du message selon le type de commande
     */
    private function buildMessageContent(): string
    {
        if ($this->isAdditionalCards && $this->additionalPayment) {
            // Message pour les cartes supplémentaires
            return $this->buildAdditionalCardsMessage();
        } else {
            // Message pour la commande initiale
            return $this->buildInitialOrderMessage();
        }
    }

    /**
     * Construire le message pour la commande initiale
     */
    private function buildInitialOrderMessage(): string
    {
        $orderType = $this->order->order_type;
        $userName = $this->user->name;
        $cardQuantity = $this->order->card_quantity;
        $totalPrice = number_format($this->order->total_price, 0, ',', ' ') . ' GNF';
        $orderId = $this->order->id;

        if ($orderType === 'business' || $orderType === 'entreprise') {
            // Commande entreprise
            $this->order->load(['orderEmployees.employee']);
            
            $recipients = [];
            foreach ($this->order->orderEmployees as $oe) {
                $name = $oe->employee_name;
                $cardQty = $oe->card_quantity;
                if ($oe->employee_id === $this->user->id) {
                    $recipients[] = "lui";
                } else {
                    $recipients[] = "l'employé {$name}";
                }
            }
            
            // Construire le texte des destinataires avec "et" ou "ou" selon le contexte
            if (count($recipients) === 1) {
                $recipientsText = $recipients[0];
            } else {
                $lastRecipient = array_pop($recipients);
                $recipientsText = implode(', ', $recipients) . ' et ' . $lastRecipient;
            }
            
            return "Le Business Admin {$userName} a payé {$cardQuantity} carte(s) au prix total de {$totalPrice} pour {$recipientsText} et l'ID de la commande est {$orderId}.";
        } else {
            // Commande particulière
            return "Le particulier {$userName} a payé {$cardQuantity} carte(s) au prix total de {$totalPrice} et l'ID de la commande est {$orderId}.";
        }
    }

    /**
     * Construire le message pour les cartes supplémentaires
     */
    private function buildAdditionalCardsMessage(): string
    {
        $orderType = $this->order->order_type;
        $userName = $this->user->name;
        $quantity = $this->additionalPayment->quantity;
        $totalPrice = number_format($this->additionalPayment->total_price, 0, ',', ' ') . ' GNF';
        $orderId = $this->order->id;
        $distribution = $this->additionalPayment->distribution;

        if ($orderType === 'business' || $orderType === 'entreprise') {
            // Commande entreprise
            $this->order->load(['orderEmployees.employee']);
            
            $adminQuantity = isset($distribution['admin']) ? (int) $distribution['admin'] : 0;
            $employeesDistribution = $distribution['employees'] ?? [];
            
            $recipients = [];
            
            // Ajouter le business admin s'il a reçu des cartes
            if ($adminQuantity > 0) {
                $adminOrderEmployee = $this->order->orderEmployees->where('employee_id', $this->user->id)->first();
                $adminName = $adminOrderEmployee ? $adminOrderEmployee->employee_name : $this->user->name;
                $recipients[] = "lui ({$adminQuantity} carte(s))";
            }
            
            // Ajouter les employés qui ont reçu des cartes
            foreach ($employeesDistribution as $employeeId => $employeeQuantity) {
                $employeeQuantityInt = (int) $employeeQuantity;
                if ($employeeQuantityInt > 0) {
                    $employeeOrderEmployee = $this->order->orderEmployees->where('employee_id', $employeeId)->first();
                    if ($employeeOrderEmployee) {
                        $employeeName = $employeeOrderEmployee->employee_name;
                        $recipients[] = "l'employé {$employeeName} ({$employeeQuantityInt} carte(s))";
                    }
                }
            }
            
            if (empty($recipients)) {
                return "Le Business Admin {$userName} a ajouté et payé {$quantity} carte(s) supplémentaire(s) à la commande {$orderId} au prix de {$totalPrice}.";
            }
            
            // Construire le texte des destinataires avec "et" ou "ou" selon le contexte
            if (count($recipients) === 1) {
                $recipientsText = $recipients[0];
            } else {
                $lastRecipient = array_pop($recipients);
                $recipientsText = implode(', ', $recipients) . ' et ' . $lastRecipient;
            }
            
            return "Le Business Admin {$userName} a ajouté et payé {$quantity} carte(s) supplémentaire(s) à la commande {$orderId} pour {$recipientsText} au prix de {$totalPrice}.";
        } else {
            // Commande particulière
            return "Le particulier {$userName} a ajouté et payé {$quantity} carte(s) supplémentaire(s) au prix total de {$totalPrice} à la commande {$orderId}.";
        }
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $fromAddress = config('mail.from.address', 'noreply@arccenciel.com');
        $fromName = config('mail.from.name', 'DigiCard');
        
        $subject = $this->isAdditionalCards 
            ? 'Nouveau paiement - Cartes supplémentaires - Commande #' . $this->order->order_number
            : 'Nouveau paiement - Commande #' . $this->order->order_number;
        
        return $this->from($fromAddress, $fromName)
            ->replyTo($fromAddress, $fromName)
            ->subject($subject)
            ->view('emails.admin-order-payment-notification')
            ->with([
                'order' => $this->order,
                'user' => $this->user,
                'messageContent' => $this->messageContent,
                'isAdditionalCards' => $this->isAdditionalCards,
                'additionalPayment' => $this->additionalPayment,
            ])
            ->priority(1);
    }

    /**
     * Get the message envelope.
     * Note: La méthode build() est utilisée pour définir le destinataire
     */
    public function envelope(): Envelope
    {
        $subject = $this->isAdditionalCards 
            ? 'Nouveau paiement - Cartes supplémentaires - Commande #' . $this->order->order_number
            : 'Nouveau paiement - Commande #' . $this->order->order_number;
            
        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                config('mail.from.address', 'noreply@arccenciel.com'),
                config('mail.from.name', 'DigiCard')
            ),
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-order-payment-notification',
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
