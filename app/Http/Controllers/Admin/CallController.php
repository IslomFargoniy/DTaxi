<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Call;
use App\Models\Client;
use App\Models\SipTrunk;
use App\Models\User;
use App\Services\Telephony\ChannelLimits;
use App\Services\Telephony\OperatorPool;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Qo'ng'iroqlar (§5.5): jonli monitoring, tarix (filtr + yozuvni tinglash), statistika.
 * Filial admini faqat o'z filiallarini ko'radi (BelongsToBranch).
 */
class CallController extends Controller
{
    public function index(Request $request, OperatorPool $pool, ChannelLimits $limits): Response
    {
        /** @var User $user */
        $user = $request->user();
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        // Sanalar filial vaqt zonasida, bazada UTC
        $tz = Branch::query()->whereIn('id', $user->isSuperadmin() ? Branch::query()->select('id') : $user->branchIds())->value('timezone') ?? 'Asia/Tashkent';
        $from = ($request->date('from', tz: $tz) ?? today($tz))->startOfDay()->utc();
        $to = ($request->date('to', tz: $tz) ?? today($tz))->endOfDay()->utc();

        $history = Call::query()
            ->whereBetween('started_at', [$from, $to])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')->toString()))
            ->when($request->filled('operator_id'), fn ($q) => $q->where('operator_id', $request->integer('operator_id')))
            ->when($request->filled('phone'), function ($q) use ($request) {
                $digits = Client::phoneSearchDigits($request->string('phone')->toString());
                $q->where(fn ($w) => $w->where('caller', 'like', "%{$digits}%")->orWhere('callee', 'like', "%{$digits}%"));
            })
            ->with(['operator:id,name', 'client:id,name'])
            ->latest('started_at')
            ->paginate(50)
            ->withQueryString();

        $operators = User::query()->role([Role::Operator->value, Role::Admin->value])
            ->when(! $user->isSuperadmin(), fn ($q) => $q->where(fn ($w) => $w->whereIn('branch_id', $user->branchIds())
                ->orWhereHas('branches', fn ($b) => $b->whereIn('branches.id', $user->branchIds()))))
            ->whereNotNull('sip_extension')
            ->orderBy('name')
            ->get(['id', 'name', 'sip_extension', 'operator_status', 'operator_status_at']);
        $online = rescue(fn () => $pool->onlineExtensions(), [], false);
        $busy = $pool->busyOperatorIds();

        return Inertia::render('admin/calls', [
            'calls' => $history->through(fn (Call $call) => $this->row($call)),
            'filters' => [
                'from' => $from->copy()->setTimezone($tz)->toDateString(),
                'to' => $to->copy()->setTimezone($tz)->toDateString(),
                'status' => $request->string('status')->toString(),
                'kind' => $request->string('kind')->toString(),
                'operator_id' => $request->string('operator_id')->toString(),
                'phone' => $request->string('phone')->toString(),
            ],
            'stats' => $this->stats($from, $to, $tz),
            'live' => [
                'calls' => Call::query()->whereIn('status', Call::ACTIVE)->with(['operator:id,name', 'client:id,name'])->latest('started_at')->get()->map(fn (Call $c) => $this->row($c)),
                'operators' => $operators->map(fn (User $o) => [
                    'id' => $o->id,
                    'name' => $o->name,
                    'status' => in_array($o->id, $busy, true) ? 'busy' : $o->operator_status,
                    'registered' => in_array($o->sip_extension, $online, true),
                    'since' => $o->operator_status_at?->toIso8601String(),
                ]),
                'trunks' => SipTrunk::query()->where('is_active', true)->get()->map(fn (SipTrunk $t) => [
                    'id' => $t->id,
                    'title' => $t->title,
                    'active' => $limits->active($t)['total'],
                    'max' => $t->max_channels,
                ]),
                'daemon' => Cache::get('ari:listen:heartbeat') !== null,
            ],
            'operators' => $operators->map(fn (User $o) => ['id' => $o->id, 'name' => $o->name]),
            'branchIds' => $user->isSuperadmin() ? null : $user->branchIds(),
        ]);
    }

