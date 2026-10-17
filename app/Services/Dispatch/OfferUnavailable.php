<?php

namespace App\Services\Dispatch;

use RuntimeException;

/**
 * Taklif endi mavjud emas: boshqa haydovchi oldi, buyurtma bekor qilindi yoki haydovchi band.
 */
class OfferUnavailable extends RuntimeException {}
