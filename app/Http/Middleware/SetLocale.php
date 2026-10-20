<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Til: foydalanuvchi tanlagani → sessiya → firmaning standart tili (qaror #44).
 */
class SetLocale
{
    /** uz — o'zbek (lotin), oz — o'zbek (kirill), ru, en */
    public const LOCALES = ['uz', 'oz', 'ru', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        // api guruhida auth:sanctum hali ishlamagan bo'lishi mumkin — token egasi to'g'ridan-to'g'ri
        $user = $request->is('api/*') ? Auth::guard('sanctum')->user() : $request->user();
        $locale = ($user instanceof User ? $user->locale : null)
            ?? ($request->is('api/*') ? $request->getPreferredLanguage(self::LOCALES) : null)
            ?? ($request->hasSession() ? $request->session()->get('locale') : null)
            ?? app(Settings::class)->get('company.default_locale');

        app()->setLocale(in_array($locale, self::LOCALES, true) ? $locale : 'uz');

        return $next($request);
    }
}
