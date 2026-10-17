<?php

namespace App\Enums;

enum OfferResponse: string
{
    case Accepted = 'accepted';
    case TooLate = 'too_late';
    case Ignored = 'ignored';
    case Rejected = 'rejected';
}
