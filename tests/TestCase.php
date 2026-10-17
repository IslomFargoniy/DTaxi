<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * DatabaseTruncation PostGIS tizim jadvaliga tegmasin.
     *
     * @var list<string>
     */
    protected $exceptTables = ['spatial_ref_sys'];

    protected function setUp(): void
    {
        parent::setUp();

        // Testlar frontend build'iga bog'liq bo'lmasin
        $this->withoutVite();
        // Sozlash ustasi o'tilgan deb hisoblanadi (alohida SetupTest'da tekshiriladi)
        Cache::forever('setup.complete', true);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
