<?php

namespace App\Services\Branches;

use App\Models\Branch;
use App\Models\TariffClass;
use App\Support\Settings;

/**
 * Yangi filial uchun boshlang'ich ma'lumotlar: 4 ta tarif klassi va ularning narxlari (qaror #27).
 * Narxlar shartli — admin panelda o'zgartiradi.
 */
class BranchProvisioner
{
    /**
     * @var list<array{name: string, icon: string, is_delivery: bool, is_default: bool, base_fare: int, base_km: int, per_km: int}>
     */
    public const DEFAULT_CLASSES = [
        ['name' => 'Standart', 'icon' => 'car', 'is_delivery' => false, 'is_default' => true, 'base_fare' => 10000, 'base_km' => 3, 'per_km' => 2000],
        ['name' => 'Komfort', 'icon' => 'car-front', 'is_delivery' => false, 'is_default' => false, 'base_fare' => 15000, 'base_km' => 3, 'per_km' => 2500],
        ['name' => 'Yuk', 'icon' => 'truck', 'is_delivery' => false, 'is_default' => false, 'base_fare' => 25000, 'base_km' => 3, 'per_km' => 3000],
        ['name' => 'Dostavka', 'icon' => 'package', 'is_delivery' => true, 'is_default' => false, 'base_fare' => 12000, 'base_km' => 3, 'per_km' => 2000],
    ];

    public function __construct(private Settings $settings) {}

    public function provision(Branch $branch): void
    {
        if (TariffClass::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->exists()) {
            return;
        }

        $roundingStep = (int) $this->settings->get('tariffs.rounding_step', $branch);

        foreach (self::DEFAULT_CLASSES as $sort => $class) {
            $tariffClass = TariffClass::query()->create([
                'branch_id' => $branch->id,
                'name' => $class['name'],
                'icon' => $class['icon'],
                'sort' => $sort,
                'is_delivery' => $class['is_delivery'],
                'is_default' => $class['is_default'],
            ]);

            $tariffClass->tariff()->create([
                'branch_id' => $branch->id,
                'base_fare' => $class['base_fare'],
                'base_km' => $class['base_km'],
                'per_km' => $class['per_km'],
                'rounding_step' => $roundingStep,
            ]);
        }
    }
}
