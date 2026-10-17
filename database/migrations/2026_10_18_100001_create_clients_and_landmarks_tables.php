<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mijoz butun nusxa bo'yicha yagona (1 nusxa = 1 firma), filiallar o'rtasida o'tsa ham bitta yozuv
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique();
            $table->string('name')->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_blacklisted')->default(false);
            $table->unsignedInteger('orders_count')->default(0);
            $table->foreignId('last_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->timestamps();
        });

        // Mo'ljallar hududga bog'lanadi — qo'shni filiallar bitta bazadan foydalanadi
        Schema::create('landmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->jsonb('aliases')->default('[]');
            // Qidiruv uchun: nom + sinonimlar, lotin yozuviga o'girilgan va kichik harfda
            $table->text('search')->default('');
            $table->geography('location', 'point', 4326);
            $table->string('category', 32)->nullable();
            $table->unsignedBigInteger('osm_id')->nullable()->unique();
            $table->unsignedInteger('usage_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->spatialIndex('location');
        });
        DB::statement('CREATE INDEX landmarks_search_trgm ON landmarks USING gin (search gin_trgm_ops)');

        Schema::create('client_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->foreignId('landmark_id')->nullable()->constrained()->nullOnDelete();
            $table->string('text');
            $table->geography('location', 'point', 4326);
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_addresses');
        Schema::dropIfExists('landmarks');
        Schema::dropIfExists('clients');
    }
};
