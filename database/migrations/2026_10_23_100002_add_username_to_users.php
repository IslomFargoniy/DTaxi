<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Xodimlar login (username) bilan kiradi; email ixtiyoriy (ko'p kassir/operatorning email'i yo'q).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->unique()->after('name');
            $table->string('email')->nullable()->change();
        });

        // Mavjud foydalanuvchilar: email'ning @ dan oldingi qismi, takrorlansa — id bilan
        DB::statement(<<<'SQL'
            UPDATE users u SET username = CASE
                WHEN (SELECT count(*) FROM users o WHERE lower(split_part(o.email, '@', 1)) = lower(split_part(u.email, '@', 1))) > 1
                    THEN lower(split_part(u.email, '@', 1)) || '_' || u.id
                ELSE lower(split_part(u.email, '@', 1))
            END
            WHERE u.username IS NULL AND u.email IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('username');
        });
    }
};
