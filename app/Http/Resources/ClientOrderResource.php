<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Mijoz ilovasi uchun buyurtma (qaror #71): faqat mijozga kerakli maydonlar — komissiya, operator, haydovchi
 * telefoni va balans kabi ichki ma'lumotlar yo'q (haydovchiga firma raqami orqali qo'ng'iroq qilinadi).
 *
 * @mixin Order
 */
class ClientOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $driver = $this->driver;
        $vehicle = $this->vehicle;
        $withDriver = $driver && in_array($this->status, [OrderStatus::Assigned, OrderStatus::Arrived, OrderStatus::InProgress], true);

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'pickup' => ['text' => $this->pickup_text, 'lat' => $this->pickup_location->getLatitude(), 'lng' => $this->pickup_location->getLongitude()],
            'dropoff' => $this->dropoff_location ? [
                'text' => $this->dropoff_text, 'lat' => $this->dropoff_location->getLatitude(), 'lng' => $this->dropoff_location->getLongitude(),
            ] : null,
            'stops' => $this->stops ?? [],
            'tariff_class' => ['id' => $this->tariff_class_id, 'name' => $this->tariffClass->name],
            'comment' => $this->comment,
            'price_estimate' => $this->price_estimate,
            'price_final' => $this->price_final,
            'driver' => $withDriver ? [
                'name' => $driver->user->name,
                'callsign' => $driver->callsign,
                'rating' => $driver->rating,
                'location' => $driver->last_location ? ['lat' => $driver->last_location->getLatitude(), 'lng' => $driver->last_location->getLongitude()] : null,
            ] : null,
            'vehicle' => $withDriver && $vehicle ? [
                'brand' => $vehicle->brand, 'model' => $vehicle->model, 'color' => $vehicle->color, 'plate' => $vehicle->plate_number,
            ] : null,
            // Safar boshlangach bekor qilinmaydi — haydovchi bilan yakunlanadi
            'can_cancel' => in_array($this->status, [OrderStatus::New, OrderStatus::Searching, OrderStatus::Assigned, OrderStatus::Arrived], true),
            'rating' => $this->client_rating,
            // Operatorning tayyor sabablari kalit (inglizcha matn) sifatida saqlanadi — mijoz tilida
            'cancel_reason' => $this->cancel_reason !== null ? self::translated($this->cancel_reason) : null,
            'created_at' => $this->created_at->toIso8601String(),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'arrived_at' => $this->arrived_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }

    private static function translated(string $key): string
    {
        $text = __($key);

        return is_string($text) ? $text : $key;
    }
}
