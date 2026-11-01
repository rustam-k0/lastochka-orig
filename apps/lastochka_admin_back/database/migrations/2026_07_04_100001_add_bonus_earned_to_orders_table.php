<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'bonus_earned')) {
                $table->unsignedInteger('bonus_earned')->default(0)->after('bonus_spent')
                    ->comment('Начислено бонусов клиенту за заказ (снапшот из RabbitMQ-события order.*)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'bonus_earned')) {
                $table->dropColumn('bonus_earned');
            }
        });
    }
};
