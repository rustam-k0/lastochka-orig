<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Способ получения заказа: courier/pickup/cdek/russian_post/yandex_delivery.
            // Приходит в payload как shipping_method, выгружается в 1С реквизитом «Способ доставки».
            $table->string('shipping_method')->nullable()->after('delivery_cost');
            // Способ оплаты: cash/card. Приходит в payload как payment_type,
            // выгружается в 1С реквизитом «Способ оплаты».
            $table->string('payment_type')->nullable()->after('shipping_method');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_method', 'payment_type']);
        });
    }
};
