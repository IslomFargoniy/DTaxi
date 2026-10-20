<?php

namespace App\Support;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Firmaning Telegram guruhiga ogohlantirish (Integratsiyalar → Telegram). Sozlanmagan bo'lsa — jim.
 */
class TelegramAlert
{
    public static function send(string $text): void
    {
        rescue(function () use ($text) {
            $token = Integration::credential(IntegrationProvider::Telegram, 'bot_token');
            $chat = Integration::credential(IntegrationProvider::Telegram, 'chat_id');
            if (! $token || ! $chat) {
                return;
            }

            $company = (string) app(Settings::class)->get('company.name');
            Http::timeout(5)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chat,
                'text' => Str::limit("{$company}\n{$text}", 4000),
            ])->throw();
        }, fn () => Log::warning('Telegram ogohlantirishi yuborilmadi'), false);
    }

    /**
     * Kutilmagan xato: firma serverlaridagi muammoni dasturchi ham ko'rishi uchun (guruhga qo'shilsa).
     * Bir xil joydagi xato 30 daqiqada bir marta yuboriladi — tsikldagi xato guruhni to'ldirmasin.
     */
    public static function exception(Throwable $e): void
    {
        rescue(function () use ($e) {
            $key = 'telegram-alert:'.sha1($e::class.$e->getFile().$e->getLine());
            if (! Cache::add($key, true, now()->addMinutes(30))) {
                return;
            }

            $where = Str::after($e->getFile(), base_path().'/').':'.$e->getLine();
            $url = app()->runningInConsole() ? 'console' : request()->method().' '.request()->path();
            self::send('⚠️ Xato: '.$e::class."\n".Str::limit($e->getMessage(), 500)."\n{$where}\n{$url}");
        }, report: false);
    }
}
