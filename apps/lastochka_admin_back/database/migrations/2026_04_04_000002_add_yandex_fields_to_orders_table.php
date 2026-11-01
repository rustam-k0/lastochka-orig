<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('yandex_claim_id', 64)->nullable()->after('bonus_spent');
            $table->string('yandex_delivery_status', 40)->nullable()->after('yandex_claim_id');
            $table->string('yandex_courier_name', 255)->nullable()->after('yandex_delivery_status');
            $table->string('yandex_courier_phone', 20)->nullable()->after('yandex_courier_name');
            $table->unsignedInteger('yandex_delivery_price')->nullable()->after('yandex_courier_phone');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'yandex_claim_id',
                'yandex_delivery_status',
                'yandex_courier_name',
                'yandex_courier_phone',
                'yandex_delivery_price',
            ]);
        });
    }
};
