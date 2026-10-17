<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\DriverStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\DriverTransaction;
use App\Models\TariffClass;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Drivers\DriverBalance;
use App\Services\Telephony\Extensions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Haydovchilar (qaror #26): o'z mashinasi bilan ishlaydigan hamkorlar. Admin ro'yxatdan o'tkazadi,
 * hujjatlarni tekshiradi va tasdiqlaydi — shundan keyingina liniyaga chiqa oladi.
 */
class DriverController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(DriverStatus::class)],
            'pending' => ['nullable', 'boolean'],
        ]);

        $drivers = Driver::query()
            ->with(['user', 'vehicle.tariffClasses:id', 'branch:id,name'])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('callsign', 'ilike', "%{$term}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'ilike', "%{$term}%")->orWhere('phone', 'ilike', "%{$term}%"))
                ->orWhereHas('vehicles', fn ($v) => $v->where('plate_number', 'ilike', '%'.Vehicle::normalizePlate($term).'%'))))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($request->boolean('pending'), fn ($q) => $q->where('is_approved', false))
            ->orderBy('callsign')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('admin/drivers/index', [
            'drivers' => $drivers->through(fn (Driver $d) => $this->row($d)),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('admin/drivers/form', ['driver' => null, ...$this->formOptions($request)]);
    }

    public function edit(Request $request, Driver $driver): Response
    {
        $driver->load(['user', 'vehicle.tariffClasses:id', 'documents']);

        return Inertia::render('admin/drivers/form', [
            'driver' => [
                ...$this->row($driver),
                'license_no' => $driver->license_no,
                'license_expires_at' => $driver->license_expires_at?->toDateString(),
                'email' => $driver->user->email,
                'documents' => $driver->documents->map(fn (DriverDocument $doc) => [
                    'id' => $doc->id,
                    'type' => $doc->type->value,
                    'status' => $doc->status->value,
                    'expires_at' => $doc->expires_at?->toDateString(),
                    'url' => route('admin.drivers.documents.show', [$driver, $doc]),
                ]),
                'transactions' => $driver->transactions()->latest('created_at')->limit(30)->get()->map(fn (DriverTransaction $t) => [
                    'id' => $t->id,
                    'type' => $t->type->value,
                    'source' => $t->source->value,
                    'amount' => $t->amount,
                    'balance_after' => $t->balance_after,
                    'comment' => $t->comment,
                    'created_at' => $t->created_at?->toIso8601String(),
                ]),
            ],
            ...$this->formOptions($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $driver = $this->save($request, null);

        return to_route('admin.drivers.edit', $driver);
    }

    public function update(Request $request, Driver $driver): RedirectResponse
    {
        $this->save($request, $driver);

        return to_route('admin.drivers.edit', $driver);
    }

    /**
     * Tasdiqlash: haydovchi va joriy mashinasi (qaror #26).
     */
    public function approve(Request $request, Driver $driver): RedirectResponse
    {
        $approved = $request->validate(['approved' => ['required', 'boolean']])['approved'];
        $now = $approved ? now() : null;
        $by = $approved ? $request->user()?->id : null;

        $driver->forceFill(['is_approved' => $approved, 'approved_at' => $now, 'approved_by' => $by])->save();
        $driver->vehicle?->forceFill(['is_approved' => $approved, 'approved_at' => $now, 'approved_by' => $by])->save();
        if (! $approved) {
            $driver->forceFill(['status' => DriverStatus::Offline])->save();
        }

        return back();
    }

    public function block(Request $request, Driver $driver): RedirectResponse
    {
        $minutes = $request->validate(['minutes' => ['required', 'integer', 'min:0', 'max:525600']])['minutes'];

        $driver->forceFill([
            'blocked_until' => $minutes > 0 ? now()->addMinutes($minutes) : null,
            'status' => $minutes > 0 && $driver->status === DriverStatus::Free ? DriverStatus::Offline : $driver->status,
        ])->save();

        return back();
    }

    public function adjustBalance(Request $request, Driver $driver, DriverBalance $balance): RedirectResponse
    {
        abort_unless($request->user()?->can('balance.adjust'), 403);
        $data = $request->validate([
            'amount' => ['required', 'integer', 'not_in:0', 'between:-10000000,10000000'],
            'comment' => ['required', 'string', 'max:255'],
        ]);
        /** @var User $user */
        $user = $request->user();

        $balance->adjust($driver, (int) $data['amount'], $user, $data['comment']);

        return back();
    }

    public function uploadDocument(Request $request, Driver $driver): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(DocumentType::class)],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $driver->documents()->create([
            'type' => $data['type'],
            'file_path' => $request->file('file')?->store("drivers/{$driver->id}/documents"),
            'expires_at' => $data['expires_at'] ?? null,
            'status' => DocumentStatus::Pending,
        ]);

        return back();
    }

    public function reviewDocument(Request $request, Driver $driver, DriverDocument $document): RedirectResponse
    {
        abort_unless($document->driver_id === $driver->id, 404);
        $status = $request->validate(['status' => ['required', Rule::enum(DocumentStatus::class)]])['status'];

        $document->forceFill(['status' => $status])->save();

        return back();
    }

    public function showDocument(Driver $driver, DriverDocument $document): StreamedResponse
    {
        abort_unless($document->driver_id === $driver->id, 404);

        return Storage::response($document->file_path);
    }

    private function save(Request $request, ?Driver $driver): Driver
    {
        /** @var User $actor */
        $actor = $request->user();
        $branchIds = $this->branchIds($actor);
        $userId = $driver?->user_id;
        $vehicleId = $driver?->vehicle?->id;

        $data = $request->validate([
            'branch_id' => ['required', Rule::in($branchIds)],
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20'],
            'password' => [$driver ? 'nullable' : 'required', Password::min(6)],
            'callsign' => ['required', 'string', 'max:16', Rule::unique('drivers')->ignore($driver?->id)],
            'license_no' => ['nullable', 'string', 'max:50'],
            'license_expires_at' => ['nullable', 'date'],
            'vehicle.brand' => ['required', 'string', 'max:50'],
            'vehicle.model' => ['required', 'string', 'max:50'],
            'vehicle.color' => ['required', 'string', 'max:30'],
            'vehicle.plate_number' => ['required', 'string', 'max:16'],
            'vehicle.year' => ['nullable', 'integer', 'between:1980,2100'],
            'vehicle.tech_passport_no' => ['nullable', 'string', 'max:50'],
            'vehicle.tariff_class_ids' => ['array'],
            'vehicle.tariff_class_ids.*' => [Rule::exists('tariff_classes', 'id')->where('branch_id', $request->integer('branch_id'))],
        ]);

        $phone = Client::normalizePhone($data['phone']);
        $plate = Vehicle::normalizePlate($data['vehicle']['plate_number']);
        validator(['phone' => $phone, 'plate' => $plate], [
            'phone' => [Rule::unique('users', 'phone')->ignore($userId)],
            'plate' => [Rule::unique('vehicles', 'plate_number')->ignore($vehicleId)],
        ])->validate();

        return DB::transaction(function () use ($data, $driver, $phone, $plate) {
            $user = $driver !== null ? $driver->user : new User(['email' => "driver-{$phone}@drivers.local"]);
            $user->fill(['name' => $data['name'], 'phone' => $phone, 'branch_id' => $data['branch_id']]);
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
            $user->syncRoles(Role::Driver);

            $driver ??= new Driver(['user_id' => $user->id, 'status' => DriverStatus::Offline]);
            $driver->fill([
                'branch_id' => $data['branch_id'],
                'callsign' => $data['callsign'],
                'license_no' => $data['license_no'] ?? null,
                'license_expires_at' => $data['license_expires_at'] ?? null,
            ])->save();

            $vehicle = $driver->vehicle;
            // Davlat raqami o'zgarsa — yangi mashina, qayta tasdiqlanadi; eski buyurtmalarda eskisi qoladi
            if ($vehicle && $vehicle->plate_number !== $plate) {
                $vehicle->forceFill(['is_current' => false])->save();
                $vehicle = null;
            }
            $vehicle ??= new Vehicle(['driver_id' => $driver->id, 'is_current' => true, 'is_approved' => false]);
            $vehicle->fill([...$data['vehicle'], 'plate_number' => $plate])->save();
            $vehicle->tariffClasses()->sync($data['vehicle']['tariff_class_ids'] ?? []);

            app(Extensions::class)->syncFor($user);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved')]);

            return $driver;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Driver $d): array
    {
        return [
            'id' => $d->id,
            'callsign' => $d->callsign,
            'name' => $d->user->name,
            'phone' => $d->user->phone,
            'branch_id' => $d->branch_id,
            'status' => $d->status->value,
            'balance' => $d->balance,
            'rating' => $d->rating,
            'is_approved' => $d->is_approved,
            'blocked_until' => $d->blocked_until?->toIso8601String(),
            'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            'vehicle' => $d->vehicle ? [
                'brand' => $d->vehicle->brand,
                'model' => $d->vehicle->model,
                'color' => $d->vehicle->color,
                'plate_number' => $d->vehicle->plate_number,
                'year' => $d->vehicle->year,
                'tech_passport_no' => $d->vehicle->tech_passport_no,
                'is_approved' => $d->vehicle->is_approved,
                'tariff_class_ids' => $d->vehicle->tariffClasses->pluck('id'),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(Request $request): array
    {
        /** @var User $actor */
        $actor = $request->user();
        $branchIds = $this->branchIds($actor);

        return [
            'branches' => Branch::query()->whereIn('id', $branchIds)->orderBy('name')->get(['id', 'name']),
            'tariffClasses' => TariffClass::query()->whereIn('branch_id', $branchIds)->orderBy('sort')->get(['id', 'branch_id', 'name']),
            'documentTypes' => array_map(fn (DocumentType $t) => $t->value, DocumentType::cases()),
        ];
    }

    /**
     * @return list<int>
     */
    private function branchIds(User $actor): array
    {
        return $actor->isSuperadmin()
            ? array_values(Branch::query()->pluck('id')->map(fn ($id): int => (int) $id)->all())
            : $actor->branchIds();
    }
}
