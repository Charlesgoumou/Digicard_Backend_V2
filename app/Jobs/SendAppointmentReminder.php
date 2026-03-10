<?php

namespace App\Jobs;

use App\Mail\AppointmentReminder;
use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Job pour envoyer un rappel de rendez-vous.
 * 
 * Ce job est dispatché automatiquement par la commande ScheduleAppointmentReminders
 * pour envoyer des rappels à 30 minutes et 10 minutes avant chaque rendez-vous.
 */
class SendAppointmentReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Le rendez-vous.
     */
    public Appointment $appointment;

    /**
     * Minutes avant le rendez-vous (30 ou 10).
     */
    public int $minutesBefore;

    /**
     * Type de destinataire ('owner' ou 'visitor').
     */
    public string $recipientType;

    /**
     * Create a new job instance.
     */
    public function __construct(Appointment $appointment, int $minutesBefore, string $recipientType = 'owner')
    {
        $this->appointment = $appointment;
        $this->minutesBefore = $minutesBefore;
        $this->recipientType = $recipientType;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Vérifier que le rendez-vous est toujours confirmé
        $appointment = Appointment::find($this->appointment->id);
        
        if (!$appointment || $appointment->status !== Appointment::STATUS_CONFIRMED) {
            Log::info('Rappel de rendez-vous ignoré (rendez-vous annulé ou introuvable)', [
                'appointment_id' => $this->appointment->id,
                'status' => $appointment ? $appointment->status : 'not_found',
            ]);
            return;
        }

        // Vérifier que le rendez-vous n'est pas passé
        if ($appointment->start_time->isPast()) {
            Log::info('Rappel de rendez-vous ignoré (rendez-vous déjà passé)', [
                'appointment_id' => $this->appointment->id,
                'start_time' => $appointment->start_time->format('Y-m-d H:i:s'),
            ]);
            return;
        }

        try {
            // Déterminer l'email du destinataire
            $recipientEmail = $this->recipientType === 'owner' 
                ? $appointment->user->email 
                : $appointment->visitor_email;

            // Envoyer l'email de rappel
            Mail::to($recipientEmail)->send(
                new AppointmentReminder($appointment, $this->minutesBefore, $this->recipientType)
            );

            Log::info('Rappel de rendez-vous envoyé avec succès', [
                'appointment_id' => $appointment->id,
                'recipient_type' => $this->recipientType,
                'recipient_email' => $recipientEmail,
                'minutes_before' => $this->minutesBefore,
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'envoi du rappel de rendez-vous', [
                'appointment_id' => $appointment->id,
                'recipient_type' => $this->recipientType,
                'error' => $e->getMessage(),
            ]);
            
            // Relancer le job en cas d'échec (jusqu'à 3 tentatives)
            throw $e;
        }
    }
}
