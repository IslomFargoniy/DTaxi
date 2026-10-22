<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kassa smenasi hisoboti, komissiya/to'lov hisobotlari va telefon bo'yicha qidiruv (LIKE '%...%') uchun indekslar;
     * haydovchi yoki uning foydalanuvchisi o'chirilsa pul tarixi jimgina yo'qolmasin — o'chirish rad etiladi.
     */
    public function up(): void
    {
        Schema::table('driver_transactions', function (Blueprint $table) {
            $table->index('cash_shift_id');
            $table->index(['type', 'created_at']);
        });
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->index('driver_id');
        });

        // Qism bo'yicha qidiruv (oxirgi raqamlar) — oddiy B-tree indeks ishlatilmaydi
        DB::statement('CREATE INDEX clients_phone_trgm ON clients USING gin (phone gin_trgm_ops)');
        DB::statement('CREATE INDEX users_phone_trgm ON users USING gin (phone gin_trgm_ops)');
        DB::statement('CREATE INDEX calls_caller_trgm ON calls USING gin (caller gin_trgm_ops)');
        DB::statement('CREATE INDEX calls_callee_trgm ON calls USING gin (callee gin_trgm_ops)');

        Schema::table('driver_transactions', function (Blueprint $table) {
            $table->dropForeign(['driver_id']);
            $table->foreign('driver_id')->references('id')->on('drivers')->restrictOnDelete();
        });
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        Schema::table('driver_transactions', function (Blueprint $table) {
            $table->dropForeign(['driver_id']);
            $table->foreign('driver_id')->references('id')->on('drivers')->cascadeOnDelete();
        });

        foreach (['clients_phone_trgm', 'users_phone_trgm', 'calls_caller_trgm', 'calls_callee_trgm'] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropIndex(['driver_id']);
        });
        Schema::table('driver_transactions', function (Blueprint $table) {
            $table->dropIndex(['cash_shift_id']);
            $table->dropIndex(['type', 'created_at']);
        });
    }
};
