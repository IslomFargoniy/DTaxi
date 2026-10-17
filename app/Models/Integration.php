<?php

namespace App\Models;

use App\Enums\IntegrationProvider;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property IntegrationProvider $provider
 * @property bool $is_enabled
 * @property array<string, string|null>|null $credentials
 * @property Carbon|null $last_checked_at
 * @property bool|null $last_check_ok
 * @property string|null $last_check_message
 */
#[Fillable(['provider', 'is_enabled', 'credentials'])]
#[Hidden(['credentials'])]
class Integration extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'provider' => IntegrationProvider::class,
            'is_enabled' => 'boolean',
            'credentials' => 'encrypted:array',
            'last_checked_at' => 'datetime',
            'last_check_ok' => 'boolean',
        ];
    }

    public static function for(IntegrationProvider $provider): self
    {
        return static::firstOrNew(['provider' => $provider]);
    }

    /**
     * Integratsiya yoqilgan va kalitlari kiritilgan bo'lsa, maydon qiymatini qaytaradi.
     */
    public static function credential(IntegrationProvider $provider, string $key): ?string
    {
        $integration = static::query()->where('provider', $provider)->where('is_enabled', true)->first();

        return $integration?->credentials[$key] ?? null;
    }

    /**
     * Panelda ko'rsatish uchun: maxfiy maydonlarning faqat oxirgi 4 belgisi.
     *
     * @return array<string, string|null>
     */
    public function maskedCredentials(): array
    {
        $credentials = $this->credentials ?? [];
        $masked = [];

        foreach ($this->provider->fields() as $field) {
            $value = $credentials[$field['key']] ?? null;
            $masked[$field['key']] = $field['secret'] && $value !== null && $value !== ''
                ? '••••'.mb_substr($value, -4)
                : $value;
        }

        return $masked;
    }
}
