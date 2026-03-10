# Script PowerShell pour exécuter les rappels de rendez-vous
# Ce script peut être exécuté manuellement ou via le Planificateur de tâches Windows

$projectPath = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $projectPath

Write-Host "=== Planification des rappels de rendez-vous ===" -ForegroundColor Cyan
Write-Host "Date/Heure : $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')" -ForegroundColor Gray
Write-Host ""

# Exécuter la commande Artisan
php artisan appointments:schedule-reminders

# Code de sortie
$exitCode = $LASTEXITCODE
if ($exitCode -eq 0) {
    Write-Host "`n✅ Commande exécutée avec succès" -ForegroundColor Green
} else {
    Write-Host "`n❌ Erreur lors de l'exécution (code: $exitCode)" -ForegroundColor Red
}

exit $exitCode
