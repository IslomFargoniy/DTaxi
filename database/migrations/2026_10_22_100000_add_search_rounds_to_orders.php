<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Har qayta efirga chiqishda oshadi: eski raunddagi takliflar va kechikkan job'lar yangisiga xalal bermaydi
            $table->unsignedSmallInteger('search_round')->default(0);
            // Eng katta radiusda ham hech kim olmadi — operator panelida ajralib turadi
            $table->timestamp('search_exhausted_at')->nullable();
        });

        Schema::table('order_offers', function (Blueprint $table) {
            $table->unsignedSmallInteger('round')->default(0);
        });

        // Qo'ng'iroqda mijoz manzillari har safar o'qiladi; PostgreSQL tashqi kalitga indeks qo'ymaydi
        Schema::table('client_addresses', function (Blueprint $table) {
            $table->index(['client_id', 'last_used_at']);
        });

        DB::statement('CREATE INDEX IF NOT EXISTS driver_locations_recorded_at_index ON driver_locations (recorded_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS driver_locations_recorded_at_index');

        Schema::table('client_addresses', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'last_used_at']);
        });

        Schema::table('order_offers', function (Blueprint $table) {
            $table->dropColumn('round');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['search_round', 'search_exhausted_at']);
        });
    }
};
