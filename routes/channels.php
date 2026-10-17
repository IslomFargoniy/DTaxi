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
