<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rappel de Rendez-vous</title>
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
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
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
            background: #fef3c7;
            border: 2px solid #f59e0b;
            color: #92400e;
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
        .datetime-highlight .minutes {
            font-size: 16px;
            font-weight: 600;
            margin-top: 10px;
        }
        .contact-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 15px;
            margin: 15px 0;
        }
        .contact-card h3 {
            color: #475569;
            margin: 0 0 10px;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .contact-name {
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 10px;
        }
        .contact-info {
            font-size: 14px;
            color: #64748b;
        }
        .contact-info a {
            color: #0ea5e9;
            text-decoration: none;
        }
        .reminder-notice {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 10px;
            padding: 15px;
            margin: 20px 0;
            text-align: center;
        }
        .reminder-notice p {
            color: #92400e;
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
            color: #f59e0b;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="icon-calendar">⏰</div>
            <h1>Rappel de Rendez-vous</h1>
            <p>Votre rendez-vous approche</p>
        </div>

        <div class="content">
            <p class="greeting">Bonjour <strong>{{ $recipientType === 'owner' ? $ownerName : $visitorName }}</strong>,</p>
            
            <p>Ceci est un rappel automatique pour votre rendez-vous.</p>

            <!-- Date et Heure -->
            <div class="datetime-highlight">
                <div class="date">{{ $startTime ? $startTime->locale('fr')->isoFormat('dddd D MMMM YYYY') : '' }}</div>
                <div class="time">{{ $startTime ? $startTime->format('H:i') : '' }} - {{ $endTime ? $endTime->format('H:i') : '' }}</div>
                <div class="minutes">⏰ Dans {{ $minutesBefore }} minutes</div>
            </div>

            <!-- Informations du contact -->
            <div class="contact-card">
                <h3>{{ $recipientType === 'owner' ? '👤 Visiteur' : '👤 Contact' }}</h3>
                <div class="contact-name">{{ $recipientType === 'owner' ? $visitorName : $ownerName }}</div>
                <div class="contact-info">
                    @if($recipientType === 'owner')
                        📧 <a href="mailto:{{ $visitorEmail }}">{{ $visitorEmail }}</a>
                        @if($visitorPhone)
                            <br>📱 <a href="tel:{{ $visitorPhone }}">{{ $visitorPhone }}</a>
                        @endif
                    @else
                        📧 Contactez directement {{ $ownerName }}
                    @endif
                </div>
            </div>

            <!-- Notice de rappel -->
            <div class="reminder-notice">
                <p>
                    <strong>⏰ Rappel automatique</strong><br>
                    Votre rendez-vous est prévu dans {{ $minutesBefore }} minutes. Pensez à vous préparer !
                </p>
            </div>
        </div>

        <div class="footer">
            <div class="logo">
                <strong style="color: #f59e0b; font-size: 16px;">DigiCard</strong>
            </div>
            <p>
                Cet email a été envoyé automatiquement par DigiCard.<br>
                <a href="https://digicard.arccenciel.com">digicard.arccenciel.com</a>
            </p>
        </div>
    </div>
</body>
</html>
