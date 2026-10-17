<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SIP trunk (Sarkor va h.k.) — firma o'zi oladi (qaror #40), panelda kiritiladi (§5.5)
        Schema::create('sip_trunks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32)->unique();
            $table->string('title');
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(5060);
            $table->string('transport', 32)->default('transport-udp');
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->string('from_user')->nullable();
            // Kanallar (qaror #13): jami / kiruvchi / chiquvchi
            $table->unsignedSmallInteger('max_channels')->default(4);
            $table->unsignedSmallInteger('max_inbound')->nullable();
            $table->unsignedSmallInteger('max_outbound')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Raqamlar (DID) ↔ filiallar — ko'pdan-ko'p (qaror #15, §5.2a)
        Schema::create('phone_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sip_trunk_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20)->unique();
            $table->string('name')->nullable();
            $table->string('routing_mode', 20)->default('shared_queue');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('branch_phone_number', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('phone_number_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->unsignedTinyInteger('ivr_digit')->nullable();
            $table->primary(['branch_id', 'phone_number_id']);
        });

        Schema::table('branches', function (Blueprint $table) {
            // Filial ulushi (qaror #13); null — trunk limiti
            $table->unsignedSmallInteger('max_channels')->nullable();
            $table->unsignedSmallInteger('max_auto_calls')->default(1);
        });

        Schema::table('users', function (Blueprint $table) {
            // WebRTC ichki raqam (§5.5): operatorlar va haydovchilar
            $table->string('sip_extension', 32)->nullable()->unique();
            $table->text('sip_password')->nullable();
            // Operator holati: offline / ready / paused (busy — qo'ng'iroq paytida avtomatik)
            $table->string('operator_status', 16)->default('offline');
            $table->timestamp('operator_status_at')->nullable();
        });

        // Qo'ng'iroqlar (CDR): kiruvchi, operator callback, avto-qo'ng'iroq (TTS), haydovchi ↔ dispetcher
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->string('channel_id')->nullable()->unique();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 16);
            $table->string('kind', 16);
            $table->string('caller', 32)->nullable();
            $table->string('callee', 32)->nullable();
            $table->foreignId('phone_number_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sip_trunk_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20);
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->timestamp('started_at');
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('wait_seconds')->nullable();
            $table->unsignedInteger('talk_seconds')->nullable();
            $table->string('recording_path')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['caller', 'created_at']);
        });

        // Ovozli bo'laklar (§5.4, qaror #14): bir marta generatsiya qilinadi
        Schema::create('sound_clips', function (Blueprint $table) {
            $table->id();
            $table->string('category', 16);
            $table->string('key', 64);
            $table->string('text');
            $table->string('file_path')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['category', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sound_clips');
        Schema::dropIfExists('calls');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['sip_extension', 'sip_password', 'operator_status', 'operator_status_at']));
        Schema::table('branches', fn (Blueprint $t) => $t->dropColumn(['max_channels', 'max_auto_calls']));
        Schema::dropIfExists('branch_phone_number');
        Schema::dropIfExists('phone_numbers');
        Schema::dropIfExists('sip_trunks');
    }
};
