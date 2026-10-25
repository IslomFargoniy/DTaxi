<?php

namespace App\Http\Controllers\Api\Client;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClientOrderResource;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Order;
use App\Models\TariffClass;
use App\Services\Orders\OrderService;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Mijoz ilovasi (qaror #71): narx, buyurtma berish, kuzatish, bekor qilish, baho, tarix.
 * Mijoz faqat o'z buyurtmalarini ko'radi (filial chegarasi Client'ga qo'llanmaydi — client_id bo'yicha qat'iy).
 */
class OrderController extends Controller
{
    use ResolvesClient;

    private const WITH = ['driver.user', 'vehicle', 'tariffClass'];

    public function __construct(private readonly OrderService $orders) {}

    public function quote(Request $request): JsonResponse
    {
        $client = $this->client();
        $data = $request->validate($this->routeRules());
        [$branch, $pickup, $stops, $dropoff] = $this->route($data);

        return response()->json([
            'branch_id' => $branch->id,
            'tariffs' => $this->orders->quote($branch, $client, $pickup, $stops, $dropoff),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $client = $this->client();
        $data = $request->validate([
            ...$this->routeRules(),
            'pickup.landmark_id' => ['nullable', 'integer', 'exists:landmarks,id'],
            'tariff_class_id' => ['nullable', 'integer'],
            'comment' => ['nullable', 'string', 'max:300'],
        ]);

        if ($client->is_blacklisted) {
            abort(403, __('Ordering from the app is not available. Please call the dispatcher.'));
        }
        // Bir vaqtda bitta faol buyurtma — ikki marta bosilsa ham ikkita mashina chiqmaydi
        $active = Order::query()->where('client_id', $client->id)->active()->with(self::WITH)->latest('id')->first();
        if ($active) {
            return response()->json(['message' => __('You already have an active order.'), 'order' => new ClientOrderResource($active)], 409);
        }

        [$branch, $pickup, $stops, $dropoff] = $this->route($data);
        if (! $branch->isOpen()) {
            throw ValidationException::withMessages(['pickup' => __('The service is closed now.')]);
        }
        $classId = isset($data['tariff_class_id']) ? (int) $data['tariff_class_id'] : null;
        if ($classId && ! TariffClass::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('is_active', true)->whereKey($classId)->exists()) {
            throw ValidationException::withMessages(['tariff_class_id' => __('This tariff is not available here.')]);
        }

        $order = $this->orders->create([
            'branch_id' => $branch->id,
            'phone' => $client->phone,
            'client_name' => $client->name,
            'tariff_class_id' => $classId,
            'pickup_text' => (string) ($data['pickup']['text'] ?? ''),
            'pickup_lat' => $pickup->getLatitude(),
            'pickup_lng' => $pickup->getLongitude(),
            'pickup_landmark_id' => $data['pickup']['landmark_id'] ?? null,
            'dropoff_text' => $data['dropoff']['text'] ?? null,
            'dropoff_lat' => $dropoff?->getLatitude(),
            'dropoff_lng' => $dropoff?->getLongitude(),
            'stops' => $stops,
            'comment' => $data['comment'] ?? null,
            'source' => OrderSource::App,
        ]);

        return response()->json(new ClientOrderResource($order->refresh()->load(self::WITH)), 201);
    }

    /**
     * Faol buyurtma (ilova ochilganda holat tiklanadi) va baholanmagan oxirgi safar (2 soat ichida).
     */
    public function current(Request $request): JsonResponse
    {
        $client = $this->client();
        $active = Order::query()->where('client_id', $client->id)->active()->with(self::WITH)->latest('id')->first();
        $toRate = Order::query()->where('client_id', $client->id)->where('source', OrderSource::App->value)
            ->where('status', OrderStatus::Completed->value)->whereNull('client_rating')
            ->where('completed_at', '>=', now()->subHours(2))->with(self::WITH)->latest('completed_at')->first();

        return response()->json([
            'order' => $active ? new ClientOrderResource($active) : null,
            'to_rate' => $toRate ? new ClientOrderResource($toRate) : null,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $client = $this->client();

        return response()->json(ClientOrderResource::collection(
            Order::query()->where('client_id', $client->id)->with(self::WITH)->latest('id')
                ->when($request->integer('before_id') > 0, fn ($q) => $q->where('id', '<', $request->integer('before_id')))
                ->limit(20)->get(),
        ));
    }

    public function cancel(Request $request, int $order): JsonResponse
    {
        $model = $this->own($request, $order);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);

        if (! in_array($model->status, [OrderStatus::New, OrderStatus::Searching, OrderStatus::Assigned, OrderStatus::Arrived], true)) {
            throw ValidationException::withMessages(['order' => __('The order can no longer be cancelled.')]);
        }
        try {
            $this->orders->cancelByClient($model, null, $data['reason'] ?? __('Cancelled by the client in the app'));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['order' => $e->getMessage()]);
        }

        return response()->json(new ClientOrderResource($model->refresh()->load(self::WITH)));
    }

    public function rate(Request $request, int $order): JsonResponse
    {
        $model = $this->own($request, $order);
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5']]);

        if ($model->status !== OrderStatus::Completed) {
            throw ValidationException::withMessages(['order' => __('Only completed trips can be rated.')]);
        }
        $this->orders->rateByClient($model, (int) $data['rating']);

        return response()->json(new ClientOrderResource($model->refresh()->load(self::WITH)));
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function routeRules(): array
    {
        return [
            'pickup' => ['required', 'array'],
            'pickup.lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup.lng' => ['required', 'numeric', 'between:-180,180'],
            'pickup.text' => ['nullable', 'string', 'max:255'],
            'dropoff' => ['nullable', 'array'],
            'dropoff.lat' => ['required_with:dropoff', 'numeric', 'between:-90,90'],
            'dropoff.lng' => ['required_with:dropoff', 'numeric', 'between:-180,180'],
            'dropoff.text' => ['nullable', 'string', 'max:255'],
            'stops' => ['nullable', 'array', 'max:3'],
            'stops.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'stops.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'stops.*.text' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{Branch, Point, list<array{text: string, lat: float, lng: float}>, Point|null}
     */
    private function route(array $data): array
    {
        $pickup = Point::makeGeodetic((float) $data['pickup']['lat'], (float) $data['pickup']['lng']);
        $branch = Branch::serving($pickup)
            ?? throw ValidationException::withMessages(['pickup' => __('This address is outside the service area.')]);
        $dropoff = isset($data['dropoff']['lat'], $data['dropoff']['lng'])
            ? Point::makeGeodetic((float) $data['dropoff']['lat'], (float) $data['dropoff']['lng']) : null;
        $stops = array_values(array_map(fn (array $s) => ['text' => (string) $s['text'], 'lat' => (float) $s['lat'], 'lng' => (float) $s['lng']], $data['stops'] ?? []));

        return [$branch, $pickup, $stops, $dropoff];
    }

    private function own(Request $request, int $order): Order
    {
        return Order::query()->where('client_id', $this->client()->id)->with(self::WITH)->findOrFail($order);
    }
}
