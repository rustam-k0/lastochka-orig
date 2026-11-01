<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'items_subtotal')) {
                $table->decimal('items_subtotal', 15, 2)->nullable()->after('origin_amount')
                    ->comment('Сумма товаров ДО скидки (снапшот из RabbitMQ-события order.*)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'items_subtotal')) {
                $table->dropColumn('items_subtotal');
            }
        });
    }
};
