<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réinitialisation de votre mot de passe</title>
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
        .button-container {
            text-align: center;
            margin: 30px 0;
        }
        .button {
            display: inline-block;
            background-color: #0ea5e9;
            color: #ffffff !important;
            padding: 15px 40px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            font-size: 16px;
        }
        .button:hover {
            background-color: #0284c7;
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
            <h1>🔐 Réinitialisation de mot de passe</h1>
        </div>

        <!-- Contenu principal -->
        <div class="content">
            <div class="greeting">
                Bonjour <strong>{{ $user->name }}</strong>,
            </div>

            <p>
                Vous avez demandé la réinitialisation de votre mot de passe pour votre compte Arcc En Ciel.
            </p>

            <div class="message-box">
                <p style="margin: 0; font-size: 14px; color: #475569;">
                    Pour réinitialiser votre mot de passe, cliquez sur le bouton ci-dessous. Ce lien est valide pendant <strong>24 heures</strong>.
                </p>
            </div>

            <div class="button-container">
                <a href="{{ $frontendUrl }}/reset-password?email={{ urlencode($user->email) }}&token={{ $token }}" class="button">
                    Réinitialiser mon mot de passe
                </a>
            </div>

            <div class="warning">
                <strong>⚠️ Attention :</strong> Si vous n'avez pas demandé cette réinitialisation, ignorez simplement cet email. Votre mot de passe actuel restera inchangé.
            </div>

            <p style="margin-top: 30px; font-size: 14px; color: #64748b;">
                <strong>Problème avec le bouton ?</strong><br>
                Copiez et collez ce lien dans votre navigateur :<br>
                <span style="color: #0ea5e9; word-break: break-all;">
                    {{ $frontendUrl }}/reset-password?email={{ urlencode($user->email) }}&token={{ $token }}
                </span>
            </p>

            <p style="margin-top: 30px; font-size: 14px; color: #64748b;">
                Pour toute question, n'hésitez pas à nous contacter.
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

