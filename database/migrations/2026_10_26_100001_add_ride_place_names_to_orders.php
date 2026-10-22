<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Tarixda har qator uchun PostGIS so'rovi bo'lmasin: joy nomi safar boshida/oxirida bir marta yoziladi.
            // Bo'sh satr — yaqinda nomli joy topilmadi (qayta qidirilmaydi)
            $table->string('start_name')->nullable();
            $table->string('finish_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['start_name', 'finish_name']);
        });
    }
};
