<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\User;
use App\Services\Telephony\OperatorPool;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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

        // Uzoq vaqt ishlatilmagan token (yo'qolgan telefon) yaroqsiz — `expiration` yaratilgan vaqtdan sanaydi va smenadagi haydovchini chiqarib yuboradi
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid): bool {
            $lastUsed = $token->last_used_at ?? $token->created_at;

            return $isValid && $lastUsed !== null && $lastUsed->gt(now()->subDays((int) config('sanctum.idle_expiration_days', 60)));
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
