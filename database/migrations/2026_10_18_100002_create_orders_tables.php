<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('client_id')->constrained();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tariff_class_id')->constrained();
            $table->string('status', 32)->default('new');
            $table->string('source', 16)->default('phone');

            $table->string('pickup_text');
            $table->geography('pickup_location', 'point', 4326);
            $table->foreignId('pickup_landmark_id')->nullable()->constrained('landmarks')->nullOnDelete();
            $table->string('dropoff_text')->nullable();
            $table->geography('dropoff_location', 'point', 4326)->nullable();
            $table->text('comment')->nullable();

            // Buyurtma paytidagi tarif — keyin narx o'zgarsa ham shu bo'yicha hisoblanadi
            $table->jsonb('tariff_snapshot');
            $table->unsignedInteger('price_estimate')->nullable();
            $table->unsignedInteger('price_final')->nullable();
            $table->jsonb('price_breakdown')->nullable();
            $table->unsignedInteger('distance_m')->nullable();
            $table->unsignedInteger('duration_s')->nullable();
            $table->unsignedInteger('waiting_s')->nullable();
            $table->unsignedInteger('commission')->nullable();

            $table->unsignedSmallInteger('search_radius_m')->default(3000);
            $table->timestamp('searching_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'created_at']);
            $table->index(['driver_id', 'status']);
            $table->index(['client_id', 'created_at']);
        });

        Schema::create('order_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('distance_m')->nullable();
            // Operator qo'lda tayinlagan taklif: rad etish/javobsizlik jarimaga olib keladi (qaror #29)
            $table->boolean('is_direct')->default(false);
            $table->string('response', 16)->nullable();
            $table->timestamp('offered_at');
            $table->timestamp('responded_at')->nullable();

            $table->unique(['order_id', 'driver_id']);
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('payload')->nullable();
            $table->timestamp('created_at');

            $table->index(['order_id', 'created_at']);
        });

        Schema::create('order_deliveries', function (Blueprint $table) {
            $table->foreignId('order_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('sender_name')->nullable();
            $table->string('sender_phone', 20)->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_phone', 20)->nullable();
            $table->text('description')->nullable();
        });

        Schema::create('order_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->string('reason');
            $table->unsignedInteger('penalty_amount')->default(0);
            $table->foreignId('penalty_waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_cancellations');
        Schema::dropIfExists('order_deliveries');
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('order_offers');
        Schema::dropIfExists('orders');
    }
};
