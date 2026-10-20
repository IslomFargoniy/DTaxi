<?php

namespace App\Enums;

enum TransactionType: string
{
    case Commission = 'commission';
    case Topup = 'topup';
    case Penalty = 'penalty';
    case Bonus = 'bonus';
    case Adjustment = 'adjustment';
    // Korporativ safar narxi — haydovchi naqd olmagan
    case Corporate = 'corporate';
}
