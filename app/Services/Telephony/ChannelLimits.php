<?php

namespace App\Services\Telephony;

use App\Models\Branch;
use App\Models\Call;
use App\Models\SipTrunk;

/**
 * Parallel kanallar limiti (qaror #13, reja §5.3): trunk jami / kiruvchi / chiquvchi va filial ulushi.
 * Limit bazadan o'qiladi — panelda o'zgartirilsa darhol kuchga kiradi.
 */
class ChannelLimits
{
    public function canAcceptInbound(SipTrunk $trunk, ?Branch $branch = null): bool
    {
        $active = $this->active($trunk);

        if ($active['total'] >= $trunk->max_channels) {
            return false;
        }
        if ($trunk->max_inbound !== null && $active['inbound'] >= $trunk->max_inbound) {
            return false;
        }

        return ! $branch?->max_channels || $this->activeForBranch($branch) < $branch->max_channels;
    }

    /**
     * Avto-qo'ng'iroqlar (TTS) operator liniyalarini band qilib qo'ymasligi uchun alohida ulush.
     */
    public function canPlaceAutoCall(SipTrunk $trunk, Branch $branch): bool
    {
        $active = $this->active($trunk);

        if ($active['total'] >= $trunk->max_channels) {
            return false;
        }
        if ($trunk->max_outbound !== null && $active['outbound'] >= $trunk->max_outbound) {
            return false;
        }
        if ($branch->max_channels && $this->activeForBranch($branch) >= $branch->max_channels) {
            return false;
        }

        $autoCalls = Call::query()->withoutGlobalScopes()->where('branch_id', $branch->id)
            ->where('kind', 'tts')->whereIn('status', Call::ACTIVE)->count();

        return $autoCalls < max(1, (int) $branch->max_auto_calls);
    }

    /**
     * Operatorning qayta qo'ng'irog'i (callback): avto-qo'ng'iroq ulushiga kirmaydi.
     */
    public function canPlaceOutbound(SipTrunk $trunk, ?Branch $branch = null): bool
    {
        $active = $this->active($trunk);

        if ($active['total'] >= $trunk->max_channels) {
            return false;
        }
        if ($trunk->max_outbound !== null && $active['outbound'] >= $trunk->max_outbound) {
            return false;
        }

        return ! $branch?->max_channels || $this->activeForBranch($branch) < $branch->max_channels;
    }

    /**
     * @return array{total: int, inbound: int, outbound: int}
     */
    public function active(SipTrunk $trunk): array
    {
        $rows = Call::query()->withoutGlobalScopes()
            ->where('sip_trunk_id', $trunk->id)
            ->whereIn('status', Call::ACTIVE)
            ->selectRaw('direction, COUNT(*) AS total')
            ->groupBy('direction')
            ->pluck('total', 'direction');

        $inbound = (int) ($rows['inbound'] ?? 0);
        $outbound = (int) ($rows['outbound'] ?? 0);

        return ['total' => $inbound + $outbound, 'inbound' => $inbound, 'outbound' => $outbound];
    }

    private function activeForBranch(Branch $branch): int
    {
        return Call::query()->withoutGlobalScopes()->where('branch_id', $branch->id)
            ->whereNotNull('sip_trunk_id')->whereIn('status', Call::ACTIVE)->count();
    }
}
