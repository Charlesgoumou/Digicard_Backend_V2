<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmployeeGroupAssignmentMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $employeeName;
    public string $groupName;
    public string $adminName;
    public string $companyName;
    public string $orderNumber;
    public string $workdaysLabel;
    public string $startTime;
    public string $endTime;
    public string $toleranceLabel;
    public string $deviceModelLabel;

    public function __construct(
        string $employeeName,
        string $groupName,
        string $adminName,
        string $orderNumber,
        ?string $companyName = null,
        array $groupConfig = [],
        ?string $deviceModel = null
    ) {
        $this->employeeName = trim($employeeName) !== '' ? $employeeName : 'Employé';
        $this->groupName = trim($groupName);
        $this->adminName = trim($adminName);
        $this->orderNumber = trim($orderNumber) !== '' ? trim($orderNumber) : '-';
        $this->companyName = trim((string) $companyName) !== '' ? trim((string) $companyName) : 'votre entreprise';

        $calendar = is_array($groupConfig['calendar'] ?? null) ? $groupConfig['calendar'] : [];
        $weekdays = is_array($calendar['weekdays'] ?? null) ? $calendar['weekdays'] : [1, 2, 3, 4, 5];
        $dailyWindow = is_array($calendar['dailyWindow'] ?? null) ? $calendar['dailyWindow'] : [];
        $lateTolerance = $calendar['lateToleranceMinutes'] ?? $calendar['late_tolerance_minutes'] ?? 15;

        $this->workdaysLabel = $this->formatWeekdaysFr($weekdays);
        $this->startTime = $this->normalizeHour((string) ($dailyWindow['start'] ?? '08:00'));
        $this->endTime = $this->normalizeHour((string) ($dailyWindow['end'] ?? '18:00'));
        $this->toleranceLabel = $this->formatTolerance((int) $lateTolerance);
        $this->deviceModelLabel = trim((string) $deviceModel) !== '' ? (string) $deviceModel : 'Non encore enregistré';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Affectation au groupe '.$this->groupName.' - '.$this->companyName,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.employee-group-assignment',
        );
    }

    public function attachments(): array
    {
        return [];
    }

    /**
     * @param  array<int, int|string>  $weekdays
     */
    private function formatWeekdaysFr(array $weekdays): string
    {
        $labels = [
            1 => 'Lundi',
            2 => 'Mardi',
            3 => 'Mercredi',
            4 => 'Jeudi',
            5 => 'Vendredi',
            6 => 'Samedi',
            7 => 'Dimanche',
        ];

        $normalized = [];
        foreach ($weekdays as $w) {
            $n = (int) $w;
            if ($n >= 1 && $n <= 7 && ! in_array($n, $normalized, true)) {
                $normalized[] = $n;
            }
        }
        sort($normalized);

        if ($normalized === []) {
            return 'Tous les jours';
        }

        $names = array_map(static fn (int $n): string => $labels[$n], $normalized);

        return implode(', ', $names);
    }

    private function formatTolerance(int $minutes): string
    {
        return match ($minutes) {
            60 => '1 heure',
            120 => '2 heures',
            default => $minutes.' minutes',
        };
    }

    private function normalizeHour(string $value): string
    {
        $parts = explode(':', trim($value));
        $h = str_pad((string) ((int) ($parts[0] ?? 0)), 2, '0', STR_PAD_LEFT);
        $m = str_pad((string) ((int) ($parts[1] ?? 0)), 2, '0', STR_PAD_LEFT);

        return $h.':'.$m;
    }
}
