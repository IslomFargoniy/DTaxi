<?php

namespace App\Jobs;

use App\Services\Push\FcmPushNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * @param  array<string, scalar|null>  $data
     */
    public function __construct(public int $userId, public array $data)
    {
        $this->onQueue('push');
    }

    public function handle(FcmPushNotifier $notifier): void
    {
        $notifier->sendToUser($this->userId, $this->data);
    }
}
