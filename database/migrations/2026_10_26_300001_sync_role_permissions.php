<?php

use Database\Seeders\RolesSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Yangi ruxsatlar (chat.drivers, landmarks.manage — admin) ishlab turgan firmalarga ham yetsin:
 * `ibtaxi:update` faqat migratsiyalarni ishga tushiradi, rollar esa `App\Enums\Role` dan sinxronlanadi.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(RolesSeeder::class)->run();
    }
};
