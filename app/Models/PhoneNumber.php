<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Kiruvchi raqam (DID) va u xizmat qiladigan filiallar (qaror #15, §5.2a).
 *
 * @property int $id
 * @property int $sip_trunk_id
 * @property string $number
 * @property string|null $name
 * @property string $routing_mode shared_queue | ivr | client_history
 * @property bool $is_active
 * @property-read SipTrunk $trunk
 */
#[Fillable(['sip_trunk_id', 'number', 'name', 'routing_mode', 'is_active'])]
class PhoneNumber extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<SipTrunk, $this>
     */
    public function trunk(): BelongsTo
    {
        return $this->belongsTo(SipTrunk::class, 'sip_trunk_id');
    }

    /**
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)->withPivot(['is_primary', 'ivr_digit']);
    }

    /**
     * Raqamni oxirgi 9 raqam bo'yicha topish: trunk "998712000000", "+998…" yoki "712000000" yuborishi mumkin.
     */
    public static function findByDialed(string $dialed): ?self
    {
        $digits = (string) preg_replace('/\D/', '', $dialed);
        if (strlen($digits) < 4) {
            return null;
        }

        return static::query()->where('is_active', true)
            ->whereRaw("regexp_replace(number, '\\D', '', 'g') LIKE ?", ['%'.substr($digits, -9)])
            ->first();
    }

    /**
     * Chiquvchi qo'ng'iroq uchun Caller ID (§5.2a): filialning asosiy raqami → filialning istalgan raqami → istalgan faol raqam.
     */
    public static function forBranch(?int $branchId): ?self
    {
        $active = static::query()->where('is_active', true)->whereHas('trunk', fn ($q) => $q->where('is_active', true));

        if ($branchId) {
            $number = (clone $active)
                ->join('branch_phone_number', 'branch_phone_number.phone_number_id', '=', 'phone_numbers.id')
                ->where('branch_phone_number.branch_id', $branchId)
                ->orderByDesc('branch_phone_number.is_primary')
                ->select('phone_numbers.*')
                ->first();
            if ($number) {
                return $number;
            }
        }

        return $active->orderBy('id')->first();
    }

    /** Trunk orqali chiqish uchun Asterisk endpoint: PJSIP/998901234567@trunk-sarkor */
    public function dialString(string $phone): string
    {
        return 'PJSIP/'.preg_replace('/\D/', '', $phone).'@'.$this->trunk->endpoint();
    }
}
