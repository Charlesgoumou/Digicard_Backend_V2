<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmployeeDevicePointageRestrictionMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $employeeName;
    public string $deviceModel;
    public string $orderNumber;
    public string $companyName;

    public function __construct(
        string $employeeName,
        string $deviceModel,
        string $orderNumber,
        ?string $companyName = null
    ) {
        $this->employeeName = trim($employeeName) !== '' ? $employeeName : 'Employé';
        $this->deviceModel = trim($deviceModel) !== '' ? $deviceModel : 'Appareil enregistré';
        $this->orderNumber = trim($orderNumber) !== '' ? $orderNumber : '-';
        $this->companyName = trim((string) $companyName) !== '' ? trim((string) $companyName) : 'votre entreprise';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Pointage autorisé uniquement depuis votre appareil enregistré',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.employee-device-pointage-restriction',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
