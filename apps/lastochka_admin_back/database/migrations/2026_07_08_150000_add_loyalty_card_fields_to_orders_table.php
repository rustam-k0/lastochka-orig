<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'has_card')) {
                $table->boolean('has_card')->default(false)->after('bonus_earned')
                    ->comment('Признак наличия подтверждённой карты лояльности у клиента на момент заказа (снапшот из RabbitMQ-события order.*)');
            }
            if (!Schema::hasColumn('orders', 'card_number')) {
                $table->string('card_number', 16)->nullable()->after('has_card')
                    ->comment('Номер карты лояльности клиента на момент заказа (снапшот, заполняется только при has_card=true)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'card_number')) {
                $table->dropColumn('card_number');
            }
            if (Schema::hasColumn('orders', 'has_card')) {
                $table->dropColumn('has_card');
            }
        });
    }
};
