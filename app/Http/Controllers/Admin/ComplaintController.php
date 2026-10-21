<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ComplaintType;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Complaint;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shikoyatlar, unutilgan buyumlar va minnatdorchiliklar jurnali (mijoz qo'ng'iroq qilganda operator yozadi).
 */
class ComplaintController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'open';
        $type = $request->string('type')->toString();

        return Inertia::render('admin/complaints', [
            'complaints' => Complaint::query()
                ->with(['client:id,phone,name', 'driver:id,callsign', 'author:id,name'])
                ->when($status !== 'all', fn ($q) => $q->where('status', $status))
                ->when($type !== '', fn ($q) => $q->where('type', $type))
                ->latest()->paginate(50)->withQueryString()
                ->through(fn (Complaint $c) => [
                    'id' => $c->id,
                    'order_id' => $c->order_id,
                    'type' => $c->type->value,
                    'text' => $c->text,
                    'status' => $c->status,
                    'resolution' => $c->resolution,
                    'client' => $c->client ? trim(Client::formatPhone($c->client->phone).' '.$c->client->name) : null,
                    'driver' => $c->driver?->callsign,
                    'author' => $c->author?->name,
                    'created_at' => $c->created_at->toIso8601String(),
                ]),
            'filters' => ['status' => $status, 'type' => $type],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'order_id' => ['nullable', 'integer', 'min:1'],
            'type' => ['required', Rule::enum(ComplaintType::class)],
            'text' => ['required', 'string', 'max:2000'],
        ]);
        /** @var User $user */
        $user = $request->user();

        // Buyurtma filial chegarasi bilan topiladi — boshqa filial buyurtmasiga yozib bo'lmaydi
        $order = isset($data['order_id']) ? Order::query()->whereKey((int) $data['order_id'])->first() : null;
        if (isset($data['order_id']) && $order === null) {
            throw ValidationException::withMessages(['order_id' => __('Order not found.')]);
        }
        $branchId = $order->branch_id ?? $user->branch_id ?? Branch::query()->value('id');

        Complaint::query()->create([
            'branch_id' => $branchId,
            'order_id' => $order?->id,
            'client_id' => $order?->client_id,
            'driver_id' => $order?->driver_id,
            'type' => $data['type'],
            'text' => $data['text'],
            'created_by' => $user->id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved.')]);

        return back();
    }

    public function update(Request $request, Complaint $complaint): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'resolved'])],
            'resolution' => ['nullable', 'string', 'max:2000'],
        ]);

        $complaint->forceFill([
            ...$data,
            'resolved_by' => $data['status'] === 'resolved' ? $request->user()?->id : null,
            'resolved_at' => $data['status'] === 'resolved' ? now() : null,
        ])->save();

        return back();
    }
}
