<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ish vaqti (§5.5 IVR / ish vaqti): null — kecha-kunduz; {"mon": {"from": "07:00", "to": "23:00"}, "sun": null, ...}
        Schema::table('branches', function (Blueprint $table) {
            $table->jsonb('work_schedule')->nullable();
            $table->jsonb('holidays')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('branches', fn (Blueprint $t) => $t->dropColumn(['work_schedule', 'holidays']));
    }
};
