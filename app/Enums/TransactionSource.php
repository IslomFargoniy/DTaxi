<?php

namespace App\Enums;

enum TransactionSource: string
{
    case Click = 'click';
    case Payme = 'payme';
    case Cash = 'cash';
    case System = 'system';
}
