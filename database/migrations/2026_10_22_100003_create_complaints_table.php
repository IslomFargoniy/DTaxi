<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Shikoyat, unutilgan buyum va minnatdorchilik — buyurtma va suhbat yozuviga bog'lanadi
        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16);
            $table->text('text');
            $table->string('status', 16)->default('open');
            $table->text('resolution')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'created_at']);
        });

        // Uzrli deb topilgan jarima qaytariladi — asl yozuv o'chirilmaydi, belgilanadi
        Schema::table('driver_transactions', function (Blueprint $table) {
            $table->timestamp('excused_at')->nullable();
            $table->foreignId('excused_by')->nullable()->constrained('users')->nullOnDelete();
        });

        // Mijoz bahosi (1–5) — safardan keyingi qo'ng'iroqda yoki operator kiritadi
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('client_rating')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('client_rating');
        });
        Schema::table('driver_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('excused_by');
            $table->dropColumn('excused_at');
        });
        Schema::dropIfExists('complaints');
    }
};
