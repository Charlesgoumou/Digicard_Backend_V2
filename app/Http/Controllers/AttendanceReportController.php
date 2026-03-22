<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderEmployee;
use App\Models\OrderEmployeePointage;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Rapports d'assiduité (pointage) pour les business_admin.
 * Données issues de order_employee_pointages (équivalent fonctionnel d'attendance_logs).
 */
class AttendanceReportController extends Controller
{
    /** Tolérances retard autorisées (minutes après l’heure d’arrivée). */
    private const LATE_TOLERANCE_MINUTES_ALLOWED = [15, 30, 60, 120];

    private function canAccessOrder(User $user, Order $order): bool
    {
        if ($user->role === 'employee') {
            return OrderEmployee::where('order_id', $order->id)
                ->where('employee_id', $user->id)
                ->exists();
        }

        return (int) $order->user_id === (int) $user->id;
    }

    /**
     * GET /api/business/reports/attendance?order_id=&date=
     */
    public function attendance(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'business_admin') {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        $validated = $request->validate([
            'order_id' => 'required|integer|exists:orders,id',
            'date' => 'nullable|date_format:Y-m-d',
        ]);

        $order = Order::findOrFail($validated['order_id']);
        if (!$this->canAccessOrder($user, $order)) {
            return response()->json(['message' => 'Accès refusé à cette commande'], 403);
        }

        $tz = config('app.timezone');
        $dateStr = $validated['date'] ?? Carbon::today($tz)->toDateString();
        $dateCarbon = Carbon::parse($dateStr, $tz)->startOfDay();
        $dow = (int) $dateCarbon->format('N');

        $securityGroups = is_array($order->security_groups) ? $order->security_groups : [];
        $groupConfigs = is_array($order->group_security_configs) ? $order->group_security_configs : [];

        $orderEmployees = OrderEmployee::where('order_id', $order->id)
            ->with(['employee:id,name,email,avatar_url'])
            ->orderBy('id')
            ->get();

        $oeIds = $orderEmployees->pluck('id')->all();
        $pointages = OrderEmployeePointage::whereIn('order_employee_id', $oeIds)
            ->whereDate('work_date', $dateStr)
            ->get()
            ->keyBy('order_employee_id');

        $placedIds = [];
        $resultGroups = [];
        $groupNumber = 0;

        foreach ($securityGroups as $i => $gRaw) {
            ++$groupNumber;
            $gname = is_string($gRaw) ? trim($gRaw) : '';
            $cfg = isset($groupConfigs[$i]) && is_array($groupConfigs[$i]) ? $groupConfigs[$i] : [];
            $isWorkingDay = $this->isGroupWorkingDay($cfg, $dow);
            $lateLimit = $this->groupLateLimitCarbon($dateStr, $cfg, $tz);

            $members = $orderEmployees->filter(function ($oe) use ($gname) {
                return trim((string) $oe->employee_group) === $gname;
            })->values();

            foreach ($members as $oe) {
                $placedIds[$oe->id] = true;
            }

            $rows = [];
            $presentInGroup = 0;
            $expectedInGroup = 0;

            foreach ($members as $oe) {
                $pt = $pointages->get($oe->id);
                $row = $this->buildEmployeeRow($oe, $pt, $tz, $isWorkingDay, $lateLimit);
                $rows[] = $row;

                if ($oe->is_configured && $isWorkingDay) {
                    ++$expectedInGroup;
                    if ($row['check_in_time'] !== null) {
                        ++$presentInGroup;
                    }
                }
            }

            $resultGroups[] = [
                'index' => $i,
                'display_index' => $groupNumber,
                'security_group_id' => $i,
                'name' => $gname,
                'title' => $gname !== ''
                    ? sprintf('Groupe %d : %s', $groupNumber, $gname)
                    : sprintf('Groupe %d', $groupNumber),
                'is_working_day' => $isWorkingDay,
                'daily_window_start' => $cfg['calendar']['dailyWindow']['start'] ?? '08:00',
                'late_tolerance_minutes' => $this->normalizeLateToleranceMinutes($cfg),
                'presence_ratio' => [
                    'present' => $presentInGroup,
                    'expected' => $expectedInGroup,
                ],
                'rows' => $rows,
            ];
        }

        $unplaced = $orderEmployees->filter(function ($oe) use ($placedIds) {
            return empty($placedIds[$oe->id]);
        })->values();

        if ($unplaced->isNotEmpty()) {
            $fallbackCfg = $this->defaultGroupConfig();
            $isWorkingDay = $this->isGroupWorkingDay($fallbackCfg, $dow);
            $lateLimit = $this->groupLateLimitCarbon($dateStr, $fallbackCfg, $tz);

            $rows = [];
            $presentInGroup = 0;
            $expectedInGroup = 0;

            foreach ($unplaced as $oe) {
                $pt = $pointages->get($oe->id);
                $row = $this->buildEmployeeRow($oe, $pt, $tz, $isWorkingDay, $lateLimit);
                $rows[] = $row;

                if ($oe->is_configured && $isWorkingDay) {
                    ++$expectedInGroup;
                    if ($row['check_in_time'] !== null) {
                        ++$presentInGroup;
                    }
                }
            }

            $resultGroups[] = [
                'index' => null,
                'display_index' => null,
                'security_group_id' => null,
                'name' => '__ungrouped__',
                'title' => 'Employés non assignés à un groupe de sécurité',
                'is_working_day' => $isWorkingDay,
                'daily_window_start' => $fallbackCfg['calendar']['dailyWindow']['start'] ?? '08:00',
                'late_tolerance_minutes' => $this->normalizeLateToleranceMinutes($fallbackCfg),
                'presence_ratio' => [
                    'present' => $presentInGroup,
                    'expected' => $expectedInGroup,
                ],
                'rows' => $rows,
            ];
        }

        $stats = $this->recomputeGlobalStats($orderEmployees, $pointages, $securityGroups, $groupConfigs, $dateStr, $dow, $tz);

        // Données : table order_employee_pointages (équivalent métier attendance_logs)
        return response()->json([
            'date' => $dateStr,
            'order_id' => $order->id,
            'data_source' => 'order_employee_pointages',
            'stats' => $stats,
            'groups' => $resultGroups,
        ]);
    }

    /**
     * GET /api/business/reports/attendance/export
     * Query: order_id, period (day|week|month|quarter|semester|year), date (ancre), group_index (null=tous, -1=non assignés, 0+=index groupe), format (csv|xlsx|pdf)
     */
    public function export(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'business_admin') {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        $validated = $request->validate([
            'order_id' => 'required|integer|exists:orders,id',
            'period' => 'nullable|string|in:day,week,month,quarter,semester,year',
            'group_index' => 'nullable|integer|min:-1',
            'date' => 'nullable|date_format:Y-m-d',
            'format' => 'nullable|string|in:csv,xlsx,pdf',
        ]);

        $order = Order::findOrFail($validated['order_id']);
        if (!$this->canAccessOrder($user, $order)) {
            return response()->json(['message' => 'Accès refusé à cette commande'], 403);
        }

        $tz = config('app.timezone');
        $anchor = Carbon::parse($validated['date'] ?? Carbon::today($tz)->toDateString(), $tz)->startOfDay();
        $period = $validated['period'] ?? 'day';
        $format = $validated['format'] ?? 'xlsx';

        [$start, $end] = $this->periodBounds($anchor, $period, $tz);
        $groupIndex = array_key_exists('group_index', $validated) ? $validated['group_index'] : null;

        $allEmployees = OrderEmployee::where('order_id', $order->id)
            ->with(['employee:id,name,email,avatar_url'])
            ->orderBy('id')
            ->get();

        $employees = $this->filterEmployeesForExport($allEmployees, $order, $groupIndex);
        if ($employees->isEmpty()) {
            return response()->json(['message' => 'Aucun employé pour ce filtre de groupe.'], 422);
        }

        $securityGroups = is_array($order->security_groups) ? $order->security_groups : [];
        $groupConfigs = is_array($order->group_security_configs) ? $order->group_security_configs : [];

        $flatRows = $this->collectExportFlatRows($order, $employees, $securityGroups, $groupConfigs, $start, $end, $tz);

        if (count($flatRows) === 0) {
            return response()->json(['message' => 'Aucune ligne à exporter sur cette période.'], 422);
        }

        $periodLabel = $this->periodLabelFr($period);
        $groupLabel = $this->exportGroupDescription($order, $groupIndex);
        $baseFilename = $this->buildExportBaseFilename($order, $period, $start, $end, $groupIndex);

        if ($format === 'csv') {
            return $this->buildCsvDownload($flatRows, $baseFilename);
        }
        if ($format === 'pdf') {
            return $this->buildPdfDownload($order, $flatRows, $periodLabel, $groupLabel, $start, $end, $baseFilename);
        }

        return $this->buildXlsxDownload($flatRows, $baseFilename);
    }

    /**
     * @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon}
     */
    private function periodBounds(Carbon $anchor, string $period, string $tz): array
    {
        $a = $anchor->copy()->timezone($tz);

        switch ($period) {
            case 'week':
                return [
                    $a->copy()->startOfWeek(Carbon::MONDAY)->startOfDay(),
                    $a->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay(),
                ];
            case 'month':
                return [
                    $a->copy()->startOfMonth()->startOfDay(),
                    $a->copy()->endOfMonth()->endOfDay(),
                ];
            case 'quarter':
                return [
                    $a->copy()->firstOfQuarter()->startOfDay(),
                    $a->copy()->lastOfQuarter()->endOfDay(),
                ];
            case 'semester':
                $y = $a->year;
                if ($a->month <= 6) {
                    return [
                        Carbon::create($y, 1, 1, 0, 0, 0, $tz),
                        Carbon::create($y, 6, 30, 23, 59, 59, $tz),
                    ];
                }

                return [
                    Carbon::create($y, 7, 1, 0, 0, 0, $tz),
                    Carbon::create($y, 12, 31, 23, 59, 59, $tz),
                ];
            case 'year':
                return [
                    $a->copy()->startOfYear()->startOfDay(),
                    $a->copy()->endOfYear()->endOfDay(),
                ];
            case 'day':
            default:
                return [$a->copy()->startOfDay(), $a->copy()->endOfDay()];
        }
    }

    private function periodLabelFr(string $period): string
    {
        return match ($period) {
            'week' => 'Semaine',
            'month' => 'Mois',
            'quarter' => 'Trimestre',
            'semester' => 'Semestre',
            'year' => 'Année',
            default => 'Jour',
        };
    }

    private function exportGroupDescription(Order $order, ?int $groupIndex): string
    {
        if ($groupIndex === null) {
            return 'Tous les groupes';
        }
        if ($groupIndex === -1) {
            return 'Employés non assignés à un groupe de sécurité';
        }
        $groups = is_array($order->security_groups) ? $order->security_groups : [];
        $name = isset($groups[$groupIndex]) ? trim((string) $groups[$groupIndex]) : '';

        return $name !== ''
            ? sprintf('Groupe %d : %s', $groupIndex + 1, $name)
            : sprintf('Groupe %d', $groupIndex + 1);
    }

    private function buildExportBaseFilename(Order $order, string $period, Carbon $start, Carbon $end, ?int $groupIndex): string
    {
        $g = $groupIndex === null ? 'all' : (string) $groupIndex;
        $slug = Str::slug($period.'-'.$start->format('Y-m-d').'-'.$end->format('Y-m-d').'-g'.$g, '-');

        return 'assiduite-commande-'.$order->id.'-'.$slug;
    }

    /**
     * @param  Collection<int, OrderEmployee>  $all
     * @return Collection<int, OrderEmployee>
     */
    private function filterEmployeesForExport(Collection $all, Order $order, ?int $groupIndex): Collection
    {
        if ($groupIndex === null) {
            return $all->values();
        }

        $securityGroups = is_array($order->security_groups) ? $order->security_groups : [];
        $knownNonEmpty = [];
        foreach ($securityGroups as $gRaw) {
            $n = is_string($gRaw) ? trim($gRaw) : '';
            if ($n !== '') {
                $knownNonEmpty[$n] = true;
            }
        }

        if ($groupIndex === -1) {
            return $all->filter(function ($oe) use ($knownNonEmpty) {
                $eg = trim((string) $oe->employee_group);

                return $eg === '' || !isset($knownNonEmpty[$eg]);
            })->values();
        }

        if (!isset($securityGroups[$groupIndex])) {
            return collect();
        }

        $target = trim((string) ($securityGroups[$groupIndex] ?? ''));

        return $all->filter(function ($oe) use ($target) {
            return trim((string) $oe->employee_group) === $target;
        })->values();
    }

    private function resolveGroupLabelForEmployee(OrderEmployee $oe, Order $order): string
    {
        $g = trim((string) $oe->employee_group);
        $groups = is_array($order->security_groups) ? $order->security_groups : [];
        foreach ($groups as $i => $gRaw) {
            $name = is_string($gRaw) ? trim($gRaw) : '';
            if ($name === $g) {
                return $name !== ''
                    ? sprintf('Groupe %d : %s', $i + 1, $name)
                    : sprintf('Groupe %d', $i + 1);
            }
        }

        return 'Non assigné';
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function collectExportFlatRows(
        Order $order,
        Collection $orderEmployees,
        array $securityGroups,
        array $groupConfigs,
        Carbon $start,
        Carbon $end,
        string $tz
    ): array {
        $oeIds = $orderEmployees->pluck('id')->all();
        if (count($oeIds) === 0) {
            return [];
        }

        $allPts = OrderEmployeePointage::whereIn('order_employee_id', $oeIds)
            ->whereDate('work_date', '>=', $start->toDateString())
            ->whereDate('work_date', '<=', $end->toDateString())
            ->get()
            ->groupBy(function ($p) {
                $wd = $p->work_date;
                if ($wd instanceof \Carbon\CarbonInterface) {
                    return $wd->format('Y-m-d');
                }

                return substr((string) $wd, 0, 10);
            });

        $out = [];
        $cursor = $start->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $dateStr = $cursor->toDateString();
            $dow = (int) $cursor->format('N');
            $ptsForDay = ($allPts->get($dateStr, collect()))->keyBy('order_employee_id');

            foreach ($orderEmployees as $oe) {
                $pt = $ptsForDay->get($oe->id);
                [$cfg, $isWorkingDay] = $this->resolveConfigForEmployee($oe, $securityGroups, $groupConfigs, $dow);
                $lateLimit = $this->groupLateLimitCarbon($dateStr, $cfg, $tz);
                $row = $this->buildEmployeeRow($oe, $pt, $tz, $isWorkingDay, $lateLimit);

                $out[] = [
                    'date' => $dateStr,
                    'group_label' => $this->resolveGroupLabelForEmployee($oe, $order),
                    'full_name' => $row['full_name'],
                    'email' => $row['email'],
                    'matricule' => $row['matricule'] ?? '',
                    'arrival' => $row['arrival'] ?? '',
                    'departure' => $row['departure'] !== null && $row['departure'] !== '' ? $row['departure'] : '--:--',
                    'status' => $row['status'],
                    'status_label' => $this->statusLabelFr($row['status']),
                    'hours_worked' => $row['hours_worked'] ?? '',
                    'late_label' => $row['is_late'] ? 'Oui' : 'Non',
                    'gps_in' => $this->formatGpsCell($row['gps']['check_in'] ?? null),
                    'gps_out' => $this->formatGpsCell($row['gps']['check_out'] ?? null),
                ];
            }
            $cursor->addDay();
        }

        return $out;
    }

    private function statusLabelFr(string $status): string
    {
        return match ($status) {
            'a_l_heure' => 'À l\'heure',
            'en_poste' => 'En poste',
            'retard' => 'Retard',
            'absent' => 'Absent',
            'jour_off' => 'Jour off',
            'non_configure' => 'Non configuré',
            default => $status,
        };
    }

    /**
     * @param  array{lat: float, lng: float}|null  $pt
     */
    private function formatGpsCell(?array $pt): string
    {
        if (!$pt) {
            return '';
        }

        return number_format($pt['lat'], 6, ',', '').' ; '.number_format($pt['lng'], 6, ',', '');
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    private function buildCsvDownload(array $rows, string $baseFilename): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filename = $this->sanitizeFilename($baseFilename).'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, [
                'Date', 'Groupe', 'Employé', 'Email', 'Matricule', 'Arrivée', 'Départ', 'Statut', 'Temps de travail', 'Retard', 'GPS entrée', 'GPS sortie',
            ], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['date'],
                    $r['group_label'],
                    $r['full_name'],
                    $r['email'],
                    $r['matricule'],
                    $r['arrival'],
                    $r['departure'],
                    $r['status_label'],
                    $r['hours_worked'] ?? '',
                    $r['late_label'],
                    $r['gps_in'],
                    $r['gps_out'],
                ], ';');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    private function buildXlsxDownload(array $rows, string $baseFilename): \Illuminate\Http\Response
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Assiduite');

        $table = [
            ['Date', 'Groupe', 'Employé', 'Email', 'Matricule', 'Arrivée', 'Départ', 'Statut', 'Temps de travail', 'Retard', 'GPS entrée', 'GPS sortie'],
        ];
        foreach ($rows as $r) {
            $table[] = [
                $r['date'],
                $r['group_label'],
                $r['full_name'],
                $r['email'],
                $r['matricule'],
                $r['arrival'],
                $r['departure'],
                $r['status_label'],
                $r['hours_worked'] ?? '',
                $r['late_label'],
                $r['gps_in'],
                $r['gps_out'],
            ];
        }
        $sheet->fromArray($table, null, 'A1', false);

        foreach (range('A', 'L') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        $filename = $this->sanitizeFilename($baseFilename).'.xlsx';

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    private function buildPdfDownload(
        Order $order,
        array $rows,
        string $periodLabel,
        string $groupLabel,
        Carbon $start,
        Carbon $end,
        string $baseFilename
    ): \Illuminate\Http\Response {
        $pdf = Pdf::loadView('reports.attendance_export_pdf', [
            'title' => 'Rapport d\'assiduité (pointage)',
            'orderNumber' => $order->order_number ?? $order->id,
            'periodLabel' => $periodLabel,
            'groupLabel' => $groupLabel,
            'startDate' => $start->format('d/m/Y'),
            'endDate' => $end->format('d/m/Y'),
            'rows' => $rows,
        ])->setPaper('a4', 'landscape');

        $filename = $this->sanitizeFilename($baseFilename).'.pdf';

        return $pdf->download($filename);
    }

    private function sanitizeFilename(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? $name;

        return trim($name, '-');
    }

    private function recomputeGlobalStats($orderEmployees, $pointages, array $securityGroups, array $groupConfigs, string $dateStr, int $dow, string $tz): array
    {
        $configured = $orderEmployees->filter(function ($oe) {
            return (bool) $oe->is_configured;
        });

        // total_employees = configurés ; present/late/missing_checkout = sous-ensemble ayant pointé
        $stats = [
            'total_employees' => $configured->count(),
            'present' => 0,
            'late' => 0,
            'missing_checkout' => 0,
        ];

        foreach ($configured as $oe) {
            $pt = $pointages->get($oe->id);
            if (!$pt || !$pt->check_in_time) {
                continue;
            }

            ++$stats['present'];

            [$cfg, $isWorkingDay] = $this->resolveConfigForEmployee($oe, $securityGroups, $groupConfigs, $dow);
            if ($isWorkingDay) {
                $lateLimit = $this->groupLateLimitCarbon($dateStr, $cfg, $tz);
                $checkIn = $pt->check_in_time instanceof Carbon
                    ? $pt->check_in_time->copy()->timezone($tz)
                    : Carbon::parse($pt->check_in_time, $tz);

                if ($checkIn->gt($lateLimit)) {
                    ++$stats['late'];
                }
            }

            if (!$pt->check_out_time) {
                ++$stats['missing_checkout'];
            }
        }

        return $stats;
    }

    /**
     * @return array{0: array, 1: bool}
     */
    private function resolveConfigForEmployee(OrderEmployee $oe, array $securityGroups, array $groupConfigs, int $dow): array
    {
        $g = trim((string) $oe->employee_group);
        foreach ($securityGroups as $i => $gRaw) {
            $name = is_string($gRaw) ? trim($gRaw) : '';
            if ($name === $g) {
                $cfg = isset($groupConfigs[$i]) && is_array($groupConfigs[$i]) ? $groupConfigs[$i] : [];

                return [$cfg, $this->isGroupWorkingDay($cfg, $dow)];
            }
        }

        $fallback = $this->defaultGroupConfig();

        return [$fallback, $this->isGroupWorkingDay($fallback, $dow)];
    }

    private function defaultGroupConfig(): array
    {
        return [
            'calendar' => [
                'weekdays' => [1, 2, 3, 4, 5],
                'dailyWindow' => ['start' => '08:00', 'end' => '18:00'],
                'lateToleranceMinutes' => 15,
            ],
        ];
    }

    /**
     * Minutes après l’heure d’arrivée avant de compter un retard (15, 30, 60 ou 120).
     */
    private function normalizeLateToleranceMinutes(array $cfg): int
    {
        $cal = $cfg['calendar'] ?? [];
        $v = $cal['lateToleranceMinutes'] ?? $cal['late_tolerance_minutes'] ?? null;
        $n = is_numeric($v) ? (int) $v : 15;

        return in_array($n, self::LATE_TOLERANCE_MINUTES_ALLOWED, true) ? $n : 15;
    }

    /**
     * Date/heure limite d’arrivée sans être marqué en retard (début de journée + tolérance).
     */
    private function groupLateLimitCarbon(string $dateStr, array $cfg, string $tz): Carbon
    {
        $start = $this->groupDeadlineCarbon($dateStr, $cfg, $tz);

        return $start->copy()->addMinutes($this->normalizeLateToleranceMinutes($cfg));
    }

    /**
     * Affichage durée travail : 4h, 0,5h, 1,5h (virgule décimale, unité h).
     */
    private function formatWorkDurationHoursFr(?float $hoursTotal): ?string
    {
        if ($hoursTotal === null || ! is_finite($hoursTotal) || $hoursTotal < 0) {
            return null;
        }
        $rounded = round($hoursTotal, 1);
        $intPart = (int) floor($rounded + 1e-9);
        $frac = $rounded - $intPart;
        if ($frac < 0.05) {
            return $intPart.'h';
        }

        return number_format($rounded, 1, ',', '').'h';
    }

    /**
     * @return array{decimal: float|null, label: string|null}
     */
    private function computeHoursWorked(?Carbon $checkIn, ?Carbon $checkOut): array
    {
        if (!$checkIn || !$checkOut) {
            return ['decimal' => null, 'label' => null];
        }
        $mins = $checkIn->diffInMinutes($checkOut, false);
        if ($mins < 0) {
            return ['decimal' => null, 'label' => null];
        }
        $hoursExact = $mins / 60.0;

        return [
            'decimal' => round($hoursExact, 2),
            'label' => $this->formatWorkDurationHoursFr(round($hoursExact, 1)),
        ];
    }

    private function isGroupWorkingDay(array $cfg, int $dowIsoN): bool
    {
        $weekdays = $cfg['calendar']['weekdays'] ?? [];
        if (!is_array($weekdays) || count($weekdays) < 1) {
            return true;
        }

        return in_array($dowIsoN, $weekdays, true);
    }

    private function groupDeadlineCarbon(string $dateStr, array $cfg, string $tz): Carbon
    {
        $start = $cfg['calendar']['dailyWindow']['start'] ?? '08:00';
        $parts = explode(':', (string) $start);
        $h = (int) ($parts[0] ?? 8);
        $m = (int) ($parts[1] ?? 0);

        return Carbon::parse($dateStr, $tz)->setTime($h, $m, 0);
    }

    private function buildEmployeeRow(
        OrderEmployee $oe,
        ?OrderEmployeePointage $pt,
        string $tz,
        bool $isWorkingDay,
        Carbon $lateLimit
    ): array {
        $checkIn = $pt && $pt->check_in_time
            ? ($pt->check_in_time instanceof Carbon
                ? $pt->check_in_time->copy()->timezone($tz)
                : Carbon::parse($pt->check_in_time, $tz))
            : null;

        $checkOut = $pt && $pt->check_out_time
            ? ($pt->check_out_time instanceof Carbon
                ? $pt->check_out_time->copy()->timezone($tz)
                : Carbon::parse($pt->check_out_time, $tz))
            : null;

        $late = false;
        if ($checkIn && $isWorkingDay) {
            $late = $checkIn->gt($lateLimit);
        }

        $worked = $this->computeHoursWorked($checkIn, $checkOut);

        $status = $this->resolveStatus($oe, $checkIn, $checkOut, $isWorkingDay, $late);

        $emp = $oe->employee;
        $fullName = ($emp ? $emp->name : null) ?? $oe->profile_name ?? $oe->employee_name ?? '—';
        $email = ($emp ? $emp->email : null) ?? ($oe->emails[0] ?? null) ?? $oe->employee_email ?? '—';
        $avatar = ($emp ? $emp->avatar_url : null) ?? $oe->employee_avatar_url;

        return [
            'order_employee_id' => $oe->id,
            'employee_id' => $oe->employee_id,
            'full_name' => $fullName,
            'email' => $email,
            'avatar_url' => $avatar,
            'matricule' => $oe->employee_matricule ? (string) $oe->employee_matricule : null,
            'is_configured' => (bool) $oe->is_configured,
            'arrival' => $checkIn ? $checkIn->format('H:i') : null,
            'departure' => $checkOut ? $checkOut->format('H:i') : null,
            'check_in_time' => $checkIn ? $checkIn->toIso8601String() : null,
            'check_out_time' => $checkOut ? $checkOut->toIso8601String() : null,
            'hours_worked' => $worked['label'],
            'hours_worked_decimal' => $worked['decimal'],
            'status' => $status,
            'is_late' => $late,
            'is_working_day' => $isWorkingDay,
            'gps' => [
                'check_in' => ($pt && $pt->check_in_lat !== null && $pt->check_in_lng !== null)
                    ? ['lat' => (float) $pt->check_in_lat, 'lng' => (float) $pt->check_in_lng]
                    : null,
                'check_out' => ($pt && $pt->check_out_lat !== null && $pt->check_out_lng !== null)
                    ? ['lat' => (float) $pt->check_out_lat, 'lng' => (float) $pt->check_out_lng]
                    : null,
            ],
        ];
    }

    private function resolveStatus(
        OrderEmployee $oe,
        ?Carbon $checkIn,
        ?Carbon $checkOut,
        bool $isWorkingDay,
        bool $late
    ): string {
        if (!$oe->is_configured) {
            return 'non_configure';
        }

        if (!$isWorkingDay) {
            return 'jour_off';
        }

        if (!$checkIn) {
            return 'absent';
        }

        if ($late) {
            return 'retard';
        }

        if (!$checkOut) {
            return 'en_poste';
        }

        return 'a_l_heure';
    }
}
