<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Filial xizmat hududi markaz atrofidagi aylana sifatida (metr). null — hudud shahar/tuman chegarasidan.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedInteger('service_radius_m')->nullable()->after('service_area');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('service_radius_m');
        });
    }
};
