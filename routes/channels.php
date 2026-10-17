<?php

use App\Models\Driver;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Operator paneli: filial buyurtmalari va haydovchilar xaritasi
Broadcast::channel('branch.{branchId}', function (User $user, int $branchId) {
    return $user->isSuperadmin() || ($user->can('orders.view') && in_array($branchId, $user->branchIds(), true));
});

// Haydovchi ilovasi: takliflar va o'z buyurtmasi
Broadcast::channel('driver.{driverId}', function (User $user, int $driverId) {
    return Driver::query()->withoutGlobalScopes()->whereKey($driverId)->where('user_id', $user->id)->exists();
});

// Operatorning shaxsiy kanali: kiruvchi qo'ng'iroq pop-up, holat
Broadcast::channel('user.{userId}', fn (User $user, int $userId) => $user->id === $userId);

// Filialsiz qo'ng'iroqlar (raqam hali filialga bog'lanmagan) — superadmin monitoringi
Broadcast::channel('telephony', fn (User $user) => $user->isSuperadmin());
