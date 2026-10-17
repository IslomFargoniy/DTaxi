<?php

namespace App\Http\Requests\Operator;

use App\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('orders.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return [
            'branch_id' => ['required', 'integer', $user->isSuperadmin() ? Rule::exists('branches', 'id') : Rule::in($user->branchIds())],
            'phone' => ['required', 'string', 'regex:/^[\d\s()+-]{7,20}$/'],
            'client_name' => ['nullable', 'string', 'max:100'],
            'tariff_class_id' => ['nullable', 'integer', Rule::exists('tariff_classes', 'id')->where('branch_id', $this->integer('branch_id'))],
            'pickup_text' => ['required', 'string', 'max:255'],
            'pickup_lat' => ['required', 'numeric', 'between:37,46'],
            'pickup_lng' => ['required', 'numeric', 'between:55,74'],
            'pickup_landmark_id' => ['nullable', 'integer', 'exists:landmarks,id'],
            'dropoff_text' => ['nullable', 'string', 'max:255'],
            'dropoff_lat' => ['nullable', 'required_with:dropoff_lng', 'numeric', 'between:37,46'],
            'dropoff_lng' => ['nullable', 'required_with:dropoff_lat', 'numeric', 'between:55,74'],
            'comment' => ['nullable', 'string', 'max:500'],
            'delivery' => ['nullable', 'array'],
            'delivery.sender_name' => ['nullable', 'string', 'max:100'],
            'delivery.sender_phone' => ['nullable', 'string', 'max:20'],
            'delivery.recipient_name' => ['nullable', 'string', 'max:100'],
            'delivery.recipient_phone' => ['nullable', 'string', 'max:20'],
            'delivery.description' => ['nullable', 'string', 'max:500'],
            // Buyurtma qaysi qo'ng'iroqdan yaratildi (pop-up orqali) — yozuv va raqam statistikasi uchun
            'call_id' => ['nullable', 'integer', Rule::exists('calls', 'id')],
            // Oldindan buyurtma: filial vaqti bilan "2026-10-20T14:30", eng ko'pi 7 kun oldin
            'scheduled_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
        ];
    }

    /**
     * Forma qiymatlari (matn ko'rinishida keladi) → OrderService kutgan tiplar.
     *
     * @return array{
     *     branch_id: int,
     *     phone: string,
     *     client_name: string|null,
     *     tariff_class_id: int|null,
     *     pickup_text: string,
     *     pickup_lat: float,
     *     pickup_lng: float,
     *     pickup_landmark_id: int|null,
     *     dropoff_text: string|null,
     *     dropoff_lat: float|null,
     *     dropoff_lng: float|null,
     *     comment: string|null,
     *     delivery: array<string, string|null>|null,
     *     scheduled_at: \DateTimeInterface|null,
     * }
     */
    public function orderData(): array
    {
        $delivery = $this->input('delivery');

        return [
            'branch_id' => $this->integer('branch_id'),
            'phone' => $this->string('phone')->toString(),
            'client_name' => $this->filled('client_name') ? $this->string('client_name')->toString() : null,
            'tariff_class_id' => $this->filled('tariff_class_id') ? $this->integer('tariff_class_id') : null,
            'pickup_text' => $this->string('pickup_text')->toString(),
            'pickup_lat' => $this->float('pickup_lat'),
            'pickup_lng' => $this->float('pickup_lng'),
            'pickup_landmark_id' => $this->filled('pickup_landmark_id') ? $this->integer('pickup_landmark_id') : null,
            'dropoff_text' => $this->filled('dropoff_text') ? $this->string('dropoff_text')->toString() : null,
            'dropoff_lat' => $this->filled('dropoff_lat') ? $this->float('dropoff_lat') : null,
            'dropoff_lng' => $this->filled('dropoff_lng') ? $this->float('dropoff_lng') : null,
            'comment' => $this->filled('comment') ? $this->string('comment')->toString() : null,
            'delivery' => is_array($delivery) ? array_map(fn ($v) => is_string($v) ? $v : null, $delivery) : null,
            'scheduled_at' => $this->scheduledAt(),
        ];
    }

    /**
     * Operator filial mahalliy vaqtini kiritadi; bazaga UTC.
     */
    private function scheduledAt(): ?\DateTimeInterface
    {
        if (! $this->filled('scheduled_at')) {
            return null;
        }
        $timezone = Branch::query()->whereKey($this->integer('branch_id'))->value('timezone') ?? 'Asia/Tashkent';

        return CarbonImmutable::createFromFormat('Y-m-d\TH:i', $this->string('scheduled_at')->toString(), $timezone)?->utc();
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $at = $this->filled('scheduled_at') && ! $validator->errors()->has('scheduled_at') ? $this->scheduledAt() : null;
            if ($at && ($at < now()->addMinutes(5) || $at > now()->addDays(7))) {
                $validator->errors()->add('scheduled_at', __('Pre-order time must be between 5 minutes and 7 days from now.'));
            }
        }];
    }
}
