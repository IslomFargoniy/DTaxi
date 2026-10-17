<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Panelda o'zgartiriladigan sozlamalar: branch_id = null — butun tizim uchun
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->jsonb('value')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'key']);
        });
        // PostgreSQL'da NULL'lar unique'ni buzmaydi — global sozlama uchun alohida indeks
        DB::statement('CREATE UNIQUE INDEX settings_global_key_unique ON settings (key) WHERE branch_id IS NULL');

        // Firmaning tashqi xizmatlari (Click, Payme, SpeechKit, Firebase) — kalitlar shifrlangan
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique();
            $table->boolean('is_enabled')->default(false);
            $table->text('credentials')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->boolean('last_check_ok')->nullable();
            $table->string('last_check_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
        Schema::dropIfExists('settings');
    }
};
