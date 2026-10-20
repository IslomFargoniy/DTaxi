<?php

namespace App\Enums;

enum OfferResponse: string
{
    case Accepted = 'accepted';
    case TooLate = 'too_late';
    case Ignored = 'ignored';
    case Rejected = 'rejected';
    // Haydovchi qabul qilib, keyin bekor qildi — shu buyurtma unga qayta taklif qilinmaydi
    case Cancelled = 'cancelled';
}
