<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * Bajarilgan to'lovni qaytarib bo'lmaydi: haydovchi pulni ishlatib bo'lgan (Payme: -31007).
 */
class PaymentNotCancellable extends RuntimeException {}
