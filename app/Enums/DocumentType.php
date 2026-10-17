<?php

namespace App\Enums;

enum DocumentType: string
{
    case License = 'license';
    case TechPassport = 'tech_passport';
    case Passport = 'passport';
    case VehiclePhoto = 'vehicle_photo';
}
