<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Haydovchi yo'lovchini haqiqatda qayerda oldi va qayerda tushirdi (buyurtmalar tarixi uchun)
            $table->geography('start_location', 'point', 4326)->nullable();
            $table->geography('finish_location', 'point', 4326)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['start_location', 'finish_location']);
        });
    }
};
