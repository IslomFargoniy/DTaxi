<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\Landmark;
use App\Models\PhoneNumber;
use App\Support\Settings;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Mijoz ilovasi (qaror #71): profil, ilova sozlamalari, manzil qidiruvi va saqlangan manzillar.
 */
class ProfileController extends Controller
{
    use ResolvesClient;

    /**
     * @return array<string, mixed>
     */
    public static function profile(Client $client): array
    {
        return [
            'id' => $client->id,
            'phone' => $client->phone,
            'name' => $client->name,
            'locale' => $client->locale,
            // Ism hali yo'q — ilova kirishdan keyin so'raydi
            'needs_name' => blank($client->name),
            'config' => [
                'company_name' => app(Settings::class)->get('company.name'),
                // Haydovchi yoki dispetcher bilan aloqa — firma raqami (raqamlar yashirin)
                'support_phone' => PhoneNumber::forBranch($client->last_branch_id)?->number,
                'map_style' => route('map.style', ['lang' => $client->locale]),
                // Ilova Reverb'ga API bilan bir xil manzil orqali ulanadi (nginx /app → Reverb)
                'reverb' => [
                    'key' => config('broadcasting.connections.reverb.key'),
                    'host' => request()->getHost(),
                    'port' => request()->getPort(),
                    'scheme' => request()->getScheme(),
                ],
                // GPS kelguncha xarita shu yerda — firmaning birinchi filiali markazi
                'map_center' => ($center = Branch::query()->withoutGlobalScopes()->where('is_active', true)->whereNotNull('center')->orderBy('id')->value('center')) instanceof Point
                    ? ['lat' => $center->getLatitude(), 'lng' => $center->getLongitude()] : null,
            ],
        ];
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(self::profile($this->client()));
    }

    public function update(Request $request): JsonResponse
    {
        $client = $this->client();
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'locale' => ['sometimes', 'required', Rule::in(SetLocale::LOCALES)],
        ]);
        $client->fill($data)->save();

        return response()->json(self::profile($client));
    }

    /**
     * Manzil qidiruvi — operator panelidagi bilan bir xil (mo'ljallar, ko'chalar, uy raqamlari).
     */
    public function places(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $near = isset($data['lat'], $data['lng']) ? Point::makeGeodetic((float) $data['lat'], (float) $data['lng']) : null;

        return response()->json(Landmark::query()->search($data['q'], $near)->limit(10)->get()->map(fn (Landmark $l) => [
            'landmark_id' => $l->id,
            'text' => $l->name,
            'category' => $l->category,
            'lat' => $l->location->getLatitude(),
            'lng' => $l->location->getLongitude(),
        ]));
    }

    /** Xaritadagi nuqta nomi (pin surilganda) */
    public function reverse(Request $request): JsonResponse
    {
        $data = $request->validate(['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180']]);

        return response()->json(['text' => Landmark::nameAt(Point::makeGeodetic((float) $data['lat'], (float) $data['lng']))]);
    }

    /**
     * Saqlangan ("Uy", "Ish") va ko'p ishlatilgan manzillar.
     */
    public function addresses(Request $request): JsonResponse
    {
        $client = $this->client();

        return response()->json(ClientAddress::query()->where('client_id', $client->id)
            ->orderByRaw('label IS NULL')->orderByDesc('usage_count')->orderByDesc('last_used_at')->limit(15)->get()
            ->map(fn (ClientAddress $a) => self::address($a)));
    }

    public function storeAddress(Request $request): JsonResponse
    {
        $client = $this->client();
        $data = $request->validate([
            'label' => ['required', 'string', 'max:50'],
            'text' => ['required', 'string', 'max:255'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $address = ClientAddress::query()->firstOrNew(['client_id' => $client->id, 'text' => $data['text']]);
        $address->fill(['label' => $data['label'], 'location' => Point::makeGeodetic((float) $data['lat'], (float) $data['lng'])])->save();

        return response()->json(self::address($address), 201);
    }

    public function destroyAddress(Request $request, int $address): Response
    {
        ClientAddress::query()->where('client_id', $this->client()->id)->whereKey($address)->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private static function address(ClientAddress $a): array
    {
        return ['id' => $a->id, 'label' => $a->label, 'text' => $a->text, 'lat' => $a->location->getLatitude(), 'lng' => $a->location->getLongitude()];
    }
}
