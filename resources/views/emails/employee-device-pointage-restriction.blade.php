<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restriction appareil pointage</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #0f172a; }
        .container { max-width: 680px; margin: 20px auto; padding: 22px; border: 1px solid #e2e8f0; border-radius: 10px; background: #ffffff; }
        .box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin: 14px 0; }
        .muted { color: #475569; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Configuration de pointage terminée</h1>
        <p>Bonjour {{ $employeeName }},</p>
        <p>
            Votre carte est configurée pour la commande N° {{ $orderNumber }} de l'entreprise {{ $companyName }}.
        </p>
        <div class="box">
            <p>
                Vous êtes habilité(e) à émarger uniquement à partir de votre téléphone
                <strong>{{ $deviceModel }}</strong> via le navigateur sur lequel votre compte a été paramétré.
            </p>
        </div>
        <p class="muted">Si vous changez d'appareil, contactez votre business admin.</p>
        <p>Merci,<br>L'équipe Arcc En Ciel</p>
    </div>
</body>
</html>
