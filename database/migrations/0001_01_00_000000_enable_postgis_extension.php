<?php

use Clickbar\Magellan\Schema\MagellanSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        MagellanSchema::enablePostgisIfNotExists($this->connection);
        // Mo'ljallar noaniq qidiruvi uchun (pg_trgm — "trusted" kengaytma, baza egasi yoqa oladi)
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }

    public function down(): void
    {
        MagellanSchema::disablePostgisIfExists($this->connection);
    }
};
