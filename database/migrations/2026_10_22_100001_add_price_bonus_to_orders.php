<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Operator qo'shgan ustama: hech kim olmayotgan buyurtmani "qimmatroq" qilib qayta efirga chiqarish
            $table->unsignedInteger('price_bonus')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('price_bonus');
        });
    }
};
