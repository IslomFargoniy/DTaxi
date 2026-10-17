<?php

namespace App\Enums;

enum OrderSource: string
{
    case Phone = 'phone';
    case Ivr = 'ivr';
}
