<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification de paiement - Commande #{{ $order->order_number }}</title>
    <style>
        body {
            font-family: 'Arial', 'Helvetica', sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .email-container {
            max-width: 700px;
            margin: 20px auto;
            background-color: #ffffff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .header {
            background: linear-gradient(135deg, #0ea5e9 0%, #3b82f6 100%);
            color: #ffffff;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: bold;
        }
        .content {
            padding: 40px 30px;
        }
        .message-box {
            background-color: #f8f9fa;
            border-left: 4px solid #0ea5e9;
            padding: 20px;
            margin: 20px 0;
            border-radius: 5px;
        }
        .message-box p {
            margin: 0;
            font-size: 16px;
            color: #1f2937;
            line-height: 1.8;
        }
        .details {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
        .details-item {
            margin: 10px 0;
            font-size: 14px;
            color: #6b7280;
        }
        .details-item strong {
            color: #1f2937;
        }
        .footer {
            background-color: #f8f9fa;
            padding: 20px 30px;
            text-align: center;
            font-size: 12px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>Notification de Paiement</h1>
            <p>Commande #{{ $order->order_number }}</p>
        </div>
        
        <div class="content">
            <div class="message-box">
                <p>{{ $messageContent }}</p>
            </div>
            
            <div class="details">
                <div class="details-item">
                    <strong>Type de commande :</strong> 
                    @if($order->order_type === 'business' || $order->order_type === 'entreprise')
                        Entreprise
                    @else
                        Particulier
                    @endif
                </div>
                <div class="details-item">
                    <strong>Client :</strong> {{ $user->name }} ({{ $user->email }})
                </div>
                <div class="details-item">
                    <strong>ID de la commande :</strong> {{ $order->id }}
                </div>
                <div class="details-item">
                    <strong>Numéro de commande :</strong> {{ $order->order_number }}
                </div>
                @if($isAdditionalCards && $additionalPayment)
                    <div class="details-item">
                        <strong>Nombre de cartes supplémentaires :</strong> {{ $additionalPayment->quantity }}
                    </div>
                    <div class="details-item">
                        <strong>Prix unitaire :</strong> {{ number_format($additionalPayment->unit_price, 0, ',', ' ') }} GNF
                    </div>
                    <div class="details-item">
                        <strong>Montant total payé :</strong> {{ number_format($additionalPayment->total_price, 0, ',', ' ') }} GNF
                    </div>
                @else
                    <div class="details-item">
                        <strong>Nombre de cartes :</strong> {{ $order->card_quantity }}
                    </div>
                    <div class="details-item">
                        <strong>Montant total payé :</strong> {{ number_format($order->total_price, 0, ',', ' ') }} GNF
                    </div>
                @endif
                <div class="details-item">
                    <strong>Date de paiement :</strong> {{ now()->format('d/m/Y à H:i') }}
                </div>
            </div>
        </div>
        
        <div class="footer">
            <p>Cet email a été envoyé automatiquement par le système Digicard.</p>
            <p>&copy; {{ date('Y') }} Arcc En Ciel - Tous droits réservés</p>
        </div>
    </div>
</body>
</html>

