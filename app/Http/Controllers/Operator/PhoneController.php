<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\User;
use App\Services\Telephony\Extensions;
use App\Services\Telephony\OperatorPool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Operator softfoni (§5.5): WebRTC ulanish ma'lumotlari, holat (tayyor/tanaffus), javobsiz qo'ng'iroqlar.
 */
class PhoneController extends Controller
{
    public function show(Request $request, Extensions $extensions): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->sip_extension) {
            $extensions->syncFor($user);
        }

        return response()->json([
            'sip' => $extensions->credentials($user, $request->getHost(), $request->isSecure()),
            'status' => $user->operator_status ?? 'offline',
        ]);
    }

    public function status(Request $request, OperatorPool $pool): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(OperatorPool::STATUSES)]]);
        /** @var User $user */
        $user = $request->user();

        $pool->setStatus($user, $data['status']);

        return response()->json(['status' => $user->operator_status]);
    }

    /**
     * Javobsiz qo'ng'iroqlar (oxirgi 24 soat) — keyin qayta qo'ng'iroq qilinmaganlari.
     */
    public function missed(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $missed = Call::query()
            ->where('kind', 'client')
            ->whereIn('status', ['missed', 'rejected'])
            // Ish vaqtidan tashqari qo'ng'iroqlarga qayta qo'ng'iroq qilinmaydi
            ->whereRaw("coalesce(meta->>'reason', '') <> 'closed'")
            ->where('started_at', '>=', now()->subDay())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('calls as later')
                ->whereColumn('later.started_at', '>', 'calls.started_at')
                ->where(fn ($w) => $w->whereColumn('later.caller', 'calls.caller')->orWhereColumn('later.callee', 'calls.caller'))
                ->whereIn('later.status', ['answered', 'completed']))
            ->with('client:id,name')
            ->latest('started_at')
            ->limit(50)
            ->get()
            ->unique('caller')
            ->values();

        return response()->json($missed->map(fn (Call $call) => [
            'id' => $call->id,
            'phone' => $call->caller,
            'client' => $call->client?->name,
            'branch_id' => $call->branch_id,
            'wait_seconds' => $call->wait_seconds,
            'started_at' => $call->started_at->toIso8601String(),
        ]));
    }
}
