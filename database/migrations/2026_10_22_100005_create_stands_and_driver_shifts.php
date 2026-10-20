<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Navbat joyi (aeroport, vokzal, bozor): shu yerda turgan bo'sh haydovchilar kelish tartibida navbatda
        Schema::create('stands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->geography('location', 'point', 4326);
            $table->unsignedInteger('radius_m')->default(200);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->spatialIndex('location');
        });

        Schema::create('stand_queue', function (Blueprint $table) {
            $table->foreignId('driver_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('stand_id')->constrained()->cascadeOnDelete();
            $table->timestamp('joined_at');

            $table->index(['stand_id', 'joined_at']);
        });

        // Smena: liniyaga chiqqandan chiqqungacha — haydovchi va admin uchun hisobot
        Schema::create('driver_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();

            $table->index(['driver_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_shifts');
        Schema::dropIfExists('stand_queue');
        Schema::dropIfExists('stands');
    }
};
