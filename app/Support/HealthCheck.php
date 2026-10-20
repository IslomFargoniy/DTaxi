<?php

namespace App\Support;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Models\SipTrunk;
use App\Services\Telephony\AriClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/**
 * Tizim holati (§10.9): har 5 daqiqada tekshiriladi, muammolar superadmin paneliga va (sozlangan bo'lsa)
 * firmaning Telegram botiga yuboriladi — faqat holat o'zgarganda.
 */
class HealthCheck
{
    public const CACHE_KEY = 'health.problems';

    public function __construct(private AriClient $ari) {}

    /**
     * @return array<string, string> muammo kaliti → matn (inglizcha kalit, panelda tarjima qilinadi)
     */
    public function run(): array
    {
        $problems = [];

        $states = rescue(fn () => $this->ari->endpointStates(), [], false);
        if (! $this->ari->ping()) {
            $problems['asterisk'] = 'Asterisk is not reachable (ARI). Calls are not working.';
        } else {
            foreach (SipTrunk::query()->where('is_active', true)->get() as $trunk) {
                if (($states[$trunk->endpoint()] ?? null) !== 'online') {
                    $problems["trunk.{$trunk->id}"] = "SIP trunk is not connected: {$trunk->title}";
                }
            }
        }

        if (SipTrunk::query()->exists() && Cache::get('ari:listen:heartbeat') === null) {
            $problems['ari'] = 'Call service (ari:listen) is not running';
        }

        $osrm = rescue(fn () => Http::timeout(3)->get(rtrim((string) config('services.osrm.url'), '/').'/nearest/v1/driving/71.78,40.38')->status(), 0, false);
        if ($osrm === 0 || $osrm >= 500) {
            $problems['osrm'] = 'Routing service (OSRM) is not responding — distances are estimated';
        }

        if (rescue(fn () => app(MasterSupervisorRepository::class)->all(), [], false) === []) {
            $problems['horizon'] = 'Queue workers (Horizon) are not running';
        }

        $free = @disk_free_space(storage_path());
        if ($free !== false && $free < 5 * 1024 ** 3) {
            $problems['disk'] = 'Low disk space: less than 5 GB free';
        }

        $backups = File::glob(storage_path('app/private/backups/*.dump'));
        $latest = $backups === [] ? 0 : max(array_map('filemtime', $backups));
        if (app()->isProduction() && $latest < now()->subHours(26)->getTimestamp()) {
            $problems['backup'] = 'No database backup in the last 24 hours';
        }

        if (Cache::get('backup.upload_error')) {
            $problems['backup_upload'] = 'Backup was not copied to the backup server';
        }

        $this->notifyChanges($problems);
        Cache::forever(self::CACHE_KEY, $problems);

        return $problems;
    }

    /**
     * @return array<string, string>
     */
    public static function problems(): array
    {
        /** @var array<string, string> */
        return Cache::get(self::CACHE_KEY, []);
    }

    /**
     * @param  array<string, string>  $problems
     */
    private function notifyChanges(array $problems): void
    {
        $previous = self::problems();
        $new = array_diff_key($problems, $previous);
        $fixed = array_diff_key($previous, $problems);
        if ($new === [] && $fixed === []) {
            return;
        }

        $token = Integration::credential(IntegrationProvider::Telegram, 'bot_token');
        $chat = Integration::credential(IntegrationProvider::Telegram, 'chat_id');
        if (! $token || ! $chat) {
            return;
        }

        $company = (string) app(Settings::class)->get('company.name');
        $lines = [
            ...array_map(fn (string $m) => "🔴 {$m}", array_values($new)),
            ...array_map(fn (string $m) => "✅ {$m}", array_values($fixed)),
        ];

        rescue(fn () => Http::timeout(5)->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chat,
            'text' => "{$company}\n".implode("\n", $lines),
        ]), fn () => Log::warning('Telegram ogohlantirishi yuborilmadi'), false);
    }
}
