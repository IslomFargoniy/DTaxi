<?php

namespace App\Http\Resources;

use App\Enums\IntegrationProvider;
use App\Models\Branch;
use App\Models\Driver;
use App\Models\Integration;
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
        $area = $this->serviceArea();
        $center = Branch::query()->withoutGlobalScopes()->whereKey($this->branch_id)->first()?->center;
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
                // Ilova Reverb'ga API bilan bir xil manzil orqali ulanadi (nginx /app → Reverb).
                // Server sozlamasidagi REVERB_HOST ichki (127.0.0.1) — telefon uchun yaroqsiz
                'reverb' => [
                    'key' => config('broadcasting.connections.reverb.key'),
                    'host' => $request->getHost(),
                    'port' => $request->getPort(),
                    'scheme' => $request->getScheme(),
                ],
                'location_interval_seconds' => 4,
                'offer_seconds' => (int) $settings->get('dispatch.step_seconds', $this->branch_id),
                // Taksometrdagi tungi tarif filial vaqti bo'yicha (telefon soat mintaqasi boshqacha bo'lishi mumkin)
                'timezone' => Branch::query()->withoutGlobalScopes()->whereKey($this->branch_id)->value('timezone'),
                // Hudud chizilmagan bo'lsa maydon yuborilmaydi (eski ilova null'ni o'qiy olmas edi)
                'service_area' => $this->when($area !== null, $area),
                // GPS kelguncha xarita shu yerda ochiladi — firma O'zbekistonning istalgan joyida bo'lishi mumkin
                'map_center' => $this->when($center !== null, fn () => ['lat' => $center?->getLatitude(), 'lng' => $center?->getLongitude()]),
                // Balansni to'ldirish havolalari uchun (maxfiy bo'lmagan) merchant ma'lumotlari; sozlanmagan bo'lsa null
                'payments' => [
                    'click' => ($service = Integration::credential(IntegrationProvider::Click, 'service_id')) ? [
                        'service_id' => $service,
                        'merchant_id' => Integration::credential(IntegrationProvider::Click, 'merchant_id'),
                    ] : null,
                    'payme' => ($merchant = Integration::credential(IntegrationProvider::Payme, 'merchant_id')) ? [
                        'merchant_id' => $merchant,
                    ] : null,
                ],
            ],
            // Klass ham filial scope'isiz: haydovchi akkaunti boshqa filialda qolgan bo'lsa ham profil (taksometr tariflari) yiqilmasin
            'tariffs' => Tariff::query()->withoutGlobalScopes()->where('branch_id', $this->branch_id)
                ->with(['tariffClass' => fn ($q) => $q->withoutGlobalScopes()])->get()
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
