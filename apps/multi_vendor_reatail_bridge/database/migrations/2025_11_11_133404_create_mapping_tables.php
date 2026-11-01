<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Создаем таблицы для маппинга сущностей между RetailCRM и другими системами.
     */
    public function up(): void
    {
        // Базовая структура для всех таблиц маппинга
        $createMappingTable = function (string $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->string('id')->primary(); // ID в нашей системе
                $table->string('external_id'); // ID во внешней системе (RetailCRM)
                $table->string('source'); // Источник данных (retailcrm, bagisto, delivery_system)
                $table->json('data')->nullable(); // Дополнительные данные в JSON
                $table->timestamps();
                $table->softDeletes();
                
                // Индексы для быстрого поиска
                $table->index(['external_id', 'source']);
            });
        };
        
        // Создаем таблицы маппинга для всех сущностей
        $createMappingTable('product_mappings');
        $createMappingTable('category_mappings');
        $createMappingTable('order_mappings');
        $createMappingTable('customer_mappings');
        $createMappingTable('user_mappings');
        $createMappingTable('shop_mappings');
        $createMappingTable('address_mappings');
    }

    /**
     * Удаляем все таблицы маппинга.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_mappings');
        Schema::dropIfExists('category_mappings');
        Schema::dropIfExists('order_mappings');
        Schema::dropIfExists('customer_mappings');
        Schema::dropIfExists('user_mappings');
        Schema::dropIfExists('shop_mappings');
        Schema::dropIfExists('address_mappings');
    }
};
