<?php

namespace App\Console\Commands;

use App\Models\Call;
use App\Models\SipTrunk;
use App\Services\Telephony\CallCenter;
use App\Services\Telephony\TrunkSync;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;

use function Ratchet\Client\connect;

/**
 * ARI WebSocket tinglovchisi (andoza: panel.1call.uz ari:listen). Supervisor ostida doim ishlaydi.
 */
class AriListen extends Command
{
    protected $signature = 'ari:listen';

    protected $description = "Asterisk ARI hodisalarini tinglash va qo'ng'iroqlarni boshqarish";

    public function handle(CallCenter $center, TrunkSync $trunks): int
    {
        DB::disableQueryLog();
        $this->recoverAfterRestart($trunks);

        $loop = Loop::get();

        // Navbatni taqsimlash va heartbeat (monitoring "daemon ishlayaptimi" ko'rsatadi)
        $loop->addPeriodicTimer(2, function () use ($center) {
            $this->guard(fn () => $center->tick());
            Cache::put('ari:listen:heartbeat', time(), 30);
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

            $conn->on('message', function ($message) use ($center) {
                $event = json_decode((string) $message, true);
                if (is_array($event)) {
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
     * Daemon qayta ishga tushganda: "faol" bo'lib qolgan qo'ng'iroqlarni yopish, trunklarni Realtime'ga qayta yozish.
     */
    private function recoverAfterRestart(TrunkSync $trunks): void
    {
        Call::query()->withoutGlobalScopes()->whereIn('status', Call::ACTIVE)->update([
            'status' => DB::raw("CASE WHEN status = 'answered' THEN 'completed' WHEN direction = 'inbound' THEN 'missed' ELSE 'no_answer' END"),
            'ended_at' => now(),
        ]);

        SipTrunk::query()->each(fn (SipTrunk $trunk) => $trunks->sync($trunk));
    }

    /**
     * Baza ulanishi uzilsa (uzoq ishlovchi jarayon) — qayta ulanib bir marta takrorlash.
     */
    private function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (QueryException $e) {
            DB::reconnect();
            $fn();
        }
    }
}
