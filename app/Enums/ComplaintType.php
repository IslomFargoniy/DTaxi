<?php

namespace App\Enums;

enum ComplaintType: string
{
    case Complaint = 'complaint';
    case LostItem = 'lost_item';
    case Praise = 'praise';
}
