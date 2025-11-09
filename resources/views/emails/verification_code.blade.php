<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Code de Vérification</title>
    <style>
        body { font-family: sans-serif; line-height: 1.6; color: #333; }
        .code { font-size: 24px; font-weight: bold; letter-spacing: 5px; color: #0ea5e9; /* Sky blue */ margin: 15px 0; }
        .container { max-width: 600px; margin: 20px auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Votre Code de Vérification</h1>
        <p>Bonjour,</p>
        <p>Utilisez le code ci-dessous pour finaliser votre connexion ou inscription sur Arcc En Ciel. Ce code est valide pendant 15 minutes.</p>
        <p class="code">{{ $code }}</p>
        <p>Si vous n'avez pas demandé ce code, vous pouvez ignorer cet email.</p>
        <p>Merci,<br>L'équipe Arcc En Ciel</p>
    </div>
</body>
</html>
