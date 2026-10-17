<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('cashier_id')->constrained('users');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('total_cash')->default(0);
            $table->foreignId('admin_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('driver_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_shift_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16);
            $table->string('source', 16);
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('comment')->nullable();
            $table->timestamp('created_at');

            $table->index(['driver_id', 'created_at']);
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 16);
            $table->string('provider_transaction_id');
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('state', 16);
            $table->jsonb('raw_request')->nullable();
            $table->timestamp('performed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_transaction_id']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('body')->nullable();
            $table->string('attachment_path')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at');

            $table->index(['driver_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('driver_transactions');
        Schema::dropIfExists('cash_shifts');
    }
};
