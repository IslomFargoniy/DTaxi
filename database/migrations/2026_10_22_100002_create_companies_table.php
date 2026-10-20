<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Korporativ mijozlar: xodimlari safar uchun naqd to'lamaydi, firma oy oxirida hisob-faktura beradi
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('contact_phone', 20)->nullable();
            $table->unsignedTinyInteger('discount_percent')->default(0);
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            // Doimiy mijozga shaxsiy chegirma
            $table->unsignedTinyInteger('discount_percent')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('discount_percent');
        });
        Schema::dropIfExists('companies');
    }
};
