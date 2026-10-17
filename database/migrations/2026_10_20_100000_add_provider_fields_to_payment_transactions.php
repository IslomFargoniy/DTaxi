<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Payme: tranzaksiya vaqti (ms, provayderniki) va bekor qilish sababi; GetStatement uchun indeks
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_time')->nullable()->after('state');
            $table->smallInteger('cancel_reason')->nullable()->after('cancelled_at');
            $table->index(['provider', 'provider_time']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropIndex(['provider', 'provider_time']);
            $table->dropColumn(['provider_time', 'cancel_reason']);
        });
    }
};
