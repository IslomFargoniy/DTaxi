<?php

namespace App\Http\Middleware;

use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Til: foydalanuvchi tanlagani → sessiya → firmaning standart tili (qaror #44).
 */
class SetLocale
{
    public const LOCALES = ['uz', 'ru'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()->locale
            ?? $request->session()->get('locale')
            ?? app(Settings::class)->get('company.default_locale');

        app()->setLocale(in_array($locale, self::LOCALES, true) ? $locale : 'uz');

        return $next($request);
    }
}
