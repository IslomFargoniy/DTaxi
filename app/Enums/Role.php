<?php

namespace App\Enums;

enum Role: string
{
    case Superadmin = 'superadmin';
    case Admin = 'admin';
    case Operator = 'operator';
    case Cashier = 'cashier';
    case Driver = 'driver';

    /**
     * Rollar uchun ruxsatlar. Superadmin hamma narsaga ruxsatli (Gate::before).
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Superadmin => [],
            self::Admin => [
                'branches.view',
                'users.manage',
                'drivers.manage',
                'tariffs.manage',
                'orders.view',
                'orders.manage',
                'balance.adjust',
                'reports.view',
                'calls.listen',
                'settings.branch',
            ],
            self::Operator => ['orders.view', 'orders.manage', 'calls.answer', 'chat.drivers'],
            self::Cashier => ['balance.topup', 'cash_shifts.manage'],
            self::Driver => ['driver.app'],
        };
    }

    /**
     * @return list<string>
     */
    public static function allPermissions(): array
    {
        return array_values(array_unique(array_merge(
            ...array_map(fn (self $role) => $role->permissions(), self::cases()),
        )));
    }
}
