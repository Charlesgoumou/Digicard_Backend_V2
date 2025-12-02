<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Nouvelles cartes DigiCard</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h2 style="color: #0ea5e9;">Nouvelles cartes DigiCard</h2>

        <p>Bonjour {{ $employeeName }},</p>

        <p>Bonne nouvelle ! Votre entreprise <strong>{{ $companyName }}</strong> vous a assigné
        <strong>{{ $cardQuantity }} nouvelle(s) carte(s)</strong> DigiCard.</p>

        <p><strong>Numéro de commande :</strong> {{ $orderNumber }}</p>

        <p>Pour configurer vos nouvelles cartes :</p>
        <ol>
            <li>Connectez-vous à votre compte DigiCard</li>
            <li>Accédez à la section "Paramétrer ma Carte"</li>
            <li>Vous y trouverez votre nouvelle commande</li>
            <li>Configurez vos nouvelles cartes (vous pouvez réutiliser les mêmes paramètres que vos cartes précédentes)</li>
        </ol>

        <p style="text-align: center; margin: 30px 0;">
            <a href="{{ $loginUrl }}"
               style="background-color: #0ea5e9; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;">
                Accéder à mon compte
            </a>
        </p>

        <p style="color: #666; font-size: 14px;">
            Si vous avez des questions, n'hésitez pas à contacter votre administrateur.
        </p>

        <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">

        <p style="color: #999; font-size: 12px; text-align: center;">
            Ceci est un email automatique, merci de ne pas y répondre.<br>
            DigiCard - Cartes de visite digitales professionnelles
        </p>
    </div>
</body>
</html>
