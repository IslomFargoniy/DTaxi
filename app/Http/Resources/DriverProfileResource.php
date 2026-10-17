<?php

namespace App\Http\Resources;

use App\Models\Driver;
use App\Models\Tariff;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

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
            'user_id' => $this->user_id,
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
            // Ilova uchun server sozlamalari: ilovaga faqat server manzili yoziladi, qolgani shu yerdan
            'config' => [
                'map_style' => route('map.style', ['lang' => $this->user->locale]),
                'reverb' => [
                    'key' => config('broadcasting.connections.reverb.key'),
                    'host' => config('reverb.apps.apps.0.options.host') ?: $request->getHost(),
                    'port' => (int) (config('reverb.apps.apps.0.options.port') ?: 443),
                    'scheme' => config('reverb.apps.apps.0.options.scheme') ?: 'https',
                ],
                'location_interval_seconds' => 4,
                'offer_seconds' => (int) $settings->get('dispatch.step_seconds', $this->branch_id),
                'service_area' => $this->serviceArea(),
            ],
            'tariffs' => Tariff::query()->withoutGlobalScopes()->where('branch_id', $this->branch_id)->with('tariffClass')->get()
                ->map(fn (Tariff $tariff) => [
                    'tariff_class_id' => $tariff->tariff_class_id,
                    'name' => $tariff->tariffClass->name,
                    ...$tariff->snapshot(),
                ]),
        ];
    }

    /**
     * Taksometr shahar ichi/tashqarisini ajratishi uchun soddalashtirilgan xizmat hududi (GeoJSON, qaror #33).
     *
     * @return array<string, mixed>|null
     */
    private function serviceArea(): ?array
    {
        $json = DB::scalar(
            'SELECT ST_AsGeoJSON(ST_SimplifyPreserveTopology(service_area::geometry, 0.0005), 5) FROM branches WHERE id = ?',
            [$this->branch_id],
        );

        return is_string($json) ? json_decode($json, true) : null;
    }
}
