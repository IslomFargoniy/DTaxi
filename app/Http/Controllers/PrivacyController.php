<?php

namespace App\Http\Controllers;

use App\Models\PhoneNumber;
use App\Support\Settings;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mijoz ilovasining maxfiylik siyosati (qaror #71) — Google Play talabi: ochiq manzil (https://<domen>/privacy).
 */
class PrivacyController extends Controller
{
    public function __invoke(Settings $settings): Response
    {
        return Inertia::render('privacy', [
            'company' => $settings->get('company.name'),
            'phone' => PhoneNumber::forBranch(null)?->number,
        ]);
    }
}
