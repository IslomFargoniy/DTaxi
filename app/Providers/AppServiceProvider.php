<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\User;
use App\Services\Telephony\OperatorPool;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(Settings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Superadmin hamma ruxsatga ega
        Gate::before(fn (User $user) => $user->isSuperadmin() ? true : null);

        // Haydovchi kirishi: IP bo'yicha ham, raqam bo'yicha ham — bitta raqam parolini ko'p IP'dan tanlab bo'lmasin
        RateLimiter::for('driver-login', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.$request->ip()),
            Limit::perMinute(5)->by('phone:'.Client::normalizePhone($request->string('phone')->toString())),
        ]);

        // Mijoz ilovasi kodi (qaror #71): har kod — trunk orqali qo'ng'iroq (pul va liniya) — raqam va IP bo'yicha qattiq
        RateLimiter::for('client-code', fn (Request $request) => [
            Limit::perHour(3)->by('phone:'.Client::normalizePhone($request->string('phone')->toString())),
            Limit::perHour(10)->by('ip:'.$request->ip()),
        ]);
        // Kodni tanlash: IP bo'yicha va raqam bo'yicha (ko'p IP'dan bitta raqamga ham)
        RateLimiter::for('client-verify', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.$request->ip()),
            Limit::perHour(20)->by('verify-phone:'.Client::normalizePhone($request->string('phone')->toString())),
        ]);
        // Nomli cheklov — kalit "client:<id>": oddiy throttle:N,1 faqat ID bo'yicha sanaydi va mijoz bilan bir xil ID'li
        // haydovchining GPS so'rovlari (har 4 s) mijozning buyurtmasini 429 qilib qo'yardi
        RateLimiter::for('client-lookup', fn (Request $request) => Limit::perMinute(120)->by('client:'.Auth::guard('sanctum')->id()));
        RateLimiter::for('client-order', fn (Request $request) => Limit::perMinute(10)->by('client-order:'.Auth::guard('sanctum')->id()));
        RateLimiter::for('client-call', fn (Request $request) => Limit::perMinute(6)->by('client-call:'.Auth::guard('sanctum')->id()));

        // Uzoq vaqt ishlatilmagan token (yo'qolgan telefon) yaroqsiz — `expiration` yaratilgan vaqtdan sanaydi va smenadagi haydovchini chiqarib yuboradi
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid): bool {
            $lastUsed = $token->last_used_at ?? $token->created_at;

            return $isValid && $lastUsed !== null && $lastUsed->gt(now()->subDays((int) config('sanctum.idle_expiration_days', 60)));
        });

        // Har ulanishda: mo'ljal qidiruvidagi "<%" chegarasi (Landmark::search) va JIT o'chiq — qisqa so'rovlarda
        // kompilyatsiya so'rovning o'zidan uzoq davom etardi (qidiruvda 2.5 s dan 1.5 s)
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) {
            if ($event->connection->getDriverName() === 'pgsql') {
                $event->connection->statement('SET pg_trgm.word_similarity_threshold = 0.4');
                $event->connection->statement('SET jit = off');
            }
        });

        // Paneldan chiqqan operatorga navbatdagi qo'ng'iroqlar yuborilmasin
        Event::listen(Logout::class, function (Logout $event) {
            if ($event->user instanceof User && ($event->user->operator_status ?? 'offline') !== 'offline') {
                app(OperatorPool::class)->setStatus($event->user, 'offline');
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
