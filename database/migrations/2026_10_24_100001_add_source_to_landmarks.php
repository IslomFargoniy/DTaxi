<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mo'ljal manbasi: OSM'dan tashqari ochiq ma'lumotlar (Overture, Wikidata) ham import qilinadi —
     * qayta importda yangilash uchun manbadagi id (source, external_id) bo'yicha topiladi (qaror #60).
     */
    public function up(): void
    {
        Schema::table('landmarks', function (Blueprint $table) {
            $table->string('source', 16)->default('osm');
            $table->string('external_id', 64)->nullable();
            $table->unique(['source', 'external_id']);
        });
        // Operator/admin qo'lda qo'shganlari OSM emas
        DB::table('landmarks')->whereNull('osm_id')->update(['source' => 'manual']);
    }

    public function down(): void
    {
        Schema::table('landmarks', function (Blueprint $table) {
            $table->dropUnique(['source', 'external_id']);
            $table->dropColumn(['source', 'external_id']);
        });
    }
};
