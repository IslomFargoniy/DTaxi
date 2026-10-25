<?php

namespace App\Enums;

enum OrderSource: string
{
    case Phone = 'phone';
    case Ivr = 'ivr';
    // Mijoz ilovasi (qaror #71)
    case App = 'app';
}
