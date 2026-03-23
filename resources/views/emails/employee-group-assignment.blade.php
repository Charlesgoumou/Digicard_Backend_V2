<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Affectation au groupe {{ $groupName }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #0f172a; }
        .container { max-width: 720px; margin: 20px auto; padding: 22px; border: 1px solid #e2e8f0; border-radius: 10px; background: #ffffff; }
        .muted { color: #475569; }
        .box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin: 14px 0; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        h2 { font-size: 17px; margin: 16px 0 8px; }
        ul { margin: 8px 0 0 18px; padding: 0; }
        li { margin: 5px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Affectation au groupe de pointage</h1>
        <p>Bonjour {{ $employeeName }},</p>
        <p>
            Vous avez été affecté(e) au groupe <strong>{{ $groupName }}</strong> par
            <strong>{{ $adminName }}</strong> (business admin de la commande N° {{ $orderNumber }} pour l'entreprise {{ $companyName }}).
        </p>

        <div class="box">
            <h2>Détails de votre groupe</h2>
            <ul>
                <li><strong>Jours ouvrés :</strong> {{ $workdaysLabel }}</li>
                <li><strong>Heure de début :</strong> {{ $startTime }}</li>
                <li><strong>Heure de fin :</strong> {{ $endTime }}</li>
                <li><strong>Tolérance retard :</strong> {{ $toleranceLabel }}</li>
            </ul>
        </div>

        <div class="box">
            <h2>Règles de configuration du compte et appareil</h2>
            <ul>
                <li>Le système récupère et enregistre le modèle de votre téléphone lors de la configuration de votre compte.</li>
                <li><strong>Modèle actuellement enregistré :</strong> {{ $deviceModelLabel }}.</li>
                <li>Vous ne pouvez vous connecter et pointer que depuis le téléphone et le navigateur enregistrés.</li>
            </ul>
        </div>

        <div class="box">
            <h2>Règles de pointage</h2>
            <ul>
                <li>Le bouton de pointage se trouve sur votre profil public.</li>
                <li>Pour pointer, vous devez être à l'intérieur de la zone de couverture définie par votre business admin.</li>
            </ul>
        </div>

        <div class="box">
            <h2>Rapports automatiques envoyés au business admin</h2>
            <p class="muted">En fin de journée, semaine, mois ou année, un rapport de votre activité est automatiquement envoyé à votre business admin.</p>
            <p>Ce rapport contient notamment :</p>
            <ul>
                <li>Prénom</li>
                <li>Nom</li>
                <li>Poste</li>
                <li>Statut (en retard, à l'heure, absent)</li>
                <li>Nombre d'heures travaillées (jour, semaine, mois, année)</li>
            </ul>
            <p><strong>Important :</strong> pour toute absence, merci d'en avertir votre business admin.</p>
        </div>

        <p class="muted">Entreprise : {{ $companyName }}</p>
        <p>Merci,<br>L'équipe Arcc En Ciel</p>
    </div>
</body>
</html>
