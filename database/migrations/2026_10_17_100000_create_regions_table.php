<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O'zbekiston ma'muriy bo'linishi: viloyat → shahar / tuman (OSM'dan import qilinadi).
     */
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('regions')->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('name_uz');
            $table->string('name_ru')->nullable();
            $table->unsignedBigInteger('osm_id')->nullable()->unique();
            $table->geography('center', 'point', 4326)->nullable();
            $table->geography('boundary', 'multipolygon', 4326)->nullable();
            $table->timestamps();

            $table->index(['parent_id', 'type']);
            $table->spatialIndex('boundary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
    }
};
