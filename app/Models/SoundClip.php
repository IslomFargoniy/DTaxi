<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ovozli bo'lak (§5.4): bir marta generatsiya qilinadi, Asterisk ketma-ket ijro etadi.
 *
 * @property int $id
 * @property string $category phrase | number | letter | color | car | ivr | greeting
 * @property string $key
 * @property string $text
 * @property string|null $file_path storage/app/sounds/uz ga nisbatan, kengaytmasiz
 * @property int|null $duration_ms
 * @property Carbon|null $generated_at
 */
#[Fillable(['category', 'key', 'text', 'file_path', 'duration_ms', 'generated_at'])]
class SoundClip extends Model
{
    protected function casts(): array
    {
        return ['generated_at' => 'datetime'];
    }

    /** Asterisk uchun media URI: sound:dtaxi/<kategoriya>/<kalit> */
    public function media(): ?string
    {
        return $this->file_path ? 'sound:dtaxi/'.$this->file_path : null;
    }
}
