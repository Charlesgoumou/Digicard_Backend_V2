<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Rapport pointage</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #111; }
        h1 { font-size: 14px; margin: 0 0 6px 0; }
        .meta { font-size: 9px; margin-bottom: 10px; color: #333; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #444; padding: 3px 4px; text-align: left; vertical-align: top; }
        th { background: #e8e8e8; font-weight: bold; }
        tr:nth-child(even) { background: #f7f7f7; }
    </style>
</head>
<body>
    <h1>Rapport d'assiduité (pointage)</h1>
    <div class="meta">
        Commande #{{ $meta['order_number'] ?? $meta['order_id'] }}
        &mdash; Période : {{ $meta['period'] }}
        &mdash; Du {{ $meta['from'] }} au {{ $meta['to'] }}
        &mdash; Filtre : {{ $meta['group_filter'] }}
    </div>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Groupe</th>
                <th>Nom</th>
                <th>Email</th>
                <th>Mat.</th>
                <th>Arrivée</th>
                <th>Départ</th>
                <th>Durée</th>
                <th>Statut</th>
                <th>Retard</th>
                <th>GPS in</th>
                <th>GPS out</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td>{{ $r['date'] }}</td>
                    <td>{{ $r['groupe'] }}</td>
                    <td>{{ $r['nom'] }}</td>
                    <td>{{ $r['email'] }}</td>
                    <td>{{ $r['matricule'] }}</td>
                    <td>{{ $r['arrivee'] }}</td>
                    <td>{{ $r['depart'] !== '' ? $r['depart'] : '--:--' }}</td>
                    <td>{{ $r['duree_min'] }}</td>
                    <td>{{ $r['statut'] }}</td>
                    <td>{{ $r['retard'] }}</td>
                    <td style="font-size: 7px;">{{ $r['gps_entree'] }}</td>
                    <td style="font-size: 7px;">{{ $r['gps_sortie'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="12">Aucun pointage sur la période pour le filtre sélectionné.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
