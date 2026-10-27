<?php

namespace App\Http\Controllers\Api\Client;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Call;
use App\Models\Order;
use App\Services\Telephony\ClientExtensions;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Mijoz ilovasidan WebRTC qo'ng'iroq (qaror #72): biriktirilgan haydovchiga (faqat faol safar davomida) yoki dispetcherga.
 * Trunk daqiqasi sarflanmaydi, ikki tomonning raqami ham ko'rinmaydi. Ilova qo'ng'iroq sahifasini (WebView) ochadi,
 * sahifa shu yerdan vaqtinchalik SIP ma'lumotlarini olib, darhol qo'ng'iroq qiladi.
 */
class CallController extends Controller
{
    use ResolvesClient;

    public function store(Request $request, ClientExtensions $extensions, Settings $settings): JsonResponse
    {
        $client = $this->client();
        $data = $request->validate(['target' => ['required', Rule::in(ClientExtensions::TARGETS)]]);
        $target = (string) $data['target'];

        if (! $settings->get('calls.client_app_calls') || $client->is_blacklisted) {
            throw ValidationException::withMessages(['target' => __('Calls from the app are not available.')]);
        }
        // Bir vaqtda bitta qo'ng'iroq: ikkinchi oyna birinchisining ruxsatini almashtirib yubormasin
        if (Call::query()->withoutGlobalScopes()->where('client_id', $client->id)->whereNull('ended_at')->where('meta->client_app', true)->exists()) {
            throw ValidationException::withMessages(['target' => __('You are already on a call.')]);
        }

        $order = Order::query()->where('client_id', $client->id)->active()->latest('id')->first();
        if ($target === 'driver' && (! $order?->driver_id
            || ! in_array($order->status, [OrderStatus::Assigned, OrderStatus::Arrived, OrderStatus::InProgress], true))) {
            throw ValidationException::withMessages(['target' => __('The driver can be called only during an active trip.')]);
        }

        $branchId = (int) ($order->branch_id ?? $client->last_branch_id
            ?? Branch::query()->withoutGlobalScopes()->where('is_active', true)->orderBy('id')->value('id')) ?: null;
        if ($target === 'dispatcher' && $branchId && ! Branch::query()->withoutGlobalScopes()->whereKey($branchId)->first()?->isOpen()) {
            throw ValidationException::withMessages(['target' => __('The dispatcher is not available now.')]);
        }

        return response()->json([
            'target' => $target,
            'sip' => $extensions->grant($client, [
                'target' => $target,
                'order_id' => $order?->id,
                'branch_id' => $branchId,
            ], $request->getHost()),
        ]);
    }

    /**
     * Oxirgi ilova qo'ng'irog'i holati: dispetcher navbatida gudok paytida sahifa operator javob berganini shu orqali biladi.
     */
    public function current(): JsonResponse
    {
        $call = Call::query()->withoutGlobalScopes()->where('client_id', $this->client()->id)
            ->where('meta->client_app', true)->where('started_at', '>=', now()->subHours(3))->latest('id')->first();

        return response()->json(['status' => $call?->status]);
    }
}
