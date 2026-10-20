<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Faolsizlantirilgan xodim yoki haydovchi ochiq sessiya/token bilan ham ishlay olmaydi
 * (parol, passkey va ilova tokeni — hammasi shu yerdan o'tadi).
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        // api guruhi auth:sanctum dan oldin ishlashi mumkin — token egasini to'g'ridan-to'g'ri olamiz
        $user = $request->is('api/*') ? Auth::guard('sanctum')->user() : $request->user();

        if ($user instanceof User && $user->is_active === false) {
            if ($request->is('api/*')) {
                $user->tokens()->delete();

                return response()->json(['message' => __('Your account is disabled.')], 401);
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', __('Your account is disabled.'));
        }

        return $next($request);
    }
}
