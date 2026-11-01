<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Опции чекаута (MENDEV-17): снапшот [{code, label}] из витрины,
     * например «Охладить перед доставкой». Guard — колонка могла быть
     * создана другим форком/веткой (общая БД docker-модулей).
     *
     * @return void
     */
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'checkout_options')) {
                $table->json('checkout_options')->nullable()->after('client_comment');
            }
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'checkout_options')) {
                $table->dropColumn('checkout_options');
            }
        });
    }
};
