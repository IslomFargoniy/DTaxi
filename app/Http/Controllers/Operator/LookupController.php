<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\Landmark;
use App\Models\Order;
use App\Models\Region;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Operator formasi uchun tezkor so'rovlar: mijoz kartochkasi va mo'ljal qidiruvi.
 */
class LookupController extends Controller
{
    /**
     * Qo'ng'iroq kelganda yoki raqam kiritilganda: mijoz, qora ro'yxat, oxirgi manzillar (reja §3, §10.1).
     */
    public function client(Request $request): JsonResponse
    {
        $phone = Client::normalizePhone($request->validate(['phone' => ['required', 'string', 'max:20']])['phone']);
        $client = Client::query()->where('phone', $phone)->first();

        if (! $client) {
            return response()->json(['client' => null, 'addresses' => [], 'orders' => [], 'dropoffs' => [], 'last_order' => null]);
        }

        return response()->json([
            'client' => [
                'id' => $client->id,
                'phone' => $client->phone,
                'name' => $client->name,
                'note' => $client->note,
                'is_blacklisted' => $client->is_blacklisted,
                'orders_count' => $client->orders_count,
            ],
            'addresses' => $client->addresses()->orderByDesc('last_used_at')->limit(5)->get()
                ->map(fn (ClientAddress $a) => [
                    'id' => $a->id,
                    'label' => $a->label,
                    'text' => $a->text,
                    'landmark_id' => $a->landmark_id,
                    'lat' => $a->location->getLatitude(),
                    'lng' => $a->location->getLongitude(),
                ]),
            // Faol buyurtma ham shu yerda: "mashinam qani?" deb qo'ng'iroq qilganda ikkinchi buyurtma ochilmasin
            'orders' => $client->orders()->with(['driver:id,callsign', 'vehicle'])->latest()->limit(5)->get()
                ->map(fn (Order $o) => [
                    'id' => $o->id,
                    'status' => $o->status->value,
                    'pickup_text' => $o->pickup_text,
                    'dropoff_text' => $o->dropoff_text,
                    'price_final' => $o->price_final,
                    'created_at' => $o->created_at->toIso8601String(),
                    'driver' => $o->driver?->callsign,
                    'vehicle' => $o->vehicle ? trim("{$o->vehicle->color} {$o->vehicle->model} {$o->vehicle->plate_number}") : null,
                ]),
            // Oxirgi borilgan joylar — "qayerga" uchun bir bosishda
            'dropoffs' => $client->orders()->whereNotNull('dropoff_location')->latest()->limit(20)->get()
                ->unique('dropoff_text')->take(5)->values()
                ->map(fn (Order $o) => [
                    'text' => (string) $o->dropoff_text,
                    'lat' => $o->dropoff_location?->getLatitude(),
                    'lng' => $o->dropoff_location?->getLongitude(),
                ]),
            // "Oxirgi buyurtmani takrorlash" — manzillar, to'xtashlar va tarif bir bosishda
            'last_order' => $this->lastOrder($client),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastOrder(Client $client): ?array
    {
        /** @var Order|null $order */
        $order = $client->orders()->latest('id')->first();
        if (! $order) {
            return null;
        }

        return [
            'id' => $order->id,
            'branch_id' => $order->branch_id,
            'tariff_class_id' => $order->tariff_class_id,
            'pickup' => [
                'text' => $order->pickup_text,
                'lat' => $order->pickup_location->getLatitude(),
                'lng' => $order->pickup_location->getLongitude(),
                'landmark_id' => $order->pickup_landmark_id,
            ],
            'dropoff' => $order->dropoff_location ? [
                'text' => (string) $order->dropoff_text,
                'lat' => $order->dropoff_location->getLatitude(),
                'lng' => $order->dropoff_location->getLongitude(),
            ] : null,
            'stops' => $order->stops ?? [],
            'comment' => $order->comment,
        ];
    }

    public function landmarks(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
        ]);

        $near = isset($data['lat'], $data['lng']) ? Point::makeGeodetic((float) $data['lat'], (float) $data['lng']) : null;
        $landmarks = Landmark::query()->search($data['q'], $near)->limit(10)->get();

        return response()->json($landmarks->map(fn (Landmark $l) => [
            'id' => $l->id,
            'name' => $l->name,
            'aliases' => $l->aliases,
            'category' => $l->category,
            'lat' => $l->location->getLatitude(),
            'lng' => $l->location->getLongitude(),
        ]));
    }

    /**
     * Xaritada bosilgan nuqtaning nomi (yaqin uy, mo'ljal yoki ko'cha).
     */
    public function reverse(Request $request): JsonResponse
    {
        $data = $request->validate(['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180']]);

        return response()->json(['name' => Landmark::nameAt(Point::makeGeodetic((float) $data['lat'], (float) $data['lng']))]);
    }

    /**
     * Yangi mo'ljal: operator xaritada bir marta belgilaydi, keyingi safar qidiruvda chiqadi (reja §4).
     */
    public function storeLandmark(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'aliases' => ['nullable', 'array', 'max:10'],
            'aliases.*' => ['string', 'max:150'],
            'category' => ['nullable', 'string', 'max:32'],
            'lat' => ['required', 'numeric', 'between:37,46'],
            'lng' => ['required', 'numeric', 'between:55,74'],
        ]);
        /** @var User $user */
        $user = $request->user();

        $location = Point::makeGeodetic((float) $data['lat'], (float) $data['lng']);
        $landmark = Landmark::query()->create([
            'name' => $data['name'],
            'aliases' => $data['aliases'] ?? [],
            'category' => $data['category'] ?? null,
            'location' => $location,
            'region_id' => Region::query()
                ->whereNotNull('parent_id')
                ->whereRaw('ST_Covers(boundary, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography)', [$data['lng'], $data['lat']])
                ->value('id'),
            'created_by' => $user->id,
        ]);

        return response()->json(['id' => $landmark->id, 'name' => $landmark->name], 201);
    }
}
