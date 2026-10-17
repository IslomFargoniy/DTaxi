<?php

namespace App\Http\Resources;

use App\Models\Driver;
use App\Models\Tariff;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Haydovchi ilovasi uchun profil: mashina, balans va taksometr uchun tariflar (formula ilovada ham bir xil).
 *
 * @mixin Driver
 */
class DriverProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $settings = app(Settings::class);
        $vehicle = $this->vehicle;

        return [
            'id' => $this->id,
            'callsign' => $this->callsign,
            'name' => $this->user->name,
            'phone' => $this->user->phone,
            'locale' => $this->user->locale,
            'status' => $this->status->value,
            'balance' => $this->balance,
            'min_balance' => (int) $settings->get('drivers.min_balance', $this->branch_id),
            'rating' => $this->rating,
            'is_approved' => $this->is_approved,
            'blocked_until' => $this->blocked_until?->toIso8601String(),
            'vehicle' => $vehicle ? [
                'brand' => $vehicle->brand,
                'model' => $vehicle->model,
                'color' => $vehicle->color,
                'plate_number' => $vehicle->plate_number,
                'tariff_class_ids' => $vehicle->tariffClasses()->withoutGlobalScopes()->pluck('tariff_classes.id'),
            ] : null,
            'tariffs' => Tariff::query()->withoutGlobalScopes()->where('branch_id', $this->branch_id)->with('tariffClass')->get()
                ->map(fn (Tariff $tariff) => [
                    'tariff_class_id' => $tariff->tariff_class_id,
                    'name' => $tariff->tariffClass->name,
                    ...$tariff->snapshot(),
                ]),
        ];
    }
}
