<?php

namespace App\Notifications;

use App\Models\MarketplaceOffer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MarketplaceMatchNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $offer;
    protected $matchScore;

    /**
     * Create a new notification instance.
     */
    public function __construct(MarketplaceOffer $offer, float $matchScore)
    {
        $this->offer = $offer;
        $this->matchScore = $matchScore;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('🎯 Nouvelle offre correspondant à votre profil !')
            ->greeting('Bonjour ' . $notifiable->name . ' !')
            ->line('Nous avons trouvé une offre qui correspond parfaitement à votre profil.')
            ->line('**Offre :** ' . $this->offer->title)
            ->line('**Score de correspondance :** ' . round($this->matchScore, 1) . '/100')
            ->action('Voir l\'offre', url('/marketplace/offers/' . $this->offer->id))
            ->line('Cette offre pourrait vous intéresser en fonction de votre profil professionnel.')
            ->salutation('Cordialement, L\'équipe DigiCard');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'marketplace_match',
            'offer_id' => $this->offer->id,
            'offer_title' => $this->offer->title,
            'match_score' => $this->matchScore,
            'message' => 'Une nouvelle offre correspond à votre profil (Score: ' . round($this->matchScore, 1) . '/100)',
            'url' => '/marketplace/offers/' . $this->offer->id,
        ];
    }
}
