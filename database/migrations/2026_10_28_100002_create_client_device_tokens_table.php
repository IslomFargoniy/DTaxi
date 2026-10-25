<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mijoz ilovasi push tokenlari (qaror #71) — haydovchilarnikidan (device_tokens → users) alohida
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_device_tokens');
    }
};
