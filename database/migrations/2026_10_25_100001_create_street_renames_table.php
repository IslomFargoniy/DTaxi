<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Qayta nomlangan ko'chalar: OSM'da eski nom qolgan — xarita yozuvi, navigatsiya va qidiruvda yangi nom ko'rsatiladi.
     * region_id — bir xil nomli ko'cha turli shaharlarda bo'lsa, faqat shu hududda (bo'sh — hamma joyda).
     */
    public function up(): void
    {
        Schema::create('street_renames', function (Blueprint $table) {
            $table->id();
            $table->string('old_name', 150);
            $table->string('new_name', 150);
            $table->foreignId('region_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // Hududsiz (NULL) yozuv ham bitta bo'lishi kerak
            $table->unique(['old_name', 'region_id'])->nullsNotDistinct();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('street_renames');
    }
};
