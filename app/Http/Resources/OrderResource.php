<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'status' => $this->status->value,
            'source' => $this->source->value,
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'phone' => $this->client->phone,
                'name' => $this->client->name,
            ]),
            'driver' => $this->whenLoaded('driver', fn () => $this->driver ? [
                'id' => $this->driver->id,
                'callsign' => $this->driver->callsign,
                'name' => $this->driver->user->name,
                'phone' => $this->driver->user->phone,
            ] : null),
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle ? [
                'model' => trim($this->vehicle->brand.' '.$this->vehicle->model),
                'color' => $this->vehicle->color,
                'plate_number' => $this->vehicle->plate_number,
            ] : null),
            'tariff_class' => $this->whenLoaded('tariffClass', fn () => [
                'id' => $this->tariffClass->id,
                'name' => $this->tariffClass->name,
                'is_delivery' => $this->tariffClass->is_delivery,
            ]),
            'pickup' => [
                'text' => $this->pickup_text,
                'lat' => $this->pickup_location->getLatitude(),
                'lng' => $this->pickup_location->getLongitude(),
            ],
            'dropoff' => $this->dropoff_location ? [
                'text' => $this->dropoff_text,
                'lat' => $this->dropoff_location->getLatitude(),
                'lng' => $this->dropoff_location->getLongitude(),
            ] : ($this->dropoff_text ? ['text' => $this->dropoff_text, 'lat' => null, 'lng' => null] : null),
            'stops' => $this->stops ?? [],
            'comment' => $this->comment,
            'price_estimate' => $this->price_estimate,
            'price_bonus' => $this->price_bonus,
            // Haydovchi ilovasi taksometri buyurtma shartini (tig'iz soat, chegirma) shu yerdan oladi
            'surge_coeff' => (float) ($this->tariff_snapshot['surge_coeff'] ?? 1),
            'discount_percent' => (int) ($this->tariff_snapshot['discount_percent'] ?? 0),
            'company' => $this->company_id ? $this->company()->withoutGlobalScopes()->value('name') : null,
            'price_final' => $this->price_final,
            'price_breakdown' => $this->price_breakdown,
            'distance_m' => $this->distance_m,
            'search_radius_m' => $this->search_radius_m,
            'searching_at' => $this->searching_at?->toIso8601String(),
            'search_exhausted_at' => $this->search_exhausted_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'arrived_at' => $this->arrived_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
        ];
    }
}
