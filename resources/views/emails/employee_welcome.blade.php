<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bienvenue chez {{ $companyName }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 20px auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; }
        .password { font-size: 18px; font-weight: bold; color: #dc2626; background-color: #f1f5f9; padding: 5px 10px; border-radius: 4px; display: inline-block; margin: 10px 0; }
        .button { display: inline-block; padding: 10px 20px; background-color: #0ea5e9; color: #ffffff !important; text-decoration: none; border-radius: 5px; margin-top: 15px; }
        a { color: #0ea5e9; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Bienvenue dans l'équipe {{ $companyName }} !</h1>
        <p>Bonjour,</p>
        <p>Votre administrateur vous a créé un compte sur la plateforme DigiCard.</p>
        <p>Voici vos informations de connexion initiales :</p>
        <ul>
            <li><strong>Email :</strong> {{ $employeeEmail }}</li>
            <li><strong>Mot de passe temporaire :</strong> <span class="password">{{ $password }}</span></li>
        </ul>
        <p><strong>Important :</strong> Lors de votre première connexion, il vous sera demandé de vérifier votre email (un code vous sera envoyé) puis de définir votre propre mot de passe.</p>
        <p>
            <a href="{{ $loginUrl }}" class="button">Se Connecter</a>
        </p>
        <p>Merci,<br>L'équipe Arcc En Ciel</p>
    </div>
</body>
</html>
