<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\URL;

class ChatMessageSent implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public ChatMessage $message, public int $branchId) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("branch.{$this->branchId}"),
            new PrivateChannel("driver.{$this->message->driver_id}"),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return self::format($this->message, null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function format(ChatMessage $message, ?int $viewerId): array
    {
        return [
            'id' => $message->id,
            'driver_id' => $message->driver_id,
            'order_id' => $message->order_id,
            'sender_id' => $message->sender_id,
            // Bir haydovchi bilan bir nechta operator yozishishi mumkin — kim yozgani ko'rinsin
            'sender_name' => $message->sender?->name,
            'from_driver' => $message->sender_id === $message->driver?->user_id,
            'mine' => $viewerId !== null && $message->sender_id === $viewerId,
            'body' => $message->body,
            // Panel ham, ilova ham ochishi uchun vaqtinchalik imzolangan havola
            'attachment_url' => $message->attachment_path ? URL::temporarySignedRoute('chat.attachment', now()->addHour(), ['message' => $message->id]) : null,
            'read_at' => $message->read_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
