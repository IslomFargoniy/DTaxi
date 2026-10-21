<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Rules\UzPhone;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Korporativ mijozlar: chegirma, xodimlar (mijozlar ro'yxatida biriktiriladi) va oylik hisob-faktura.
 */
class CompanyController extends Controller
{
    public function index(): Response
    {
        $monthStart = now()->startOfMonth();

        return Inertia::render('admin/companies', [
            'companies' => Company::query()->with('branch:id,name')->withCount('clients')
                ->withCount(['orders as month_orders' => fn ($q) => $q->where('status', OrderStatus::Completed->value)->where('completed_at', '>=', $monthStart)])
                ->withSum(['orders as month_sum' => fn ($q) => $q->where('status', OrderStatus::Completed->value)->where('completed_at', '>=', $monthStart)], 'price_final')
                ->orderBy('name')->get()
                ->map(fn (Company $c) => [
                    ...$c->only(['id', 'branch_id', 'name', 'contact_phone', 'discount_percent', 'note', 'is_active']),
                    'branch' => $c->branch?->name,
                    'clients_count' => (int) $c->getAttribute('clients_count'),
                    'month_orders' => (int) $c->getAttribute('month_orders'),
                    'month_sum' => (int) $c->getAttribute('month_sum'),
                ]),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            'month' => now()->format('Y-m'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Company::query()->create($this->validated($request));

        return back();
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $company->update($this->validated($request));

        return back();
    }

    /**
     * Oylik hisob-faktura uchun safarlar ro'yxati (CSV, Excel ochadi).
     */
    public function invoice(Request $request, Company $company): StreamedResponse
    {
        $month = $request->string('month')->toString();
        $tz = $company->branch()->value('timezone') ?? 'Asia/Tashkent';
        $from = CarbonImmutable::createFromFormat('!Y-m', preg_match('/^\d{4}-\d{2}$/', $month) ? $month : now($tz)->format('Y-m'), $tz)?->startOfMonth()
            ?? CarbonImmutable::now($tz)->startOfMonth();
        $to = $from->endOfMonth();

        $orders = Order::query()->where('company_id', $company->id)
            ->where('status', OrderStatus::Completed->value)
            ->whereBetween('completed_at', [$from->utc(), $to->utc()])
            ->with(['client:id,phone,name', 'driver:id,callsign'])
            ->orderBy('completed_at')->get();

        return response()->streamDownload(function () use ($orders, $tz) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['#', __('Date'), __('Client'), __('Phone'), __('Pickup'), __('Destination'), __('Driver'), 'km', __('Price')], ';');
            foreach ($orders as $order) {
                fputcsv($out, [
                    $order->id,
                    $order->completed_at?->setTimezone($tz)->format('d.m.Y H:i'),
                    $order->client->name,
                    $order->client->phone,
                    $order->pickup_text,
                    $order->dropoff_text,
                    $order->driver?->callsign,
                    round(($order->distance_m ?? 0) / 1000, 1),
                    $order->price_final,
                ], ';');
            }
            fputcsv($out, ['', '', '', '', '', '', '', __('Total'), $orders->sum('price_final')], ';');
            fclose($out);
        }, "ibtaxi-{$company->id}-{$from->format('Y-m')}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::in($user->isSuperadmin() ? Branch::query()->pluck('id')->all() : $user->branchIds())],
            'name' => ['required', 'string', 'max:150'],
            'contact_phone' => ['nullable', 'string', 'max:25', new UzPhone],
            'discount_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
        $data['contact_phone'] = ! empty($data['contact_phone']) ? Client::normalizePhone($data['contact_phone']) : null;

        return $data;
    }
}
