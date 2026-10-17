<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Firibgarlikka qarshi (§10.5): soxta GPS (mock location) oxirgi marta qachon aniqlangan
        Schema::table('drivers', function (Blueprint $table) {
            $table->timestamp('mock_location_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('drivers', fn (Blueprint $t) => $t->dropColumn('mock_location_at'));
    }
};
