<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Заполнить запись в таблице shops для магазина Аладдин.
 *
 * ResolvesShopCode::getShopCodeById(3) читает shops.code.
 * Пустая таблица → fallback "shop_3" → события stock_updated уходили
 * в несуществующий/псевдо-магазин storefront, а реальный Аладдин
 * (store_id=1, shop_code=aladdinmarketplace) не получал обновлений остатков.
 *
 * После этой миграции product.shop_id=3 → shop_code='aladdinmarketplace',
 * события из 1c_parser попадают на правильный storefront Product.
 *
 * См. Platrum 9853.
 */
return new class extends Migration {
    public function up(): void
    {
        $exists = DB::table('shops')->where('id', 3)->orWhere('code', 'aladdinmarketplace')->exists();

        if ($exists) {
            DB::table('shops')
                ->where('id', 3)
                ->orWhere('code', 'aladdinmarketplace')
                ->update([
                    'id' => 3,
                    'code' => 'aladdinmarketplace',
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('shops')->insert([
            'id' => 3,
            'code' => 'aladdinmarketplace',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('shops')
            ->where('id', 3)
            ->where('code', 'aladdinmarketplace')
            ->delete();
    }
};
