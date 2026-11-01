<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            $table->decimal('discount_value', 15, 2)->nullable()->after('value')
                ->comment('Акционная цена (из 1С — <ЦенаЗаЕдиницуСоСкидкой>), null = акции нет');
        });
    }

    public function down(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            $table->dropColumn('discount_value');
        });
    }
};
