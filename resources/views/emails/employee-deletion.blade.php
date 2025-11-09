<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification de Suppression de Compte</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f8fafc; }
        .container { max-width: 600px; margin: 20px auto; padding: 30px; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .header { background-color: #dc2626; color: #ffffff; padding: 20px; border-radius: 6px; margin-bottom: 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 20px 0; }
        .alert { background-color: #fef2f2; border-left: 4px solid #dc2626; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e8f0; color: #64748b; font-size: 14px; }
        strong { color: #1e293b; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚫 Compte Supprimé</h1>
        </div>

        <div class="content">
            <p>Bonjour <strong>{{ $employeeName }}</strong>,</p>

            <div class="alert">
                <p style="margin: 0;"><strong>⚠️ Votre compte a été supprimé</strong></p>
            </div>

            <p>Nous vous informons que votre compte sur la plateforme DigiCard associé à l'entreprise <strong>{{ $companyName }}</strong> a été supprimé par votre administrateur.</p>

            <p><strong>Conséquences de cette suppression :</strong></p>
            <ul>
                <li>Vos identifiants de connexion ne sont plus valides</li>
                <li>Vous ne pouvez plus accéder à votre compte</li>
                <li>Vos données de profil ont été supprimées</li>
                <li>Vos cartes digitales associées à ce compte ne sont plus accessibles</li>
            </ul>

            <p>Si vous pensez qu'il s'agit d'une erreur ou si vous avez des questions, veuillez contacter directement votre administrateur chez <strong>{{ $companyName }}</strong>.</p>
        </div>

        <div class="footer">
            <p>Merci,<br><strong>L'équipe Arcc En Ciel</strong></p>
            <p style="font-size: 12px; color: #94a3b8;">Cet email est envoyé automatiquement, merci de ne pas y répondre.</p>
        </div>
    </div>
</body>
</html>


