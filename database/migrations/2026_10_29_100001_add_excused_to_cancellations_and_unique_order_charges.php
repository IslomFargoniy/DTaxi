<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Audit B7, B9: uzrli bekor qilish alohida belgi (jarima 0 bo'lsa ham bloklash hisobi to'g'ri);
// bitta buyurtmaga komissiya/korporativ to'lov ikki marta yozilmasin — baza darajasida
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_cancellations', function (Blueprint $table) {
            $table->boolean('excused')->default(false);
        });
        // Eskilarida uzr faqat jarima 0 bilan belgilanardi
        DB::table('order_cancellations')->where('penalty_amount', 0)->update(['excused' => true]);

        // Oldin takror yozilgan bo'lsa indeks yaratilmaydi: moliyaviy yozuvlar migratsiyada o'chirilmaydi, yangilanish ham to'xtamaydi
        $duplicates = DB::table('driver_transactions')->select('order_id', 'type')->whereIn('type', ['commission', 'corporate'])->whereNotNull('order_id')
            ->groupBy('order_id', 'type')->havingRaw('count(*) > 1')->first() !== null;
        if (! $duplicates) {
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS driver_transactions_order_charge_unique ON driver_transactions (order_id, type) WHERE type IN ('commission', 'corporate') AND order_id IS NOT NULL");
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS driver_transactions_order_charge_unique');
        Schema::table('order_cancellations', function (Blueprint $table) {
            $table->dropColumn('excused');
        });
    }
};
