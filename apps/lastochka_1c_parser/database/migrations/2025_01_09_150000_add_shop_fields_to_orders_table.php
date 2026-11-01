<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('shop_order_id')
                ->nullable()
                ->unique()
                ->after('id')
                ->comment('ID заказа из магазина (для идемпотентности)');
            
            $table->string('source', 50)
                ->default('manual')
                ->after('status')
                ->comment('Источник заказа: manual, shop');
            
            $table->timestamp('received_at')
                ->nullable()
                ->after('exported_at')
                ->comment('Когда получен из RabbitMQ');

            $table->index(['status', 'exported_at'], 'orders_export_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_export_idx');
            $table->dropColumn(['shop_order_id', 'source', 'received_at']);
        });
    }
};
