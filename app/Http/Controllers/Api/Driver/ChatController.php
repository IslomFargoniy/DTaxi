<?php

namespace App\Http\Controllers\Api\Driver;

use App\Events\ChatMessageSent;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Driver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Haydovchi ↔ dispetcher chati (qaror #47).
 */
class ChatController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $driver = $this->driver($request);

        ChatMessage::query()->where('driver_id', $driver->id)->where('sender_id', '!=', $driver->user_id)->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(
            ChatMessage::query()->with(['sender:id,name', 'driver:id,user_id'])->where('driver_id', $driver->id)->latest('created_at')->limit(100)->get()
                ->map(fn (ChatMessage $m) => ChatMessageSent::format($m, $driver->user_id)),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required_without:photo', 'nullable', 'string', 'max:1000'],
            'photo' => ['nullable', 'image', 'max:5120'],
            'order_id' => ['nullable', 'integer'],
        ]);
        $driver = $this->driver($request);

        $message = ChatMessage::query()->create([
            'driver_id' => $driver->id,
            'order_id' => $data['order_id'] ?? null,
            'sender_id' => $driver->user_id,
            'body' => $data['body'] ?? null,
            'attachment_path' => $request->file('photo')?->store("chat/{$driver->id}"),
            'created_at' => now(),
        ]);

        ChatMessageSent::dispatch($message, $driver->branch_id);

        return response()->json(ChatMessageSent::format($message, $driver->user_id), 201);
    }

    private function driver(Request $request): Driver
    {
        return Driver::query()->withoutGlobalScopes()->where('user_id', $request->user()?->id)->firstOrFail();
    }
}
