<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification de changement de mot de passe</title>
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
            max-width: 600px;
            margin: 20px auto;
            background-color: #ffffff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .header {
            background: linear-gradient(135deg, #0ea5e9 0%, #3b82f6 100%);
            color: #ffffff;
            padding: 40px 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: bold;
        }
        .content {
            padding: 40px 30px;
        }
        .greeting {
            font-size: 18px;
            color: #1f2937;
            margin-bottom: 20px;
        }
        .message-box {
            background-color: #f1f5f9;
            border-left: 4px solid #0ea5e9;
            padding: 20px;
            margin: 25px 0;
            border-radius: 5px;
        }
        .info-box {
            background-color: #ecfdf5;
            border-left: 4px solid #10b981;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            font-size: 14px;
            color: #065f46;
        }
        .warning {
            background-color: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            font-size: 14px;
            color: #92400e;
        }
        .timestamp {
            background-color: #f8fafc;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            font-size: 14px;
            color: #64748b;
            text-align: center;
        }
        .footer {
            background-color: #1e293b;
            color: #cbd5e1;
            text-align: center;
            padding: 30px 20px;
            font-size: 14px;
        }
        .footer a {
            color: #38bdf8;
            text-decoration: none;
        }
        @media only screen and (max-width: 600px) {
            .email-container {
                margin: 0;
                border-radius: 0;
            }
            .content, .header {
                padding: 25px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- En-tête -->
        <div class="header">
            <h1>🔐 Mot de passe modifié</h1>
        </div>

        <!-- Contenu principal -->
        <div class="content">
            <div class="greeting">
                Bonjour <strong>{{ $user->name }}</strong>,
            </div>

            <p>
                Nous vous informons que le mot de passe de votre compte Arcc En Ciel a été modifié avec succès.
            </p>

            <div class="info-box">
                <p style="margin: 0;">
                    <strong>✅ Changement effectué</strong><br>
                    Votre mot de passe a été mis à jour depuis votre compte.
                </p>
            </div>

            <div class="timestamp">
                <strong>Date et heure :</strong><br>
                {{ $timestamp->format('d/m/Y à H:i') }}
            </div>

            <div class="warning">
                <strong>⚠️ Sécurité :</strong><br>
                Si vous n'avez pas effectué cette modification, veuillez immédiatement :
                <ul style="margin: 10px 0 0 20px; padding: 0;">
                    <li>Changer votre mot de passe en vous connectant à votre compte</li>
                    <li>Vérifier les activités récentes sur votre compte</li>
                    <li>Nous contacter si vous suspectez une activité suspecte</li>
                </ul>
            </div>

            <p style="margin-top: 30px; font-size: 14px; color: #64748b;">
                Pour toute question ou assistance, n'hésitez pas à nous contacter.
            </p>
        </div>

        <!-- Pied de page -->
        <div class="footer">
            <p style="margin: 0 0 10px;"><strong>ARCC EN CIEL SARLU</strong></p>
            <p style="margin: 0 0 5px;">Lambanyi, Commune de Lambanyi, Conakry, République de Guinée</p>
            <p style="margin: 0 0 5px;">📞 +224 613615732 / +224 661345345</p>
            <p style="margin: 0 0 15px;">✉️ <a href="mailto:contact@arccenciel.com">contact@arccenciel.com</a></p>
            <p style="font-size: 12px; color: #94a3b8; margin: 0;">
                © {{ date('Y') }} ARCC EN CIEL SARLU. Tous droits réservés.
            </p>
        </div>
    </div>
</body>
</html>




