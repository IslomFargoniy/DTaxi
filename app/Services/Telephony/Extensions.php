<?php

namespace App\Services\Telephony;

use App\Models\User;

/**
 * Operator va haydovchilarning WebRTC ichki raqamlari (reja §5.5). Asterisk Realtime jadvallari
 * Bosqich 3 da qo'shiladi; hozircha hech narsa qilmaydi.
 */
class Extensions
{
    public function syncFor(User $user): void {}
}
