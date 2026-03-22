<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 14px; margin: 0 0 6px 0; }
        .meta { font-size: 9px; color: #444; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #333; padding: 4px 5px; text-align: left; vertical-align: top; }
        th { background: #eee; font-weight: bold; }
        tr:nth-child(even) { background: #f7f7f7; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">
        Commande #{{ $orderNumber }} — {{ $periodLabel }}<br>
        Période du {{ $startDate }} au {{ $endDate }}@if($groupLabel) — {{ $groupLabel }}@endif
    </div>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Groupe</th>
                <th>Employé</th>
                <th>Email</th>
                <th>Matricule</th>
                <th>Arrivée</th>
                <th>Départ</th>
                <th>Statut</th>
                <th>Heures (jour)</th>
                <th>Retard</th>
                <th>GPS entrée</th>
                <th>GPS sortie</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $r)
            <tr>
                <td>{{ $r['date'] }}</td>
                <td>{{ $r['group_label'] }}</td>
                <td>{{ $r['full_name'] }}</td>
                <td>{{ $r['email'] }}</td>
                <td>{{ $r['matricule'] }}</td>
                <td>{{ $r['arrival'] }}</td>
                <td>{{ $r['departure'] }}</td>
                <td>{{ $r['status_label'] }}</td>
                <td>{{ $r['hours_worked'] ?? '' }}</td>
                <td>{{ $r['late_label'] }}</td>
                <td>{{ $r['gps_in'] }}</td>
                <td>{{ $r['gps_out'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
