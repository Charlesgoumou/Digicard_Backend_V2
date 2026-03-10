<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Votre Rendez-vous Confirmé</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 20px auto;
            background: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .header {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 30px 20px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
        }
        .header p {
            margin: 10px 0 0;
            opacity: 0.9;
            font-size: 14px;
        }
        .icon-calendar {
            font-size: 48px;
            margin-bottom: 10px;
        }
        .content {
            padding: 30px;
        }
        .greeting {
            font-size: 18px;
            margin-bottom: 20px;
        }
        .datetime-highlight {
            background: #10b981;
            color: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin: 20px 0;
            text-align: center;
        }
        .datetime-highlight .date {
            font-size: 18px;
            font-weight: 700;
        }
        .datetime-highlight .time {
            font-size: 24px;
            font-weight: 800;
            margin: 5px 0;
        }
        .datetime-highlight .duration {
            font-size: 14px;
            opacity: 0.9;
        }
        .owner-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 15px;
            margin: 15px 0;
        }
        .owner-card h3 {
            color: #475569;
            margin: 0 0 10px;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .owner-name {
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 10px;
        }
        .success-notice {
            background: #ecfdf5;
            border: 1px solid #6ee7b7;
            border-radius: 10px;
            padding: 15px;
            margin: 20px 0;
            text-align: center;
        }
        .success-notice p {
            color: #065f46;
            margin: 0;
            font-size: 14px;
        }
        .footer {
            background: #f8fafc;
            padding: 20px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
        }
        .footer p {
            margin: 0;
            color: #94a3b8;
            font-size: 12px;
        }
        .footer a {
            color: #10b981;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="icon-calendar">✅</div>
            <h1>Votre Rendez-vous est Confirmé !</h1>
            <p>Votre demande de rendez-vous a été acceptée</p>
        </div>

        <div class="content">
            <p class="greeting">Bonjour <strong>{{ $visitorName }}</strong>,</p>
            
            <p>Votre rendez-vous a été confirmé avec succès via DigiCard.</p>

            <!-- Date et Heure en évidence -->
            <div class="datetime-highlight">
                <div class="date">{{ $startTime ? $startTime->locale('fr')->isoFormat('dddd D MMMM YYYY') : '' }}</div>
                <div class="time">{{ $startTime ? $startTime->format('H:i') : '' }} - {{ $endTime ? $endTime->format('H:i') : '' }}</div>
                <div class="duration">Durée : {{ $duration ?? 0 }} minutes</div>
            </div>

            <!-- Informations du propriétaire -->
            <div class="owner-card">
                <h3>👤 Votre contact</h3>
                <div class="owner-name">{{ $ownerName }}</div>
            </div>

            <!-- Notice de succès -->
            <div class="success-notice">
                <p>
                    <strong>✅ Rendez-vous confirmé</strong><br>
                    Vous recevrez des rappels automatiques à 30 minutes et 10 minutes avant votre rendez-vous.
                </p>
            </div>

            <!-- Bouton d'annulation -->
            <div style="text-align: center; margin: 30px 0;">
                <a href="{{ $cancelUrl }}" 
                   style="display: inline-block; background-color: #ef4444; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px;">
                    ❌ Annuler ce rendez-vous
                </a>
            </div>

            <p style="color: #64748b; font-size: 14px; margin-top: 20px;">
                Si vous devez modifier ce rendez-vous, veuillez contacter directement 
                <strong>{{ $ownerName }}</strong>.
            </p>
        </div>

        <div class="footer">
            <div class="logo">
                <strong style="color: #10b981; font-size: 16px;">DigiCard</strong>
            </div>
            <p>
                Cet email a été envoyé automatiquement par DigiCard.<br>
                <a href="https://digicard.arccenciel.com">digicard.arccenciel.com</a>
            </p>
        </div>
    </div>
</body>
</html>
