<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MarketplaceTransactionNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $message,
        public int $offerId,
        public int $purchaseId,
        public ?int $otherUserId = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'marketplace_transaction',
            'message' => $this->message,
            'offer_id' => $this->offerId,
            'purchase_id' => $this->purchaseId,
            'other_user_id' => $this->otherUserId,
            'url' => '/marketplace',
        ];
    }
}

