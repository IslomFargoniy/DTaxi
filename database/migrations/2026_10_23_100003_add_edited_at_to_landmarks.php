<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin qo'lda tuzatgan mo'ljal: keyingi OSM importi nomi va joyini eski holatga qaytarmaydi
     * (O'zbekistonda OSM'dagi ko'p ko'cha nomlari eskirgan).
     */
    public function up(): void
    {
        Schema::table('landmarks', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('landmarks', function (Blueprint $table) {
            $table->dropColumn('edited_at');
        });
    }
};
