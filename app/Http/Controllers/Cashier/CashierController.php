<?php

namespace App\Http\Controllers\Cashier;

use App\Enums\TransactionSource;
use App\Http\Controllers\Controller;
use App\Jobs\SendPushNotification;
use App\Models\Branch;
use App\Models\CashShift;
use App\Models\Driver;
use App\Models\DriverTransaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Drivers\DriverBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kassir (qaror #25): haydovchi balansini naqd to'ldirish va kassa smenasi.
 */
class CashierController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $cashier */
        $cashier = $request->user();
        $shift = $this->openShift($cashier);

        return Inertia::render('cashier/index', [
            'shift' => $shift ? [
                'id' => $shift->id,
                'opened_at' => $shift->opened_at->toIso8601String(),
                'total_cash' => $shift->total_cash,
                'topups' => DriverTransaction::query()->where('cash_shift_id', $shift->id)->with('driver.user')->latest('created_at')->get()
                    ->map(fn (DriverTransaction $t) => [
                        'id' => $t->id,
                        'callsign' => $t->driver->callsign,
                        'name' => $t->driver->user->name,
                        'amount' => $t->amount,
                        'created_at' => $t->created_at?->toIso8601String(),
                    ]),
            ] : null,
            'history' => CashShift::query()->where('cashier_id', $cashier->id)->whereNotNull('closed_at')->latest('opened_at')->limit(10)->get()
                ->map(fn (CashShift $s) => [
                    'id' => $s->id,
                    'opened_at' => $s->opened_at->toIso8601String(),
                    'closed_at' => $s->closed_at?->toIso8601String(),
                    'total_cash' => $s->total_cash,
                    'confirmed' => $s->admin_confirmed_by !== null,
                ]),
        ]);
    }

    /**
     * Haydovchini pozivnoy, telefon yoki davlat raqami bo'yicha topish.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim($request->validate(['q' => ['required', 'string', 'min:2', 'max:30']])['q']);
        $digits = preg_replace('/\D/', '', $q);

        $drivers = Driver::query()
            ->with(['user', 'vehicle'])
            ->where(fn ($w) => $w
                ->where('callsign', $q)
                ->when(strlen((string) $digits) >= 4, fn ($w) => $w->orWhereHas('user', fn ($u) => $u->where('phone', 'like', "%{$digits}%")))
                ->orWhereHas('vehicles', fn ($v) => $v->where('plate_number', 'like', '%'.Vehicle::normalizePlate($q).'%'))
                ->orWhereHas('user', fn ($u) => $u->where('name', 'ilike', "%{$q}%")))
            ->limit(10)
            ->get();

        return response()->json($drivers->map(fn (Driver $d) => [
            'id' => $d->id,
            'callsign' => $d->callsign,
            'name' => $d->user->name,
            'phone' => $d->user->phone,
            'balance' => $d->balance,
            'vehicle' => $d->vehicle ? trim("{$d->vehicle->color} {$d->vehicle->model} {$d->vehicle->plate_number}") : null,
        ]));
    }

    public function open(Request $request): RedirectResponse
    {
        /** @var User $cashier */
        $cashier = $request->user();

        if (! $this->openShift($cashier)) {
            // Superadmin (yoki filiali biriktirilmagan kassir) — o'ziga ruxsat etilgan birinchi filial
            $branchId = $cashier->branch_id
                ?? ($cashier->isSuperadmin() ? Branch::query()->orderBy('id')->value('id') : ($cashier->branchIds()[0] ?? null));
            abort_if($branchId === null, 422, __('No branch is assigned to you.'));

            CashShift::query()->create(['branch_id' => $branchId, 'cashier_id' => $cashier->id, 'opened_at' => now()]);
        }

        return back();
    }

    public function close(Request $request): RedirectResponse
    {
        /** @var User $cashier */
        $cashier = $request->user();
        $this->openShift($cashier)?->forceFill(['closed_at' => now()])->save();

        return back();
    }

    public function topUp(Request $request, DriverBalance $balance): RedirectResponse
    {
        $data = $request->validate([
            'driver_id' => ['required', 'integer'],
            'amount' => ['required', 'integer', 'min:1000', 'max:10000000'],
        ]);
        /** @var User $cashier */
        $cashier = $request->user();

        $shift = $this->openShift($cashier);
        if (! $shift) {
            return back()->withErrors(['amount' => __('Open a cash shift first.')]);
        }

        $driver = Driver::query()->whereKey($data['driver_id'])->firstOrFail();
        $balance->topUp($driver, (int) $data['amount'], TransactionSource::Cash, $cashier, $shift, __('Cash desk'));

        SendPushNotification::dispatch($driver->user_id, [
            'type' => 'balance.topup',
            'amount' => (int) $data['amount'],
            'balance' => $driver->balance,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Balance topped up: :amount', ['amount' => number_format((int) $data['amount'], 0, '', ' ')])]);

        return back();
    }

    private function openShift(User $cashier): ?CashShift
    {
        return CashShift::query()->where('cashier_id', $cashier->id)->whereNull('closed_at')->latest('opened_at')->first();
    }
}
