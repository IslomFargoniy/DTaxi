<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Navbat joyi: shu radius ichida turgan bo'sh haydovchilar kelish tartibida navbatga turadi,
 * shu yerdan chiqqan buyurtma avval navbat boshidagiga taklif qilinadi.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $name
 * @property Point $location
 * @property int $radius_m
 * @property bool $is_active
 */
#[Fillable(['branch_id', 'name', 'location', 'radius_m', 'is_active'])]
class Stand extends Model
{
    use BelongsToBranch;

    protected function casts(): array
    {
        return ['location' => Point::class, 'is_active' => 'boolean', 'radius_m' => 'integer'];
    }
}
