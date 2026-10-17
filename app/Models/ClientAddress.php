<?php

namespace App\Models;

use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Mijozning avval borgan joylari — keyingi qo'ng'iroqda bir bosishda tanlanadi.
 *
 * @property int $id
 * @property int $client_id
 * @property string|null $label
 * @property int|null $landmark_id
 * @property string $text
 * @property Point $location
 * @property int $usage_count
 * @property Carbon|null $last_used_at
 */
#[Fillable(['client_id', 'label', 'landmark_id', 'text', 'location', 'usage_count', 'last_used_at'])]
class ClientAddress extends Model
{
    protected function casts(): array
    {
        return ['location' => Point::class, 'last_used_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
