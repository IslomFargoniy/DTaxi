<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Buyurtma holatini o'zgartirishning yagona yo'li: shartli UPDATE (faqat ruxsat etilgan holatdan), keyin bazadan
 * qayta o'qiladi. Parallel jarayonlardan (bekor qilish, qabul, rejalashtiruvchi, qayta efir) faqat bittasi o'tadi —
 * eskirgan model bekor qilingan buyurtmani "qidirilmoqda" ga qaytarib yozmaydi.
 */
final class OrderTransition
{
    /**
     * @param  list<OrderStatus>  $from
     * @param  array<string, mixed>  $attributes  qiymat, enum yoki DB::raw (masalan search_round + 1)
     * @param  (Closure(Builder<Order>): mixed)|null  $where  qo'shimcha shart
     */
    public static function apply(Order $order, array $from, array $attributes, ?Closure $where = null): bool
    {
        $values = [];
        foreach ($attributes as $key => $value) {
            $values[$key] = match (true) {
                $value instanceof BackedEnum => $value->value,
                is_array($value) => json_encode($value, JSON_THROW_ON_ERROR),
                default => $value,
            };
        }

        $updated = Order::query()->withoutGlobalScopes()
            ->whereKey($order->id)
            ->whereIn('status', array_map(fn (OrderStatus $s) => $s->value, $from))
            ->when($where !== null, fn (Builder $q) => $where($q))
            ->update($values);

        $order->refresh();

        return $updated === 1;
    }
}
