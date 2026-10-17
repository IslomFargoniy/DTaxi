<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Yangi o'rnatilgan tizim (superadmin yo'q): har qanday sahifa sozlash ustasiga yo'naltiriladi (reja §0).
 */
class EnsureSetupComplete
{
    public static function isComplete(): bool
    {
        // Bir marta tugagach qaytib tekshirilmaydi
        if (Cache::get('setup.complete')) {
            return true;
        }

        $complete = Schema::hasTable('roles')
            && User::query()->whereHas('roles', fn ($q) => $q->where('name', Role::Superadmin->value))->exists();
        if ($complete) {
            Cache::forever('setup.complete', true);
        }

        return $complete;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('setup.*') && ! $request->is('driver-app*', 'up', 'map/*') && ! self::isComplete()) {
            return redirect()->route('setup.show');
        }

        return $next($request);
    }
}
