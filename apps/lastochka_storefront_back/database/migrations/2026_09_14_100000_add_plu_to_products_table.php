<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLU весового товара из 1С (реквизит «ПЛУ»). Весы печатают PLU в весовом
 * штрихкоде (префикс 20–29 + PLU + вес), поэтому сканирование ищет товар по
 * нему, а products.code остаётся кодом номенклатуры с ценника.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'plu')) {
                $table->string('plu', 100)->nullable()->after('code')->comment('PLU весового товара из 1С');
                $table->index('plu');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'plu')) {
                $table->dropIndex(['plu']);
                $table->dropColumn('plu');
            }
        });
    }
};
