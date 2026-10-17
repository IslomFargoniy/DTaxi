<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Haydovchi hujjatlari: prava, tex-pasport, mashina rasmlari.
 *
 * @property int $id
 * @property int $driver_id
 * @property DocumentType $type
 * @property string $file_path
 * @property DocumentStatus $status
 */
#[Fillable(['driver_id', 'type', 'file_path', 'expires_at', 'status'])]
class DriverDocument extends Model
{
    protected function casts(): array
    {
        return ['type' => DocumentType::class, 'status' => DocumentStatus::class, 'expires_at' => 'date'];
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
