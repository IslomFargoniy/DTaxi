<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('region_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_name')->nullable();
            $table->text('greeting_text')->nullable();
            $table->geography('center', 'point', 4326)->nullable();
            $table->geography('service_area', 'multipolygon', 4326)->nullable();
            $table->string('timezone')->default('Asia/Tashkent');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->spatialIndex('service_area');
        });

        Schema::create('branch_user', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['branch_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('phone', 20)->nullable()->unique()->after('email');
            $table->string('locale', 5)->default('uz')->after('password');
            $table->boolean('is_active')->default(true)->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['phone', 'locale', 'is_active']);
        });
        Schema::dropIfExists('branch_user');
        Schema::dropIfExists('branches');
    }
};
