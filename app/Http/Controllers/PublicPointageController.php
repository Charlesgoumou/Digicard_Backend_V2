<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderEmployee;
use App\Models\OrderEmployeePointage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Pointage public : arrivée / départ (appareil, polygone, horaires groupe).
 */
class PublicPointageController extends Controller
{
    public const STATUS_NOT_STARTED = 'NOT_STARTED';

    public const STATUS_CHECKED_IN = 'CHECKED_IN';

    public const STATUS_COMPLETED = 'COMPLETED';

    /**
     * État courant du pointage pour le jour calendaire (timezone app).
     *
     * Réponse : day_status ∈ { NOT_STARTED, CHECKED_IN, COMPLETED }
     * - NOT_STARTED : pas d'arrivée enregistrée aujourd'hui
     * - CHECKED_IN : arrivée OK, départ encore possible (polygon renvoyé si pas terminé)
     * - COMPLETED : arrivée + départ enregistrés
     */
    public function verify(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string|max:255',
            'device_uuid' => 'required|string|max:128',
            'device_model' => 'required|string|max:255',
            'order_id' => 'nullable|integer|exists:orders,id',
            'access_token' => 'nullable|string|max:512',
            'short_code' => 'nullable|string|max:64',
        ]);

        $ctx = $this->buildVerifiedContext($validated);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }

        ['orderEmployee' => $orderEmployee, 'cfg' => $cfg, 'polygon' => $polygon] = $ctx;

        return response()->json($this->buildVerifySuccessPayload($orderEmployee, $cfg, $polygon));
    }

    /**
     * Reconnaissance silencieuse : jeton d’enrôlement (localStorage) + UUID appareil + profil public.
     * Ne renvoie les données de pointage que si le créneau horaire groupe est actif (timezone app).
     */
    public function verifyIdentity(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string|max:255',
            'order_id' => 'required|integer|exists:orders,id',
            'emp_auth_token' => 'required|string|max:128',
            'device_uuid' => 'required|string|max:128',
            'device_model' => 'required|string|max:255',
        ]);

        $user = User::where('username', $validated['username'])->first();
        if (! $user || ! in_array((string) $user->role, ['employee', 'business_admin'], true)) {
            return response()->json(['ok' => false, 'code' => 'unauthorized'], 403);
        }

        $orderEmployee = OrderEmployee::where('order_id', $validated['order_id'])
            ->where('employee_id', $user->id)
            ->first();

        if (! $orderEmployee || ! $orderEmployee->is_configured) {
            return response()->json(['ok' => false, 'code' => 'unauthorized'], 403);
        }

        $storedToken = (string) ($orderEmployee->emp_auth_token ?? '');
        if ($storedToken === '' || ! hash_equals($storedToken, $validated['emp_auth_token'])) {
            return response()->json(['ok' => false, 'code' => 'invalid_enrollment'], 403);
        }

        if (! $orderEmployee->device_uuid || $orderEmployee->device_uuid !== $validated['device_uuid']) {
            return response()->json(['ok' => false, 'code' => 'device_mismatch'], 403);
        }

        if (! $this->deviceModelsMatch($orderEmployee->device_model, $validated['device_model'])) {
            return response()->json(['ok' => false, 'code' => 'model_mismatch'], 403);
        }

        $pseudo = [
            'username' => $validated['username'],
            'device_uuid' => $validated['device_uuid'],
            'device_model' => $validated['device_model'],
            'order_id' => $validated['order_id'],
            'access_token' => null,
            'short_code' => null,
        ];

        $ctx = $this->resolvePointageContext($pseudo);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return response()->json(['ok' => false, 'code' => 'pointage_unavailable'], 403);
        }

        ['orderEmployee' => $oe, 'cfg' => $cfg, 'polygon' => $polygon] = $ctx;

        if (! $this->isWithinSchedule($cfg)) {
            return response()->json(['ok' => false, 'code' => 'outside_schedule'], 403);
        }

        $payload = $this->buildVerifySuccessPayload($oe, $cfg, $polygon);
        $payload['within_schedule'] = true;

        return response()->json($payload);
    }

    private function buildVerifySuccessPayload(OrderEmployee $orderEmployee, array $cfg, array $polygon): array
    {
        $dayStatus = $this->resolveDayStatus($orderEmployee);
        $weekdaysNorm = $this->normalizeWeekdayBits($cfg['calendar']['weekdays'] ?? []);
        $payload = [
            'ok' => true,
            'day_status' => $dayStatus,
            'can_check_in' => $dayStatus === self::STATUS_NOT_STARTED,
            'can_check_out' => $dayStatus === self::STATUS_CHECKED_IN,
            'calendar' => [
                'weekdays' => $weekdaysNorm,
                'dailyWindow' => $cfg['calendar']['dailyWindow'] ?? ['start' => '08:00', 'end' => '18:00'],
            ],
        ];

        if ($dayStatus !== self::STATUS_COMPLETED) {
            $payload['polygon'] = $polygon;
        } else {
            $payload['polygon'] = null;
        }

        $todayRow = $this->todayPointage($orderEmployee);
        if ($todayRow?->check_in_time) {
            $payload['check_in_time'] = $todayRow->check_in_time->toIso8601String();
        }
        if ($todayRow?->check_out_time) {
            $payload['check_out_time'] = $todayRow->check_out_time->toIso8601String();
        }
        if ($todayRow?->duration_minutes !== null) {
            $payload['duration_minutes'] = $todayRow->duration_minutes;
        }

        return $payload;
    }

    /**
     * Enregistre l'arrivée (jour courant, dans la zone).
     */
    public function checkIn(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string|max:255',
            'device_uuid' => 'required|string|max:128',
            'device_model' => 'required|string|max:255',
            'order_id' => 'nullable|integer|exists:orders,id',
            'access_token' => 'nullable|string|max:512',
            'short_code' => 'nullable|string|max:64',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $ctx = $this->buildVerifiedContext($validated);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }

        ['orderEmployee' => $orderEmployee, 'cfg' => $cfg, 'polygon' => $polygon] = $ctx;

        if (!$this->isWithinSchedule($cfg)) {
            return response()->json(['ok' => false, 'code' => 'outside_schedule'], 403);
        }

        $ring = $polygon['coordinates'][0] ?? [];
        if (!$this->geoPointInRing((float) $validated['latitude'], (float) $validated['longitude'], $ring)) {
            return response()->json(['ok' => false, 'code' => 'outside_polygon'], 403);
        }

        if ($this->resolveDayStatus($orderEmployee) !== self::STATUS_NOT_STARTED) {
            return response()->json(['ok' => false, 'code' => 'already_checked_in'], 409);
        }

        $workDate = Carbon::today(config('app.timezone'))->toDateString();

        $row = OrderEmployeePointage::firstOrNew([
            'order_employee_id' => $orderEmployee->id,
            'work_date' => $workDate,
        ]);

        if ($row->check_in_time) {
            return response()->json(['ok' => false, 'code' => 'already_checked_in'], 409);
        }

        $row->check_in_time = Carbon::now(config('app.timezone'));
        $row->check_in_lat = round((float) $validated['latitude'], 7);
        $row->check_in_lng = round((float) $validated['longitude'], 7);
        $row->save();

        return response()->json([
            'ok' => true,
            'day_status' => self::STATUS_CHECKED_IN,
            'check_in_time' => $row->check_in_time->toIso8601String(),
        ]);
    }

    /**
     * Pointage de départ (check-out) : même contrôles que l'arrivée
     * (appareil vérifié via buildVerifiedContext, horaire, position dans le polygone).
     * Exige une arrivée aujourd'hui avec check_out_time encore null.
     */
    public function processDeparture(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string|max:255',
            'device_uuid' => 'required|string|max:128',
            'device_model' => 'required|string|max:255',
            'order_id' => 'nullable|integer|exists:orders,id',
            'access_token' => 'nullable|string|max:512',
            'short_code' => 'nullable|string|max:64',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $ctx = $this->buildVerifiedContext($validated);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }

        ['orderEmployee' => $orderEmployee, 'cfg' => $cfg, 'polygon' => $polygon] = $ctx;

        if (!$this->isWithinSchedule($cfg)) {
            return response()->json(['ok' => false, 'code' => 'outside_schedule'], 403);
        }

        $ring = $polygon['coordinates'][0] ?? [];
        if (!$this->geoPointInRing((float) $validated['latitude'], (float) $validated['longitude'], $ring)) {
            return response()->json(['ok' => false, 'code' => 'outside_polygon'], 403);
        }

        $row = $this->todayPointage($orderEmployee);
        if (!$row || !$row->check_in_time) {
            return response()->json(['ok' => false, 'code' => 'no_check_in_today'], 422);
        }
        if ($row->check_out_time) {
            return response()->json(['ok' => false, 'code' => 'already_checked_out'], 409);
        }

        $now = Carbon::now(config('app.timezone'));
        $row->check_out_time = $now;
        $row->check_out_lat = round((float) $validated['latitude'], 7);
        $row->check_out_lng = round((float) $validated['longitude'], 7);
        $row->duration_minutes = (int) $row->check_in_time->diffInMinutes($now);
        $row->save();

        return response()->json([
            'ok' => true,
            'day_status' => self::STATUS_COMPLETED,
            'check_out_time' => $row->check_out_time->toIso8601String(),
            'duration_minutes' => $row->duration_minutes,
        ]);
    }

    /**
     * Scelle l’appareil (empreinte + modèle) pour cette commande — sans session Sanctum (profil public / carte).
     * À appeler avant verify / pointage si aucun device_uuid n’est encore en base.
     */
    public function bindDevice(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string|max:255',
            'device_uuid' => 'required|string|max:128',
            'device_model' => 'required|string|max:255',
            'order_id' => 'nullable|integer|exists:orders,id',
            'access_token' => 'nullable|string|max:512',
            'short_code' => 'nullable|string|max:64',
        ]);

        $ctx = $this->resolvePointageContext($validated);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }

        /** @var OrderEmployee $orderEmployee */
        $orderEmployee = $ctx['orderEmployee'];

        if (!$orderEmployee->device_uuid) {
            $orderEmployee->device_uuid = $validated['device_uuid'];
            $orderEmployee->device_model = $validated['device_model'];
            $orderEmployee->save();

            return response()->json(['ok' => true, 'sealed' => true, 'message' => 'Appareil lié.']);
        }

        if ($orderEmployee->device_uuid !== $validated['device_uuid']) {
            return response()->json([
                'ok' => false,
                'code' => 'device_mismatch',
                'message' => 'Un autre appareil est déjà lié pour cette commande.',
            ], 409);
        }

        $orderEmployee->device_model = $validated['device_model'];
        $orderEmployee->save();

        return response()->json(['ok' => true, 'sealed' => true, 'message' => 'Appareil déjà enregistré.']);
    }

    /**
     * Contexte pointage (employé, zone, horaires) sans vérification d’empreinte.
     *
     * @return array{order: Order, orderEmployee: OrderEmployee, cfg: array, polygon: array}|\Illuminate\Http\JsonResponse
     */
    private function resolvePointageContext(array $validated)
    {
        $user = User::where('username', $validated['username'])->first();
        if (!$user || ! in_array((string) $user->role, ['employee', 'business_admin'], true)) {
            return response()->json(['ok' => false, 'code' => 'not_employee'], 404);
        }

        $order = $this->resolveOrder($validated);
        $ot = $order ? strtolower(trim((string) ($order->order_type ?? ''))) : '';
        if (!$order || !in_array($ot, ['business', 'entreprise', 'enterprise'], true)) {
            return response()->json(['ok' => false, 'code' => 'order_not_found'], 404);
        }

        if ($order->status === 'cancelled') {
            return response()->json(['ok' => false, 'code' => 'order_cancelled'], 400);
        }

        $orderEmployee = OrderEmployee::where('order_id', $order->id)
            ->where('employee_id', $user->id)
            ->first();

        if (!$orderEmployee) {
            return response()->json(['ok' => false, 'code' => 'not_assigned'], 404);
        }

        if (!$orderEmployee->is_configured) {
            return response()->json(['ok' => false, 'code' => 'not_configured'], 403);
        }

        $groupName = trim((string) ($orderEmployee->employee_group ?? ''));
        if ($groupName === '') {
            return response()->json(['ok' => false, 'code' => 'no_group'], 403);
        }

        $cfg = $this->findGroupConfig($order, $groupName);
        if (!$cfg) {
            return response()->json(['ok' => false, 'code' => 'no_group_config'], 403);
        }

        if (empty($cfg['services']['pointage'])) {
            return response()->json(['ok' => false, 'code' => 'pointage_disabled'], 403);
        }

        $polygon = $cfg['geofence']['polygonGeoJson'] ?? null;
        if (!is_array($polygon) || ($polygon['type'] ?? '') !== 'Polygon') {
            return response()->json(['ok' => false, 'code' => 'no_polygon'], 403);
        }

        $ring = $polygon['coordinates'][0] ?? [];
        if (!is_array($ring) || count($ring) < 4) {
            return response()->json(['ok' => false, 'code' => 'invalid_polygon'], 403);
        }

        $weekdays = $this->normalizeWeekdayBits($cfg['calendar']['weekdays'] ?? []);
        if (count($weekdays) < 1) {
            return response()->json(['ok' => false, 'code' => 'no_schedule'], 403);
        }

        $dw = $cfg['calendar']['dailyWindow'] ?? null;
        if (!is_array($dw) || empty($dw['start']) || empty($dw['end'])) {
            return response()->json(['ok' => false, 'code' => 'no_daily_window'], 403);
        }

        return [
            'order' => $order,
            'orderEmployee' => $orderEmployee,
            'cfg' => $cfg,
            'polygon' => $polygon,
        ];
    }

    /**
     * @return array{order: Order, orderEmployee: OrderEmployee, cfg: array, polygon: array}|\Illuminate\Http\JsonResponse
     */
    private function buildVerifiedContext(array $validated)
    {
        $ctx = $this->resolvePointageContext($validated);
        if ($ctx instanceof \Illuminate\Http\JsonResponse) {
            return $ctx;
        }

        $orderEmployee = $ctx['orderEmployee'];

        $serverUuid = $orderEmployee->device_uuid;
        if (!$serverUuid || $serverUuid !== $validated['device_uuid']) {
            return response()->json(['ok' => false, 'code' => 'device_mismatch'], 403);
        }

        if (!$this->deviceModelsMatch($orderEmployee->device_model, $validated['device_model'])) {
            return response()->json(['ok' => false, 'code' => 'model_mismatch'], 403);
        }

        return $ctx;
    }

    private function todayPointage(OrderEmployee $orderEmployee): ?OrderEmployeePointage
    {
        $workDate = Carbon::today(config('app.timezone'))->toDateString();

        return OrderEmployeePointage::where('order_employee_id', $orderEmployee->id)
            ->where('work_date', $workDate)
            ->first();
    }

    private function resolveDayStatus(OrderEmployee $orderEmployee): string
    {
        $p = $this->todayPointage($orderEmployee);
        if (!$p || !$p->check_in_time) {
            return self::STATUS_NOT_STARTED;
        }
        if (!$p->check_out_time) {
            return self::STATUS_CHECKED_IN;
        }

        return self::STATUS_COMPLETED;
    }

    /**
     * Jours 1=lundi … 7=dimanche (ISO), entiers uniques (tolère chaînes JSON).
     *
     * @param  array<mixed>  $weekdays
     * @return array<int, int>
     */
    private function normalizeWeekdayBits(array $weekdays): array
    {
        $out = [];
        foreach ($weekdays as $w) {
            if ($w === '' || $w === null) {
                continue;
            }
            $n = (int) $w;
            if ($n >= 1 && $n <= 7) {
                $out[$n] = $n;
            }
        }

        return array_values($out);
    }

    private function isWithinSchedule(array $cfg): bool
    {
        $now = Carbon::now(config('app.timezone'));
        $dow = (int) $now->format('N');
        $weekdays = $this->normalizeWeekdayBits($cfg['calendar']['weekdays'] ?? []);
        if ($weekdays === [] || ! in_array($dow, $weekdays, true)) {
            return false;
        }

        $start = $cfg['calendar']['dailyWindow']['start'] ?? '00:00';
        $end = $cfg['calendar']['dailyWindow']['end'] ?? '23:59';
        $partsS = explode(':', $start);
        $partsE = explode(':', $end);
        $sh = (int) ($partsS[0] ?? 0);
        $sm = (int) ($partsS[1] ?? 0);
        $eh = (int) ($partsE[0] ?? 23);
        $em = (int) ($partsE[1] ?? 59);
        $mins = $now->hour * 60 + $now->minute;
        $startM = $sh * 60 + $sm;
        $endM = $eh * 60 + $em;

        return $mins >= $startM && $mins <= $endM;
    }

    /**
     * Anneau GeoJSON [lng, lat][].
     */
    private function geoPointInRing(float $lat, float $lng, array $ring): bool
    {
        $inside = false;
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = (float) $ring[$i][0];
            $yi = (float) $ring[$i][1];
            $xj = (float) $ring[$j][0];
            $yj = (float) $ring[$j][1];
            $denom = ($yj - $yi) ?: 1e-9;
            $intersect = (($yi > $lat) !== ($yj > $lat))
                && ($lng < ($xj - $xi) * ($lat - $yi) / $denom + $xi);
            if ($intersect) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    private function resolveOrder(array $validated): ?Order
    {
        if (!empty($validated['order_id'])) {
            return Order::find($validated['order_id']);
        }
        if (!empty($validated['short_code'])) {
            return Order::where('short_code', $validated['short_code'])->first();
        }
        if (!empty($validated['access_token'])) {
            $raw = $validated['access_token'];
            $decoded = urldecode($raw);
            $order = Order::where('access_token', $raw)->where('status', 'validated')->first();
            if (!$order && $decoded !== $raw) {
                $order = Order::where('access_token', $decoded)->where('status', 'validated')->first();
            }

            return $order;
        }

        return null;
    }

    private function findGroupConfig(Order $order, string $groupName): ?array
    {
        return $order->findGroupSecurityConfigByName($groupName);
    }

    private function deviceModelsMatch(?string $stored, string $incoming): bool
    {
        $a = strtolower(trim((string) $stored));
        $b = strtolower(trim($incoming));
        if ($a === '' || $b === '') {
            return true;
        }
        if ($a === $b) {
            return true;
        }
        if (str_contains($a, $b) || str_contains($b, $a)) {
            return true;
        }

        return false;
    }
}
