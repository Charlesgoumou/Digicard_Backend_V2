<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouveau message de contact</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 20px auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
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
        }
        .content {
            padding: 30px;
        }
        .info-row {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e5e7eb;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .label {
            font-weight: bold;
            color: #0ea5e9;
            margin-bottom: 5px;
        }
        .value {
            color: #333;
        }
        .message-content {
            background-color: #f9fafb;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #0ea5e9;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .footer {
            background-color: #1e293b;
            color: #94a3b8;
            text-align: center;
            padding: 20px;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📧 Nouveau message de contact</h1>
        </div>

        <div class="content">
            <div class="info-row">
                <div class="label">Nom de l'expéditeur :</div>
                <div class="value">{{ $senderName }}</div>
            </div>

            <div class="info-row">
                <div class="label">Email de l'expéditeur :</div>
                <div class="value"><a href="mailto:{{ $senderEmail }}">{{ $senderEmail }}</a></div>
            </div>

            <div class="info-row">
                <div class="label">Objet :</div>
                <div class="value">{{ $subject }}</div>
            </div>

            <div class="info-row">
                <div class="label">Message :</div>
                <div class="message-content">{{ $messageContent }}</div>
            </div>
        </div>

        <div class="footer">
            <p>&copy; {{ date('Y') }} ArccEnCiel. Tous droits réservés.</p>
            <p>Cet email a été envoyé depuis le formulaire de contact du site ArccEnCiel.</p>
        </div>
    </div>
</body>
</html>

