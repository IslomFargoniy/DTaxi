<?php

namespace App\Console\Commands;

use App\Models\SipTrunk;
use App\Services\Telephony\CallCenter;
use App\Services\Telephony\TrunkSync;
use Illuminate\Console\Command;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;

use function Ratchet\Client\connect;

/**
 * ARI WebSocket tinglovchisi (andoza: panel.1call.uz ari:listen). Supervisor ostida doim ishlaydi.
 */
class AriListen extends Command
{
    use DetectsLostConnections;

    protected $signature = 'ari:listen {--debug : Har bir ARI hodisasini chiqarish}';

    protected $description = "Asterisk ARI hodisalarini tinglash va qo'ng'iroqlarni boshqarish";

    public function handle(CallCenter $center, TrunkSync $trunks): int
    {
        DB::disableQueryLog();
        // Trunklarni Realtime'ga qayta yozish. Ochiq qo'ng'iroqlar bu yerda yopilmaydi: ular ulanishda
        // Asterisk kanallari bilan solishtiriladi — yangilanish (ari:restart) davom etayotgan suhbatni uzmasin
        $this->guard(fn () => SipTrunk::query()->each(fn (SipTrunk $trunk) => $trunks->sync($trunk)));

        $loop = Loop::get();
        $started = time();

        // Hodisa yo'qolgan bo'lsa ham (tarmoq uzilishi) osilib qolgan qo'ng'iroq bir daqiqada yopiladi
        $loop->addPeriodicTimer(60, fn () => $this->guard(fn () => $center->reconcile()));

        // Navbatni taqsimlash va heartbeat (monitoring "daemon ishlayaptimi" ko'rsatadi)
        $loop->addPeriodicTimer(2, function () use ($center, $started) {
            $this->guard(fn () => $center->tick());
            Cache::put('ari:listen:heartbeat', time(), 30);

            // `php artisan ari:restart` (yangilanishdan keyin): supervisor yangi kod bilan qayta ishga tushiradi
            if ((int) Cache::get('ari:restart', 0) > $started) {
                $this->info('Qayta ishga tushirish signali');
                Loop::get()->stop();
            }
        });

        $this->connect($center);
        $loop->run();

        return self::SUCCESS;
    }

    private function connect(CallCenter $center): void
    {
        $base = (string) preg_replace('#^http#', 'ws', rtrim((string) config('services.asterisk.ari_url'), '/'));
        $url = $base.'/ari/events?'.http_build_query([
            'app' => config('services.asterisk.app'),
            'api_key' => config('services.asterisk.ari_user').':'.config('services.asterisk.ari_password'),
        ]);

        connect($url)->then(function (WebSocket $conn) use ($center) {
            $this->info('ARI ulandi');
            // Uzilish paytida o'tib ketgan hodisalar o'rniga — ochiq qo'ng'iroqlarni haqiqiy kanallar bilan solishtirish
            $this->guard(fn () => $center->reconcile());

            $conn->on('message', function ($message) use ($center) {
                $event = json_decode((string) $message, true);
                if (is_array($event)) {
                    if ($this->option('debug')) {
                        $this->line(sprintf('%s %s %s', $event['type'] ?? '?', data_get($event, 'channel.id', data_get($event, 'playback.id', '')), json_encode($event['args'] ?? '')));
                    }
                    $this->guard(fn () => $center->handle($event));
                }
            });

            $conn->on('close', function ($code = null, $reason = null) use ($center) {
                $this->error("ARI uzildi ({$code}): {$reason}. 3 soniyadan keyin qayta ulanish…");
                Loop::get()->addTimer(3, fn () => $this->connect($center));
            });
        }, function (\Throwable $e) use ($center) {
            $this->error("ARI'ga ulanib bo'lmadi: {$e->getMessage()}. 3 soniyadan keyin qayta…");
            Loop::get()->addTimer(3, fn () => $this->connect($center));
        });
    }

    /**
     * Baza ulanishi uzilsa (uzoq ishlovchi jarayon) — qayta ulanib bir marta takrorlash.
     * Boshqa har qanday xato faqat yoziladi: tsikl o'lsa, barcha qo'ng'iroqlar boshqaruvsiz qoladi.
     */
    public function guard(callable $fn): void
    {
        // Daemon bitta uzun "so'rov": scoped xizmatlar (Settings keshi) har hodisada yangidan — panel o'zgarishlari ko'rinsin
        app()->forgetScopedInstances();

        try {
            try {
                $fn();
            } catch (QueryException $e) {
                // Faqat uzilgan ulanish: boshqa SQL xatosini takrorlash ishni ikki marta bajarardi (masalan ikkita qator)
                if (! $this->causedByLostConnection($e)) {
                    throw $e;
                }
                DB::reconnect();
                $fn();
            }
        } catch (\Throwable $e) {
            Log::error('ari:listen: '.$e->getMessage(), ['exception' => $e]);
        }
    }
}
