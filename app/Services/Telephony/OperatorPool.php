<?php

namespace App\Services\Telephony;

use App\Enums\Role;
use App\Events\OperatorCallEvent;
use App\Models\Call;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Operatorlar holati va navbatdagi qo'ng'iroqqa bo'sh operator tanlash (ACD).
 * Holat: offline / ready / paused; "band" — faol qo'ng'iroqdan kelib chiqadi.
 */
class OperatorPool
{
    public const STATUSES = ['offline', 'ready', 'paused'];

    public function __construct(private AriClient $ari) {}

    public function setStatus(User $user, string $status): void
    {
        $user->forceFill(['operator_status' => $status, 'operator_status_at' => now()])->saveQuietly();
        // Reverb ishlamasa holat baribir saqlansin (ari:listen ichida ham chaqiriladi — tsikl to'xtamasin)
        rescue(fn () => OperatorCallEvent::dispatch($user->id, 'status', ['status' => $status]));
    }

    /**
     * Eng uzoq bo'sh turgan, brauzerda ro'yxatdan o'tgan, shu filial(lar)ga biriktirilgan operator.
     *
     * @param  list<int>  $branchIds
     * @param  list<int>  $exclude
     */
    public function pick(array $branchIds, array $exclude = []): ?User
    {
        $online = $this->onlineExtensions();
        if ($online === [] || $branchIds === []) {
            return null;
        }

        return User::query()
            ->role([Role::Operator->value, Role::Admin->value, Role::Superadmin->value])
            ->where('is_active', true)
            ->where('operator_status', 'ready')
            ->whereIn('sip_extension', $online)
            ->whereNotIn('id', [...$exclude, ...$this->busyOperatorIds()])
            // Superadmin — barcha filiallar navbatida
            ->where(fn ($q) => $q->whereIn('branch_id', $branchIds)
                ->orWhereHas('branches', fn ($b) => $b->whereIn('branches.id', $branchIds))
                ->orWhereHas('roles', fn ($r) => $r->where('name', Role::Superadmin->value)))
            ->orderByRaw('operator_status_at ASC NULLS FIRST')
            ->first();
    }

    /**
     * Hozir gaplashayotgan yoki jiringlayotgan operatorlar.
     *
     * @return list<int>
     */
    public function busyOperatorIds(): array
    {
        return array_values(array_unique(array_map('intval', Call::query()->withoutGlobalScopes()
            ->whereIn('status', Call::ACTIVE)
            ->whereNotNull('operator_id')
            ->pluck('operator_id')
            ->all())));
    }

    /**
     * ARI so'rovi har navbat tekshiruvida takrorlanmasligi uchun 2 soniya keshlanadi.
     *
     * @return list<string>
     */
    public function onlineExtensions(): array
    {
        /** @var list<string> */
        return Cache::remember('telephony:online-endpoints', 2, fn () => $this->ari->onlineEndpoints());
    }
}
