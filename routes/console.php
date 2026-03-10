<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nettoyer les contacts partagés expirés (24h après téléchargement) - Toutes les heures
Schedule::command('contacts:cleanup')->hourly();

// ✅ Planifier les rappels automatiques des rendez-vous (30 min et 10 min avant)
// Exécuté toutes les minutes pour détecter les rendez-vous qui nécessitent un rappel
Schedule::command('appointments:schedule-reminders')->everyMinute();
