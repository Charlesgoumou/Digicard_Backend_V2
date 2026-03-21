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
use Symfony\Component\HttpFoundation\Response;

/**
 * Rapports d'assiduité (pointage) pour les business_admin.
 * Données issues de order_employee_pointages (équivalent fonctionnel d'attendance_logs).
 */
class AttendanceReportController extends Controller
{
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
            $deadline = $this->groupDeadlineCarbon($dateStr, $cfg, $tz);

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
                $row = $this->buildEmployeeRow($oe, $pt, $tz, $isWorkingDay, $deadline);
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
            $deadline = $this->groupDeadlineCarbon($dateStr, $fallbackCfg, $tz);

            $rows = [];
            $presentInGroup = 0;
            $expectedInGroup = 0;

            foreach ($unplaced as $oe) {
                $pt = $pointages->get($oe->id);
                $row = $this->buildEmployeeRow($oe, $pt, $tz, $isWorkingDay, $deadline);
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
                'presence_ratio' => [
                    'present' => $presentInGroup,
                    'expected' => $expectedInGroup,
                ],
                'rows' => $rows,
            ];
        }

        $stats = $this->recomputeGlobalStats($orderEmployees, $pointages, $securityGroups, $groupConfigs, $dateStr, $dow, $tz);

        return response()->json([
            'date' => $dateStr,
            'order_id' => $order->id,
            'stats' => $stats,
            'groups' => $resultGroups,
        ]);
    }

    /**
     * Export pointage : CSV (compatible Excel, UTF-8 BOM) ou PDF.
     *
     * Query : order_id, date (ancre), period (day|week|month|quarter|semester|year),
     * group_index (optionnel, index du groupe de sécurité), ungrouped=1 (non classés),
     * format=csv|pdf (défaut csv).
     */
    public function export(Request $request): Response
    {
        $user = $request->user();
        if (!$user || $user->role !== 'business_admin') {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        $validated = $request->validate([
            'order_id' => 'required|integer|exists:orders,id',
            'period' => 'nullable|string|in:day,week,month,quarter,semester,year',
            'group_index' => 'nullable|integer|min:0',
            'ungrouped' => 'nullable|boolean',
            'date' => 'nullable|date_format:Y-m-d',
            'format' => 'nullable|string|in:csv,pdf',
        ]);

        $order = Order::findOrFail($validated['order_id']);
        if (!$this->canAccessOrder($user, $order)) {
            return response()->json(['message' => 'Accès refusé à cette commande'], 403);
        }

        $tz = config('app.timezone');
        $anchorStr = $validated['date'] ?? Carbon::today($tz)->toDateString();
        $anchor = Carbon::parse($anchorStr, $tz)->startOfDay();
        $period = $validated['period'] ?? 'day';
        $format = $validated['format'] ?? 'csv';

        [$from, $to] = $this->resolvePeriodRange($period, $anchor, $tz);

        $ungrouped = filter_var($request->input('ungrouped', false), FILTER_VALIDATE_BOOLEAN);
        $groupIndex = isset($validated['group_index']) && $validated['group_index'] !== null
            ? (int) $validated['group_index']
            : null;

        $employees = $this->filterOrderEmployeesForExport($order, $groupIndex, $ungrouped);
        if ($employees->isEmpty()) {
            return response()->json(['message' => 'Aucun employé ne correspond au filtre de groupe.'], 422);
        }

        $securityGroups = is_array($order->security_groups) ? $order->security_groups : [];
        $groupConfigs = is_array($order->group_security_configs) ? $order->group_security_configs : [];

        $rows = $this->buildExportRows(
            $employees,
            $securityGroups,
            $groupConfigs,
            $from,
            $to,
            $tz
        );

        $meta = [
            'order_id' => $order->id,
            'order_number' => $order->order_number ?? $order->id,
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'group_filter' => $ungrouped
                ? 'non_classés'
                : ($groupIndex !== null ? ('groupe_index_'.$groupIndex) : 'tous'),
        ];

        $asciiBase = 'pointage_order'.$order->id.'_'.$period.'_'.$from->format('Ymd').'_'.$to->format('Ymd');

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('exports.attendance_pointage', [
                'meta' => $meta,
                'rows' => $rows,
            ])->setPaper('a4', 'landscape');

            return $pdf->download($asciiBase.'.pdf');
        }

        return $this->streamCsvExport($rows, $asciiBase.'.csv');
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolvePeriodRange(string $period, Carbon $anchor, string $tz): array
    {
        $d = $anchor->copy()->timezone($tz)->startOfDay();

        return match ($period) {
            'week' => [
                $d->copy()->startOfWeek(Carbon::MONDAY)->startOfDay(),
                $d->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay(),
            ],
            'month' => [
                $d->copy()->startOfMonth()->startOfDay(),
                $d->copy()->endOfMonth()->endOfDay(),
            ],
            'quarter' => $this->quarterBounds($d),
            'semester' => $d->month <= 6
                ? [
                    Carbon::create($d->year, 1, 1, 0, 0, 0, $tz),
                    Carbon::create($d->year, 6, 30, 23, 59, 59, $tz),
                ]
                : [
                    Carbon::create($d->year, 7, 1, 0, 0, 0, $tz),
                    Carbon::create($d->year, 12, 31, 23, 59, 59, $tz),
                ],
            'year' => [
                Carbon::create($d->year, 1, 1, 0, 0, 0, $tz),
                Carbon::create($d->year, 12, 31, 23, 59, 59, $tz),
            ],
            default => [$d->copy()->startOfDay(), $d->copy()->endOfDay()],
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function quarterBounds(Carbon $d): array
    {
        $tz = $d->timezone->getName();
        $q = (int) ceil($d->month / 3);
        $startMonth = ($q - 1) * 3 + 1;
        $start = Carbon::create($d->year, $startMonth, 1, 0, 0, 0, $tz);
        $end = $start->copy()->addMonths(3)->subDay()->endOfDay();

        return [$start, $end];
    }

    /**
     * @return Collection<int, OrderEmployee>
     */
    private function filterOrderEmployeesForExport(Order $order, ?int $groupIndex, bool $ungroupedOnly): Collection
    {
        $all = OrderEmployee::where('order_id', $order->id)
            ->with(['employee:id,name,email'])
            ->orderBy('id')
            ->get();

        $securityGroups = is_array($order->security_groups) ? $order->security_groups : [];
        $known = [];
        foreach ($securityGroups as $g) {
            $t = trim((string) $g);
            if ($t !== '') {
                $known[$t] = true;
            }
        }

        if ($ungroupedOnly) {
            return $all->filter(function (OrderEmployee $oe) use ($known) {
                $eg = trim((string) $oe->employee_group);

                return $eg === '' || !isset($known[$eg]);
            })->values();
        }

        if ($groupIndex !== null) {
            $name = $securityGroups[$groupIndex] ?? null;
            $name = is_string($name) ? trim($name) : '';

            if ($name === '') {
                return collect();
            }

            return $all->filter(fn (OrderEmployee $oe) => trim((string) $oe->employee_group) === $name)->values();
        }

        return $all->values();
    }

    /**
     * @param  Collection<int, OrderEmployee>  $employees
     * @return list<array<string, mixed>>
     */
    private function buildExportRows(
        Collection $employees,
        array $securityGroups,
        array $groupConfigs,
        Carbon $from,
        Carbon $to,
        string $tz
    ): array {
        $ids = $employees->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $pointages = OrderEmployeePointage::query()
            ->whereIn('order_employee_id', $ids)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->with(['orderEmployee.employee:id,name,email'])
            ->orderBy('work_date')
            ->orderBy('order_employee_id')
            ->get();

        $byId = $employees->keyBy('id');
        $out = [];

        foreach ($pointages as $pt) {
            $oe = $pt->orderEmployee;
            if (!$oe || !isset($byId[$oe->id])) {
                continue;
            }

            $workDateStr = $pt->work_date instanceof Carbon
                ? $pt->work_date->timezone($tz)->toDateString()
                : Carbon::parse((string) $pt->work_date, $tz)->toDateString();

            $dow = (int) Carbon::parse($workDateStr, $tz)->format('N');
            [$cfg, $isWorkingDay] = $this->resolveConfigForEmployee($oe, $securityGroups, $groupConfigs, $dow);
            $deadline = $this->groupDeadlineCarbon($workDateStr, $cfg, $tz);
            $row = $this->buildEmployeeRow($oe, $pt, $tz, $isWorkingDay, $deadline);

            $groupe = trim((string) $oe->employee_group) ?: '—';
            $gpsIn = $this->formatGpsPair($row['gps']['check_in'] ?? null);
            $gpsOut = $this->formatGpsPair($row['gps']['check_out'] ?? null);

            $out[] = [
                'date' => $workDateStr,
                'groupe' => $groupe,
                'nom' => $row['full_name'],
                'email' => $row['email'],
                'matricule' => $row['matricule'] ?? '',
                'arrivee' => $row['arrival'] ?? '',
                'depart' => $row['departure'] ?? '',
                'duree_min' => $pt->duration_minutes ?? '',
                'statut' => $this->statusLabelFr($row['status']),
                'retard' => $row['is_late'] ? 'oui' : 'non',
                'gps_entree' => $gpsIn,
                'gps_sortie' => $gpsOut,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $pair
     */
    private function formatGpsPair(?array $pair): string
    {
        if (!$pair || !isset($pair['lat'], $pair['lng'])) {
            return '';
        }

        return number_format((float) $pair['lat'], 6, '.', '').','.number_format((float) $pair['lng'], 6, '.', '');
    }

    private function statusLabelFr(string $status): string
    {
        return match ($status) {
            'a_l_heure' => 'À l\'heure',
            'en_poste' => 'En poste',
            'retard' => 'Retard',
            'absent' => 'Absent',
            'jour_off' => 'Jour non ouvré',
            'non_configure' => 'Non configuré',
            default => $status,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function streamCsvExport(array $rows, string $filename): StreamedResponse
    {
        $headers = [
            'Date',
            'Groupe',
            'Nom',
            'Email',
            'Matricule',
            'Arrivée',
            'Départ',
            'Durée (min)',
            'Statut',
            'Retard',
            'GPS entrée',
            'GPS sortie',
        ];

        return response()->streamDownload(function () use ($rows, $headers) {
            echo "\xEF\xBB\xBF";
            $sep = ';';
            echo $this->csvLine($headers, $sep)."\r\n";
            foreach ($rows as $r) {
                $dep = isset($r['depart']) && (string) $r['depart'] !== '' ? (string) $r['depart'] : '--:--';
                $line = [
                    (string) $r['date'],
                    (string) $r['groupe'],
                    (string) $r['nom'],
                    (string) $r['email'],
                    (string) ($r['matricule'] ?? ''),
                    (string) ($r['arrivee'] ?? ''),
                    $dep,
                    (string) ($r['duree_min'] ?? ''),
                    (string) $r['statut'],
                    (string) $r['retard'],
                    (string) $r['gps_entree'],
                    (string) $r['gps_sortie'],
                ];
                echo $this->csvLine($line, $sep)."\r\n";
            }
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function escapeCsvCell(string $value): string
    {
        if (strpbrk($value, ";\"\n\r") !== false) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }

    /**
     * @param  list<string>  $cells
     */
    private function csvLine(array $cells, string $sep): string
    {
        $parts = [];
        foreach ($cells as $c) {
            $parts[] = $this->escapeCsvCell((string) $c);
        }

        return implode($sep, $parts);
    }

    private function recomputeGlobalStats($orderEmployees, $pointages, array $securityGroups, array $groupConfigs, string $dateStr, int $dow, string $tz): array
    {
        $stats = [
            'total_employees' => $orderEmployees->count(),
            'present' => 0,
            'late' => 0,
            'missing_checkout' => 0,
        ];

        foreach ($orderEmployees as $oe) {
            $pt = $pointages->get($oe->id);
            if (!$pt || !$pt->check_in_time) {
                continue;
            }

            ++$stats['present'];

            [$cfg, $isWorkingDay] = $this->resolveConfigForEmployee($oe, $securityGroups, $groupConfigs, $dow);
            if ($isWorkingDay) {
                $deadline = $this->groupDeadlineCarbon($dateStr, $cfg, $tz);
                $checkIn = $pt->check_in_time instanceof Carbon
                    ? $pt->check_in_time->copy()->timezone($tz)
                    : Carbon::parse($pt->check_in_time, $tz);

                if ($checkIn->gt($deadline)) {
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
            ],
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
        Carbon $deadline
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
            $late = $checkIn->gt($deadline);
        }

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
