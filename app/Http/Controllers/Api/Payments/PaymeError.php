<?php

namespace App\Http\Controllers\Api\Payments;

use RuntimeException;

/**
 * Payme JSON-RPC xatosi (kod hujjatdagi ro'yxatdan).
 */
class PaymeError extends RuntimeException
{
    public function __construct(int $code, string $message, public ?string $data = null)
    {
        parent::__construct($message, $code);
    }
}
