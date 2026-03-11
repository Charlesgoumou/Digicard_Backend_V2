<?php

namespace App\Console\Commands;

use App\Jobs\SendAppointmentReminder;
use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Commande pour planifier les rappels automatiques des rendez-vous.
 * 
 * Cette commande doit être exécutée toutes les minutes via cron :
 * * * * * * cd /path-to-project && php artisan appointments:schedule-reminders
 * 
 * Elle planifie les rappels à 30 minutes et 10 minutes avant chaque rendez-vous confirmé.
 */
class ScheduleAppointmentReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'appointments:schedule-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Planifie les rappels automatiques pour les rendez-vous (30 min et 10 min avant)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = Carbon::now();
        
        // Calculer les moments où les rappels doivent être envoyés
        $reminder30MinTime = $now->copy()->addMinutes(30);
        $reminder10MinTime = $now->copy()->addMinutes(10);
        
        // Tolérance de 1 minute (pour éviter les doublons si la commande s'exécute plusieurs fois)
        $tolerance = 1; // minutes
        
        // Récupérer les rendez-vous confirmés qui nécessitent un rappel à 30 minutes
        $appointments30Min = Appointment::where('status', Appointment::STATUS_CONFIRMED)
            ->whereBetween('start_time', [
                $reminder30MinTime->copy()->subMinutes($tolerance),
                $reminder30MinTime->copy()->addMinutes($tolerance),
            ])
            ->where('start_time', '>', $now) // Seulement les rendez-vous futurs
            ->get();
        
        // Récupérer les rendez-vous confirmés qui nécessitent un rappel à 10 minutes
        $appointments10Min = Appointment::where('status', Appointment::STATUS_CONFIRMED)
            ->whereBetween('start_time', [
                $reminder10MinTime->copy()->subMinutes($tolerance),
                $reminder10MinTime->copy()->addMinutes($tolerance),
            ])
            ->where('start_time', '>', $now) // Seulement les rendez-vous futurs
            ->get();
        
        $scheduledCount = 0;
        
        // Planifier les rappels à 30 minutes
        foreach ($appointments30Min as $appointment) {
            // Envoyer au propriétaire
            SendAppointmentReminder::dispatch($appointment, 30, 'owner')
                ->delay(now()->addSeconds(5)); // Petit délai pour éviter la surcharge
            
            // Envoyer au demandeur
            SendAppointmentReminder::dispatch($appointment, 30, 'visitor')
                ->delay(now()->addSeconds(5));
            
            $scheduledCount += 2;
            
            Log::info('Rappel 30 min planifié pour rendez-vous', [
                'appointment_id' => $appointment->id,
                'start_time' => $appointment->start_time->format('Y-m-d H:i:s'),
            ]);
        }
        
        // Planifier les rappels à 10 minutes
        foreach ($appointments10Min as $appointment) {
            // Envoyer au propriétaire
            SendAppointmentReminder::dispatch($appointment, 10, 'owner')
                ->delay(now()->addSeconds(5));
            
            // Envoyer au demandeur
            SendAppointmentReminder::dispatch($appointment, 10, 'visitor')
                ->delay(now()->addSeconds(5));
            
            $scheduledCount += 2;
            
            Log::info('Rappel 10 min planifié pour rendez-vous', [
                'appointment_id' => $appointment->id,
                'start_time' => $appointment->start_time->format('Y-m-d H:i:s'),
            ]);
        }
        
        $this->info("Rappels planifiés : {$scheduledCount} emails pour " . ($appointments30Min->count() + $appointments10Min->count()) . " rendez-vous(s)");
        
        return Command::SUCCESS;
    }
}
