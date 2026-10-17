<?php

namespace App\Enums;

enum DriverStatus: string
{
    case Offline = 'offline';
    case Free = 'free';
    case Busy = 'busy';
}
