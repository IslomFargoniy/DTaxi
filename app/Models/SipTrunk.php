<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\Telephony\TrunkSync;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SIP trunk (Sarkor va h.k.) — firma o'z shartnomasi bilan (qaror #40).
 *
 * @property int $id
 * @property string $name
 * @property string $title
 * @property string $host
 * @property int $port
 * @property string $transport
 * @property string|null $username
 * @property string|null $password
 * @property string|null $from_user
 * @property int $max_channels
 * @property int|null $max_inbound
 * @property int|null $max_outbound
 * @property bool $is_active
 */
#[Fillable(['name', 'title', 'host', 'port', 'transport', 'username', 'password', 'from_user', 'max_channels', 'max_inbound', 'max_outbound', 'is_active'])]
#[Hidden(['password'])]
class SipTrunk extends Model
{
    use Auditable;

    protected static function booted(): void
    {
        // Panelda o'zgartirilsa — darhol Asterisk Realtime'ga (qayta ishga tushirmasdan)
        static::saved(function (SipTrunk $trunk) {
            // Id lar nomdan yasaladi — eski nomli yozuvlar qolsa Asterisk eski trunk bilan ham ro'yxatdan o'taveradi
            if ($trunk->wasChanged('name') && is_string($old = $trunk->getOriginal('name')) && $old !== '') {
                app(TrunkSync::class)->removeEndpoint(self::endpointFor($old));
            }
            app(TrunkSync::class)->sync($trunk);
        });
        static::deleted(fn (SipTrunk $trunk) => app(TrunkSync::class)->remove($trunk));
    }

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'password' => 'encrypted',
            'max_channels' => 'integer',
            'max_inbound' => 'integer',
            'max_outbound' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PhoneNumber, $this>
     */
    public function phoneNumbers(): HasMany
    {
        return $this->hasMany(PhoneNumber::class);
    }

    /** Asterisk'dagi endpoint nomi */
    public function endpoint(): string
    {
        return self::endpointFor($this->name);
    }

    public static function endpointFor(string $name): string
    {
        return 'trunk-'.$name;
    }
}
