<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Тип магазина: shop (обычный магазин) | restaurant (меню как единый список
            // секций product_group+category без пагинации). Зеркалит shops.type_id
            // курьерки (Type::SHOP_TYPE_STORE|SHOP_TYPE_CAFE). Дефолт shop для всех
            // существующих магазинов и для событий без поля type.
            $table->string('type')->default('shop')->after('is_b2b');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
