<?php

namespace App\Enums;

enum OrderStatus: string
{
    case New = 'new';
    case Searching = 'searching';
    case Assigned = 'assigned';
    case Arrived = 'arrived';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case CancelledByClient = 'cancelled_by_client';
    case CancelledByOperator = 'cancelled_by_operator';
    case CancelledByDriver = 'cancelled_by_driver';
    case NoDriverFound = 'no_driver_found';

    public function isFinished(): bool
    {
        return in_array($this, [
            self::Completed,
            self::CancelledByClient,
            self::CancelledByOperator,
            self::CancelledByDriver,
            self::NoDriverFound,
        ], true);
    }

    public function isActive(): bool
    {
        return ! $this->isFinished();
    }
}
