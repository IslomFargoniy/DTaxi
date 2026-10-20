<?php

namespace App\Http\Controllers\Operator;

use App\Events\ChatMessageSent;
use App\Http\Controllers\Controller;
use App\Jobs\SendPushNotification;
use App\Models\ChatMessage;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Operator tomoni: haydovchilar bilan chat (qaror #47).
 */
class ChatController extends Controller
{
    /**
     * Suhbatlar ro'yxati: oxirgi xabar va o'qilmaganlar soni.
     */
    public function threads(): JsonResponse
    {
        $driverIds = Driver::query()->pluck('id');

        // O'qilmagan — faqat haydovchi yozganlari (boshqa operatorning xabari "o'qilmagan" bo'lib qolib ketmasin)
        $latest = DB::table('chat_messages')
            ->join('drivers', 'drivers.id', '=', 'chat_messages.driver_id')
            ->whereIn('chat_messages.driver_id', $driverIds)
            ->select('chat_messages.driver_id', DB::raw('MAX(chat_messages.created_at) AS last_at'))
            ->selectRaw('COUNT(*) FILTER (WHERE chat_messages.read_at IS NULL AND chat_messages.sender_id = drivers.user_id) AS unread')
            ->groupBy('chat_messages.driver_id')
            ->orderByDesc('last_at')
            ->limit(50)
            ->get();

        $drivers = Driver::query()->with('user')->findMany($latest->pluck('driver_id'))->keyBy('id');
        // Ro'yxatda oxirgi xabar ko'rinadi (Telegram kabi)
        $last = DB::table('chat_messages')
            ->join('drivers', 'drivers.id', '=', 'chat_messages.driver_id')
            ->whereIn('chat_messages.driver_id', $latest->pluck('driver_id'))
            ->selectRaw('DISTINCT ON (chat_messages.driver_id) chat_messages.driver_id, chat_messages.body,
                chat_messages.attachment_path IS NOT NULL AS has_photo, chat_messages.sender_id = drivers.user_id AS from_driver')
            ->orderBy('chat_messages.driver_id')->orderByDesc('chat_messages.created_at')
            ->get()->keyBy('driver_id');

        return response()->json($latest->map(fn (object $row) => [
            'driver_id' => $row->driver_id,
            'callsign' => $drivers->get($row->driver_id)?->callsign,
            'name' => $drivers->get($row->driver_id)?->user->name,
            'last_at' => $row->last_at,
            'unread' => (int) $row->unread,
            'last_body' => $last->get($row->driver_id)?->body,
            'last_photo' => (bool) ($last->get($row->driver_id)->has_photo ?? false),
            'last_from_driver' => (bool) ($last->get($row->driver_id)->from_driver ?? false),
        ])->values());
    }

    public function messages(Request $request, Driver $driver): JsonResponse
    {
        ChatMessage::query()->where('driver_id', $driver->id)->where('sender_id', $driver->user_id)->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(
            ChatMessage::query()->with(['sender:id,name', 'driver:id,user_id'])->where('driver_id', $driver->id)->latest('created_at')->limit(100)->get()->reverse()->values()
                ->map(fn (ChatMessage $m) => ChatMessageSent::format($m, $request->user()?->id)),
        );
    }

    public function send(Request $request, Driver $driver): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:1000'], 'order_id' => ['nullable', 'integer']]);
        /** @var User $user */
        $user = $request->user();

        $message = ChatMessage::query()->create([
            'driver_id' => $driver->id,
            'order_id' => $data['order_id'] ?? null,
            'sender_id' => $user->id,
            'body' => $data['body'],
            'created_at' => now(),
        ]);

        ChatMessageSent::dispatch($message, $driver->branch_id);
        SendPushNotification::dispatch($driver->user_id, ['type' => 'chat.message', 'body' => mb_substr($data['body'], 0, 200)]);

        return response()->json(ChatMessageSent::format($message, $user->id), 201);
    }
}
