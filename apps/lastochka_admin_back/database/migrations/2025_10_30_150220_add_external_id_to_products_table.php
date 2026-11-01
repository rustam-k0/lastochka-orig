<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            // Добавляем поле external_id для хранения ID товара из RetailCRM
            if (!Schema::hasColumn('products', 'external_id')) {
                $table->string('external_id')->nullable()->index()->after('id');
            }
            
            // Добавляем поле barcode, если его еще нет
            if (!Schema::hasColumn('products', 'barcode')) {
                $table->string('barcode')->nullable()->after('article');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            // Удаляем добавленные поля
            if (Schema::hasColumn('products', 'external_id')) {
                $table->dropColumn('external_id');
            }
            
            if (Schema::hasColumn('products', 'barcode')) {
                $table->dropColumn('barcode');
            }
        });
    }
};
