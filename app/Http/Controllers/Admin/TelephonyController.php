<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PhoneNumber;
use App\Models\SipTrunk;
use App\Services\Telephony\AriClient;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Telefoniya sozlamalari (§5.5, faqat superadmin): SIP trunklar, raqamlar ↔ filiallar, liniyalar limiti,
 * qo'ng'iroq markazi sozlamalari. Hammasi Asterisk Realtime orqali darhol ishlaydi.
 */
class TelephonyController extends Controller
{
    private const SETTINGS = [
        'calls.max_wait_seconds' => ['integer', 'min:30', 'max:1800'],
        'calls.operator_ring_seconds' => ['integer', 'min:5', 'max:120'],
        'calls.pause_on_missed_ring' => ['boolean'],
        'calls.ivr_auto_order' => ['boolean'],
        'calls.auto_calls_enabled' => ['boolean'],
        'calls.auto_call_attempts' => ['integer', 'min:1', 'max:5'],
        'calls.auto_call_retry_seconds' => ['integer', 'min:10', 'max:600'],
        'calls.still_waiting_minutes' => ['integer', 'min:0', 'max:30'],
        'calls.rating_call_enabled' => ['boolean'],
        'calls.rating_call_delay_minutes' => ['integer', 'min:0', 'max:60'],
        'calls.recordings_retention_days' => ['integer', 'min:1', 'max:3650'],
        'calls.max_recording_seconds' => ['integer', 'min:60', 'max:86400'],
        'calls.driver_gain' => ['integer', 'min:0', 'max:10'],
        'calls.operator_outbound_limit' => ['integer', 'min:0', 'max:1000'],
        'calls.operator_outbound_window_minutes' => ['integer', 'min:1', 'max:1440'],
        // Operator qaysi raqamlarga tashqariga qo'ng'iroq qila oladi (toll fraud himoyasi)
        'calls.outbound_allowed_patterns' => ['array', 'min:1', 'max:20'],
    ];

    public function index(AriClient $ari, Settings $settings): Response
    {
        $states = rescue(fn () => $ari->endpointStates(), [], false);

        return Inertia::render('admin/telephony', [
            'trunks' => SipTrunk::query()->orderBy('id')->get()->map(fn (SipTrunk $t) => [
                ...$t->only(['id', 'name', 'title', 'host', 'port', 'username', 'from_user', 'max_channels', 'max_inbound', 'max_outbound', 'is_active']),
                'has_password' => (bool) $t->password,
                'state' => $states[$t->endpoint()] ?? null,
            ]),
            'numbers' => PhoneNumber::query()->with('branches:id')->orderBy('id')->get()->map(fn (PhoneNumber $n) => [
                ...$n->only(['id', 'sip_trunk_id', 'number', 'name', 'routing_mode', 'is_active']),
                'branches' => $n->branches->map(fn (Branch $b) => [
                    'id' => $b->id,
                    'is_primary' => (bool) $b->getRelationValue('pivot')->is_primary,
                    'ivr_digit' => $b->getRelationValue('pivot')->ivr_digit,
                ])->values(),
            ]),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name', 'max_channels', 'max_auto_calls']),
            'settings' => collect(array_keys(self::SETTINGS))->mapWithKeys(fn (string $key) => [$key => $settings->get($key)]),
            'asteriskOnline' => $ari->ping(),
        ]);
    }

    public function storeTrunk(Request $request): RedirectResponse
    {
        SipTrunk::query()->create($this->trunkData($request));

        return back();
    }

    public function updateTrunk(Request $request, SipTrunk $trunk): RedirectResponse
    {
        $data = $this->trunkData($request, $trunk);
        // Parol bo'sh qoldirilsa — eskisi saqlanadi
        if (($data['password'] ?? '') === '') {
            unset($data['password']);
        }
        $trunk->update($data);

        return back();
    }

