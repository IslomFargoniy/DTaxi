<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\SipTrunk;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Kengaytirilgan hisobotlar (Bosqich 5): davr va filial bo'yicha buyurtmalar, tushum, haydovchilar, operatorlar,
 * balans to'ldirish manbalari, kanal bandligi. Filial admini faqat o'z filiallarini ko'radi.
 */
class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        [$branchIds, $from, $to, $tz] = $this->scope($request);

        return Inertia::render('admin/reports', [
            'filters' => [
                'from' => $from->setTimezone($tz)->toDateString(),
                'to' => $to->setTimezone($tz)->toDateString(),
                'branch_id' => $request->string('branch_id')->toString(),
            ],
            'branches' => Branch::query()->whereIn('id', $this->allowedBranchIds($request))->orderBy('name')->get(['id', 'name']),
            'daily' => $this->daily($branchIds, $from, $to, $tz),
            'cancellations' => $this->cancellations($branchIds, $from, $to),
            'drivers' => $this->drivers($branchIds, $from, $to),
            'operators' => $this->operators($branchIds, $from, $to),
            'topups' => $this->topups($branchIds, $from, $to),
            'sources' => $this->sources($branchIds, $from, $to),
            'channels' => $this->channels($branchIds, $from, $to, $tz),
        ]);
    }

    /**
     * CSV (Excel'da ochiladi): kunlik ko'rsatkichlar yoki haydovchilar.
     */
    public function export(Request $request, string $report): StreamedResponse
    {
        abort_unless(in_array($report, ['daily', 'drivers'], true), 404);
        [$branchIds, $from, $to, $tz] = $this->scope($request);
        $rows = $report === 'daily' ? $this->daily($branchIds, $from, $to, $tz) : $this->drivers($branchIds, $from, $to, 10_000);
        $name = "ibtaxi-{$report}-{$from->setTimezone($tz)->toDateString()}_{$to->setTimezone($tz)->toDateString()}.csv";

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF"); // Excel uchun UTF-8 BOM
            if ($rows !== []) {
                fputcsv($out, array_keys($rows[0]), ';');
            }
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v), $row), ';');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{0: list<int>, 1: CarbonImmutable, 2: CarbonImmutable, 3: string}
     */
    private function scope(Request $request): array
    {
        $allowed = $this->allowedBranchIds($request);
        $branchIds = $request->filled('branch_id') && in_array($request->integer('branch_id'), $allowed, true)
            ? [$request->integer('branch_id')]
            : $allowed;
        $tz = Branch::query()->whereIn('id', $branchIds)->value('timezone') ?? 'Asia/Tashkent';

        $from = CarbonImmutable::parse($request->string('from')->toString() ?: now($tz)->subDays(6)->toDateString(), $tz)->startOfDay()->utc();
        $to = CarbonImmutable::parse($request->string('to')->toString() ?: now($tz)->toDateString(), $tz)->endOfDay()->utc();

        return [$branchIds, $from, $to, $tz];
    }

    /**
     * @return list<int>
     */
    private function allowedBranchIds(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        return $user->isSuperadmin()
            ? array_values(array_map('intval', Branch::query()->pluck('id')->all()))
            : $user->branchIds();
    }

    /**
     * @param  list<int>  $branchIds
     * @return list<array<string, mixed>>
     */
    private function daily(array $branchIds, CarbonImmutable $from, CarbonImmutable $to, string $tz): array
    {
        return array_values(DB::table('orders')
            ->whereIn('branch_id', $branchIds ?: [0])
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("
                (created_at AT TIME ZONE 'UTC' AT TIME ZONE ?)::date AS day,
                COUNT(*) AS orders,
                COUNT(*) FILTER (WHERE status = 'completed') AS completed,
                COUNT(*) FILTER (WHERE status = 'cancelled_by_client') AS cancelled_client,
                COUNT(*) FILTER (WHERE status = 'cancelled_by_operator') AS cancelled_operator,
                COUNT(*) FILTER (WHERE status = 'no_driver_found') AS no_driver,
                COALESCE(SUM(price_final) FILTER (WHERE status = 'completed'), 0) AS revenue,
                COALESCE(SUM(commission) FILTER (WHERE status = 'completed'), 0) AS commission,
                ROUND(AVG(price_final) FILTER (WHERE status = 'completed')) AS avg_fare,
                ROUND(AVG(EXTRACT(EPOCH FROM assigned_at - created_at)) FILTER (WHERE assigned_at IS NOT NULL AND scheduled_at IS NULL)) AS avg_assign_s
            ", [$tz])
            ->groupBy('day')->orderBy('day')->get()
            ->map(fn ($r) => [
                'day' => (string) $r->day,
                'orders' => (int) $r->orders,
                'completed' => (int) $r->completed,
                'cancelled_client' => (int) $r->cancelled_client,
                'cancelled_operator' => (int) $r->cancelled_operator,
                'no_driver' => (int) $r->no_driver,
                'revenue' => (int) $r->revenue,
                'commission' => (int) $r->commission,
                'avg_fare' => (int) $r->avg_fare,
                'avg_assign_s' => (int) $r->avg_assign_s,
            ])->all());
    }

    /**
     * @param  list<int>  $branchIds
     * @return list<array{reason: string, total: int}>
     */
    private function cancellations(array $branchIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_values(DB::table('orders')
            ->whereIn('branch_id', $branchIds ?: [0])
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('cancel_reason')
            ->selectRaw('cancel_reason AS reason, COUNT(*) AS total')
            ->groupBy('cancel_reason')->orderByDesc('total')->limit(10)->get()
            ->map(fn ($r) => ['reason' => (string) $r->reason, 'total' => (int) $r->total])->all());
    }

    /**
     * @param  list<int>  $branchIds
     * @return list<array<string, mixed>>
     */
    private function drivers(array $branchIds, CarbonImmutable $from, CarbonImmutable $to, int $limit = 20): array
    {
        $cancels = DB::table('order_events')->join('orders', 'orders.id', '=', 'order_events.order_id')
            ->where('order_events.type', 'driver_cancelled')
            ->whereBetween('order_events.created_at', [$from, $to])
            ->join('drivers', 'drivers.user_id', '=', 'order_events.actor_id')
            ->selectRaw('drivers.id AS driver_id, COUNT(*) AS total')->groupBy('drivers.id');

        return array_values(DB::table('orders')
            ->join('drivers', 'drivers.id', '=', 'orders.driver_id')
            ->join('users', 'users.id', '=', 'drivers.user_id')
            ->leftJoinSub($cancels, 'c', 'c.driver_id', '=', 'drivers.id')
            ->whereIn('orders.branch_id', $branchIds ?: [0])
            ->whereBetween('orders.created_at', [$from, $to])
            ->where('orders.status', 'completed')
            ->selectRaw('drivers.callsign, users.name, drivers.rating, COUNT(*) AS trips,
                SUM(orders.price_final) AS revenue, SUM(orders.commission) AS commission,
                SUM(orders.distance_m) AS distance_m, MAX(COALESCE(c.total, 0)) AS cancels')
            ->groupBy('drivers.id', 'drivers.callsign', 'users.name', 'drivers.rating')
            ->orderByDesc('trips')->limit($limit)->get()
            ->map(fn ($r) => [
                'callsign' => (string) $r->callsign,
                'name' => (string) $r->name,
                'trips' => (int) $r->trips,
                'revenue' => (int) $r->revenue,
                'commission' => (int) $r->commission,
                'km' => round(((int) $r->distance_m) / 1000, 1),
                'cancels' => (int) $r->cancels,
                'rating' => (int) $r->rating,
            ])->all());
    }

    /**
     * @param  list<int>  $branchIds
     * @return list<array<string, mixed>>
     */
    private function operators(array $branchIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $calls = DB::table('calls')->whereIn('branch_id', $branchIds ?: [0])->whereBetween('started_at', [$from, $to])
            ->where('status', 'completed')->whereNotNull('operator_id')
            ->selectRaw('operator_id, COUNT(*) AS calls, SUM(talk_seconds) AS talk')->groupBy('operator_id');

        return array_values(DB::table('orders')
            ->join('users', 'users.id', '=', 'orders.operator_id')
            ->leftJoinSub($calls, 'c', 'c.operator_id', '=', 'users.id')
            ->whereIn('orders.branch_id', $branchIds ?: [0])
            ->whereBetween('orders.created_at', [$from, $to])
            ->selectRaw("users.name, COUNT(*) AS orders, COUNT(*) FILTER (WHERE orders.status = 'completed') AS completed,
                MAX(COALESCE(c.calls, 0)) AS calls, MAX(COALESCE(c.talk, 0)) AS talk_seconds")
            ->groupBy('users.id', 'users.name')->orderByDesc('orders')->get()
            ->map(fn ($r) => [
                'name' => (string) $r->name,
                'orders' => (int) $r->orders,
                'completed' => (int) $r->completed,
                'calls' => (int) $r->calls,
                'talk_seconds' => (int) $r->talk_seconds,
            ])->all());
    }

    /**
     * @param  list<int>  $branchIds
     * @return list<array{source: string, count: int, total: int}>
     */
    private function topups(array $branchIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_values(DB::table('driver_transactions')
            ->join('drivers', 'drivers.id', '=', 'driver_transactions.driver_id')
            ->whereIn('drivers.branch_id', $branchIds ?: [0])
            ->where('driver_transactions.type', 'topup')
            ->whereBetween('driver_transactions.created_at', [$from, $to])
            ->selectRaw('driver_transactions.source, COUNT(*) AS count, SUM(driver_transactions.amount) AS total')
            ->groupBy('driver_transactions.source')->get()
            ->map(fn ($r) => ['source' => (string) $r->source, 'count' => (int) $r->count, 'total' => (int) $r->total])
            ->all());
    }

    /**
     * Buyurtma manbalari (operator / IVR, oldindan) va qaysi raqamdan.
     *
     * @param  list<int>  $branchIds
     * @return list<array{label: string, total: int}>
     */
    private function sources(array $branchIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_values(DB::table('orders')
            ->leftJoin('phone_numbers', 'phone_numbers.id', '=', 'orders.phone_number_id')
            ->whereIn('orders.branch_id', $branchIds ?: [0])
            ->whereBetween('orders.created_at', [$from, $to])
            ->selectRaw("orders.source || CASE WHEN orders.scheduled_at IS NOT NULL THEN ' (pre-order)' ELSE '' END
                || COALESCE(' · ' || phone_numbers.number, '') AS label, COUNT(*) AS total")
            ->groupBy('label')->orderByDesc('total')->get()
            ->map(fn ($r) => ['label' => (string) $r->label, 'total' => (int) $r->total])->all());
    }

    /**
     * Kunlik eng yuqori bir vaqtdagi trunk qo'ng'iroqlari va liniya bandligi sababli yo'qotilganlar —
     * Sarkor'dan qo'shimcha kanal kerakmi (§5.3).
     *
     * @param  list<int>  $branchIds
     * @return array{days: list<array{day: string, peak: int, lost: int}>, capacity: int, recommendation: string|null}
     */
    private function channels(array $branchIds, CarbonImmutable $from, CarbonImmutable $to, string $tz): array
    {
        $ids = implode(',', $branchIds ?: [0]);
        $rows = DB::select("
            WITH c AS (
                SELECT started_at, COALESCE(ended_at, started_at) AS ended_at, status, meta FROM calls
                WHERE sip_trunk_id IS NOT NULL AND branch_id IN ({$ids}) AND started_at BETWEEN ? AND ?
            ), ev AS (
                SELECT started_at AS t, 1 AS d FROM c WHERE status <> 'rejected'
                UNION ALL SELECT ended_at, -1 FROM c WHERE status <> 'rejected'
            ), run AS (
                SELECT t, SUM(d) OVER (ORDER BY t, d) AS n FROM ev
            ), peaks AS (
                SELECT (t AT TIME ZONE 'UTC' AT TIME ZONE ?)::date AS day, MAX(n) AS peak FROM run GROUP BY day
            ), lost AS (
                SELECT (started_at AT TIME ZONE 'UTC' AT TIME ZONE ?)::date AS day, COUNT(*) AS lost FROM c
                WHERE status = 'rejected' AND meta->>'reason' = 'channels' GROUP BY day
            )
            SELECT COALESCE(p.day, l.day) AS day, COALESCE(p.peak, 0) AS peak, COALESCE(l.lost, 0) AS lost
            FROM peaks p FULL JOIN lost l ON l.day = p.day ORDER BY day
        ", [$from, $to, $tz, $tz]);

        $days = array_values(array_map(fn ($r) => ['day' => (string) $r->day, 'peak' => (int) $r->peak, 'lost' => (int) $r->lost], $rows));
        $capacity = (int) SipTrunk::query()->where('is_active', true)->sum('max_channels');
        $lost = array_sum(array_column($days, 'lost'));
        $maxPeak = $days === [] ? 0 : max(array_column($days, 'peak'));

        $recommendation = match (true) {
            $capacity === 0 => null,
            $lost > 0 => 'lost',
            $maxPeak >= $capacity => 'full',
            default => null,
        };

        return ['days' => $days, 'capacity' => $capacity, 'recommendation' => $recommendation];
    }
}
