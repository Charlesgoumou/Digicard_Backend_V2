<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouveau Rendez-vous Confirmé</title>
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
            background: linear-gradient(135deg, #0ea5e9 0%, #6366f1 100%);
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
        .appointment-card {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 1px solid #bae6fd;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
        }
        .appointment-card h2 {
            color: #0369a1;
            margin: 0 0 15px;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .info-row {
            display: flex;
            margin-bottom: 12px;
            align-items: flex-start;
        }
        .info-label {
            font-weight: 600;
            color: #64748b;
            min-width: 100px;
            font-size: 14px;
        }
        .info-value {
            color: #1e293b;
            font-size: 14px;
        }
        .datetime-highlight {
            background: #0ea5e9;
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
        .visitor-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 15px;
            margin: 15px 0;
        }
        .visitor-card h3 {
            color: #475569;
            margin: 0 0 10px;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .visitor-name {
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 10px;
        }
        .visitor-contact {
            font-size: 14px;
            color: #64748b;
        }
        .visitor-contact a {
            color: #0ea5e9;
            text-decoration: none;
        }
        .message-box {
            background: #fefce8;
            border: 1px solid #fde047;
            border-radius: 10px;
            padding: 15px;
            margin: 15px 0;
        }
        .message-box h3 {
            color: #854d0e;
            margin: 0 0 8px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .message-box p {
            color: #713f12;
            margin: 0;
            font-style: italic;
        }
        .calendar-notice {
            background: #ecfdf5;
            border: 1px solid #6ee7b7;
            border-radius: 10px;
            padding: 15px;
            margin: 20px 0;
            text-align: center;
        }
        .calendar-notice p {
            color: #065f46;
            margin: 0;
            font-size: 14px;
        }
        .calendar-notice strong {
            color: #047857;
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
            color: #0ea5e9;
            text-decoration: none;
        }
        .logo {
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="icon-calendar">📅</div>
            <h1>Nouveau Rendez-vous Confirmé !</h1>
            <p>Un visiteur a réservé un créneau avec vous</p>
        </div>

        <div class="content">
            <p class="greeting">Bonjour <strong>{{ $ownerName }}</strong>,</p>
            
            <p>Vous avez un nouveau rendez-vous confirmé via votre profil DigiCard.</p>

            <!-- Date et Heure en évidence -->
            <div class="datetime-highlight">
                <div class="date">{{ $startTime ? $startTime->locale('fr')->isoFormat('dddd D MMMM YYYY') : '' }}</div>
                <div class="time">{{ $startTime ? $startTime->format('H:i') : '' }} - {{ $endTime ? $endTime->format('H:i') : '' }}</div>
                <div class="duration">Durée : {{ $duration ?? 0 }} minutes</div>
            </div>

            <!-- Informations du visiteur -->
            <div class="visitor-card">
                <h3>👤 Visiteur</h3>
                <div class="visitor-name">{{ $visitorName }}</div>
                <div class="visitor-contact">
                    📧 <a href="mailto:{{ $visitorEmail }}">{{ $visitorEmail }}</a>
                    @if($visitorPhone)
                        <br>📱 <a href="tel:{{ $visitorPhone }}">{{ $visitorPhone }}</a>
                    @endif
                </div>
            </div>

            <!-- Message du visiteur (si présent) -->
            @if($visitorMessage)
            <div class="message-box">
                <h3>💬 Message du visiteur</h3>
                <p>"{{ $visitorMessage }}"</p>
            </div>
            @endif

            <!-- Notice calendrier -->
            <div class="calendar-notice">
                <p>
                    <strong>📅 Invitation calendrier jointe</strong><br>
                    Cet email contient une invitation calendrier. 
                    Cliquez sur <strong>Accepter</strong> pour l'ajouter automatiquement à votre agenda.
                </p>
            </div>

            <p style="color: #64748b; font-size: 14px; margin-top: 20px;">
                Si vous devez annuler ou modifier ce rendez-vous, veuillez contacter le visiteur directement à l'adresse 
                <a href="mailto:{{ $visitorEmail }}" style="color: #0ea5e9;">{{ $visitorEmail }}</a>.
            </p>
        </div>

        <div class="footer">
            <div class="logo">
                <strong style="color: #0ea5e9; font-size: 16px;">DigiCard</strong>
            </div>
            <p>
                Cet email a été envoyé automatiquement par DigiCard.<br>
                <a href="https://digicard.arccenciel.com">digicard.arccenciel.com</a>
            </p>
        </div>
    </div>
</body>
</html>

