<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mijoz qaysi raqamga qo'ng'iroq qilgan — statistika va avto-qo'ng'iroq Caller ID (§5.2a)
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('phone_number_id')->nullable()->after('source')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropConstrainedForeignId('phone_number_id'));
    }
};
