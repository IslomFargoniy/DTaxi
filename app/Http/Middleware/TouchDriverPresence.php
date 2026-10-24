<?php

namespace App\Http\Middleware;

use App\Support\DriverPresence;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Haydovchi ilovasining har so'rovi — "online" belgisi (chatda operator ko'radi).
 */
class TouchDriverPresence
{
    public function __construct(private readonly DriverPresence $presence) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $id = $request->user()?->getKey();
        if (is_int($id) && $response->isSuccessful()) {
            $this->presence->touch($id);
        }

        return $response;
    }
}