    public function destroyTrunk(SipTrunk $trunk): RedirectResponse
    {
        $trunk->delete();

        return back();
    }

    public function storeNumber(Request $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $number = PhoneNumber::query()->create($this->numberData($request));
            $this->syncBranches($number, $request);
        });

        return back();
    }

    public function updateNumber(Request $request, PhoneNumber $number): RedirectResponse
    {
        DB::transaction(function () use ($request, $number) {
            $number->update($this->numberData($request, $number));
            $this->syncBranches($number, $request);
        });

        return back();
    }

    public function destroyNumber(PhoneNumber $number): RedirectResponse
    {
        $number->delete();

        return back();
    }

    public function updateLimits(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branches' => ['required', 'array'],
            'branches.*.id' => ['required', 'integer', 'exists:branches,id'],
            'branches.*.max_channels' => ['nullable', 'integer', 'min:1', 'max:500'],
            'branches.*.max_auto_calls' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        foreach ($data['branches'] as $row) {
            Branch::query()->whereKey($row['id'])->update([
                'max_channels' => $row['max_channels'] ?? null,
                'max_auto_calls' => $row['max_auto_calls'],
            ]);
        }

        return back();
    }

    public function updateSettings(Request $request, Settings $settings): RedirectResponse
    {
        $rules = [];
        foreach (self::SETTINGS as $key => $rule) {
            $rules[str_replace('.', '_', $key)] = ['required', ...$rule];
        }
        $rules['calls_outbound_allowed_patterns.*'] = ['required', 'string', 'max:200', function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || @preg_match('/'.str_replace('/', '\\/', $value).'/', '') === false) {
                $fail(__('Invalid number pattern.'));
            }
        }];
        $data = $request->validate($rules);

        foreach (self::SETTINGS as $key => $rule) {
            $value = $data[str_replace('.', '_', $key)];
            $settings->set($key, match (true) {
                in_array('boolean', $rule, true) => (bool) $value,
                in_array('array', $rule, true) => array_values(array_map(fn (mixed $v): string => trim((string) $v), (array) $value)),
                default => (int) $value,
            });
        }

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function trunkData(Request $request, ?SipTrunk $trunk = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9_-]+$/', Rule::unique('sip_trunks', 'name')->ignore($trunk)],
            'title' => ['required', 'string', 'max:100'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:255'],
            'from_user' => ['nullable', 'string', 'max:100'],
            'max_channels' => ['required', 'integer', 'min:1', 'max:1000'],
            'max_inbound' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'max_outbound' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function numberData(Request $request, ?PhoneNumber $number = null): array
    {
        $data = $request->validate([
            'sip_trunk_id' => ['required', 'integer', 'exists:sip_trunks,id'],
            'number' => ['required', 'string', 'max:20', 'regex:/^\+?\d{3,15}$/', Rule::unique('phone_numbers', 'number')->ignore($number)],
            'name' => ['nullable', 'string', 'max:100'],
            'routing_mode' => ['required', Rule::in(['shared_queue', 'ivr', 'client_history'])],
            'is_active' => ['boolean'],
            'branches' => ['array'],
            'branches.*.id' => ['required', 'integer', 'exists:branches,id'],
            'branches.*.is_primary' => ['boolean'],
            'branches.*.ivr_digit' => ['nullable', 'integer', 'min:1', 'max:9'],
        ]);
        unset($data['branches']);

        return $data;
    }

    private function syncBranches(PhoneNumber $number, Request $request): void
    {
        /** @var list<array{id: int, is_primary?: bool, ivr_digit?: int|null}> $rows */
        $rows = $request->input('branches', []);

        $number->branches()->sync(collect($rows)->mapWithKeys(fn (array $row) => [(int) $row['id'] => [
            'is_primary' => (bool) ($row['is_primary'] ?? false),
            'ivr_digit' => isset($row['ivr_digit']) ? (int) $row['ivr_digit'] : null,
        ]])->all());
    }
}
