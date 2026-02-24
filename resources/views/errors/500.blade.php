<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Erreur serveur - DigiCard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; background: #fef2f2; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { text-align: center; padding: 2rem; max-width: 420px; }
        h1 { font-size: 4rem; color: #991b1b; margin-bottom: 0.5rem; }
        p { color: #64748b; margin-bottom: 1.5rem; line-height: 1.6; }
        a { color: #0ea5e9; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <h1>500</h1>
        <p>Une erreur interne s'est produite. Notre équipe en a été informée. Veuillez réessayer plus tard.</p>
        <a href="{{ url('/') }}">Retour à l'accueil</a>
    </div>
</body>
</html>
