<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tariff_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('icon')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_delivery')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Narx: boshlang'ich narx (ichida base_km) + har km; qo'shimcha qoidalar yoqiladi/o'chiriladi (qaror #21, #33)
        Schema::create('tariffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tariff_class_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('base_fare');
            $table->decimal('base_km', 6, 2);
            $table->unsignedInteger('per_km');
            $table->unsignedInteger('rounding_step')->default(500);
            $table->boolean('waiting_enabled')->default(false);
            $table->unsignedSmallInteger('waiting_free_min')->default(3);
            $table->unsignedInteger('waiting_per_min')->default(0);
            $table->boolean('night_enabled')->default(false);
            $table->time('night_from')->default('23:00');
            $table->time('night_to')->default('06:00');
            $table->decimal('night_coeff', 4, 2)->default(1.2);
            $table->boolean('outside_enabled')->default(false);
            $table->unsignedInteger('outside_per_km')->default(0);
            $table->timestamps();
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            // Pozivnoy — butun nusxada yagona; Click/Payme'da to'ldirish ID'si
            $table->string('callsign', 16)->unique();
            $table->string('status', 16)->default('offline');
            $table->bigInteger('balance')->default(0);
            $table->unsignedSmallInteger('rating')->default(100);
            $table->timestamp('blocked_until')->nullable();
            $table->string('license_no')->nullable();
            $table->date('license_expires_at')->nullable();
            $table->boolean('is_approved')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->geography('last_location', 'point', 4326)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->spatialIndex('last_location');
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->string('brand');
            $table->string('model');
            $table->string('color');
            $table->string('plate_number', 16)->unique();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('tech_passport_no')->nullable();
            // Haydovchining joriy (faol) mashinasi; eskilari buyurtmalar tarixi uchun qoladi
            $table->boolean('is_current')->default(true);
            $table->boolean('is_approved')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_tariff_class', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tariff_class_id')->constrained()->cascadeOnDelete();
            $table->primary(['vehicle_id', 'tariff_class_id']);
        });

        Schema::create('driver_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('file_path');
            $table->date('expires_at')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamps();
        });

        Schema::create('driver_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->geography('location', 'point', 4326);
            $table->unsignedSmallInteger('speed')->nullable();
            $table->unsignedSmallInteger('heading')->nullable();
            $table->unsignedSmallInteger('accuracy')->nullable();
            $table->timestamp('recorded_at');

            $table->index(['driver_id', 'recorded_at']);
        });

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16)->default('android');
            $table->string('token', 512)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('driver_locations');
        Schema::dropIfExists('driver_documents');
        Schema::dropIfExists('vehicle_tariff_class');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('tariffs');
        Schema::dropIfExists('tariff_classes');
    }
};