    /**
     * Yozuv faqat avtorizatsiyadan o'tgan admin uchun Laravel orqali (to'g'ridan-to'g'ri URL yo'q).
     */
    public function recording(Call $call): StreamedResponse
    {
        abort_unless($call->recording_path && Storage::exists($call->recording_path), 404);

        return Storage::response($call->recording_path, "call-{$call->id}.mp3", ['Content-Type' => 'audio/mpeg']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Call $call): array
    {
        return [
            'id' => $call->id,
            'branch_id' => $call->branch_id,
            'direction' => $call->direction,
            'kind' => $call->kind,
            'caller' => $call->caller,
            'callee' => $call->callee,
            'status' => $call->status,
            'operator' => $call->operator?->name,
            'client' => $call->client?->name,
            'order_id' => $call->order_id,
            'attempt' => $call->attempt,
            'started_at' => $call->started_at->toIso8601String(),
            'answered_at' => $call->answered_at?->toIso8601String(),
            'wait_seconds' => $call->wait_seconds,
            'talk_seconds' => $call->talk_seconds,
            'has_recording' => (bool) $call->recording_path,
            'reason' => data_get($call->meta, 'reason'),
        ];
    }

    /**
     * Statistika: kiruvchi javob berilgan/javobsiz, o'rtacha kutish va suhbat, liniya bandligi sababli yo'qotilganlar,
     * soatlar bo'yicha yuklama, operatorlar bo'yicha.
     *
     * @return array<string, mixed>
     */
    private function stats(CarbonInterface $from, CarbonInterface $to, string $tz): array
    {
        $inbound = Call::query()->whereBetween('started_at', [$from, $to])->where('kind', 'client')->where('direction', 'inbound');

        $totals = (clone $inbound)->selectRaw("
            COUNT(*) AS total,
            COUNT(*) FILTER (WHERE status = 'completed') AS answered,
            COUNT(*) FILTER (WHERE status = 'missed') AS missed,
            COUNT(*) FILTER (WHERE status = 'rejected') AS rejected,
            ROUND(AVG(wait_seconds) FILTER (WHERE status = 'completed')) AS avg_wait,
            ROUND(AVG(talk_seconds) FILTER (WHERE status = 'completed')) AS avg_talk
        ")->toBase()->first();

        $hourly = (clone $inbound)
            ->selectRaw("EXTRACT(HOUR FROM started_at AT TIME ZONE 'UTC' AT TIME ZONE ?) AS hour, COUNT(*) AS total, COUNT(*) FILTER (WHERE status <> 'completed') AS lost", [$tz])
            ->groupBy('hour')->orderBy('hour')->toBase()->get()
            ->map(fn ($r) => ['hour' => (int) $r->hour, 'total' => (int) $r->total, 'lost' => (int) $r->lost]);

        $byOperator = Call::query()->whereBetween('started_at', [$from, $to])
            ->whereNotNull('operator_id')->whereIn('status', ['completed'])
            ->selectRaw('operator_id, COUNT(*) AS calls, ROUND(AVG(talk_seconds)) AS avg_talk, SUM(talk_seconds) AS total_talk')
            ->groupBy('operator_id')->with('operator:id,name')->get()
            ->map(fn (Call $r) => [
                'operator' => $r->operator?->name,
                'calls' => (int) $r->getAttribute('calls'),
                'avg_talk' => (int) $r->getAttribute('avg_talk'),
                'total_talk' => (int) $r->getAttribute('total_talk'),
            ]);

        $auto = Call::query()->whereBetween('started_at', [$from, $to])->where('kind', 'tts')
            ->selectRaw("COUNT(*) AS total, COUNT(*) FILTER (WHERE status = 'completed') AS delivered")->toBase()->first();

        return [
            'total' => (int) ($totals->total ?? 0),
            'answered' => (int) ($totals->answered ?? 0),
            'missed' => (int) ($totals->missed ?? 0),
            'rejected' => (int) ($totals->rejected ?? 0),
            'avg_wait' => (int) ($totals->avg_wait ?? 0),
            'avg_talk' => (int) ($totals->avg_talk ?? 0),
            'auto_total' => (int) ($auto->total ?? 0),
            'auto_delivered' => (int) ($auto->delivered ?? 0),
            'hourly' => $hourly,
            'by_operator' => $byOperator,
        ];
    }
}
