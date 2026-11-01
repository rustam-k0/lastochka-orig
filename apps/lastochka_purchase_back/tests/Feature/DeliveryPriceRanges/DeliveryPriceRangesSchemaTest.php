<?php

namespace Tests\Feature\DeliveryPriceRanges;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Страж схемы «стоимость доставки от суммы заказа» (MID-280). Своих миграций у purchase_back нет:
 * колонки shop_zones.delivery_price_ranges и orders.delivery_price_snapshot создают миграции
 * admin_back, а локальная courier_test собирается командой `php artisan testing:prepare-db`
 * из рабочего дерева admin_back. Красный тест = тестовую БД собрали не с той ветки.
 */
class DeliveryPriceRangesSchemaTest extends TestCase
{
    public function test_shop_zones_has_delivery_price_ranges(): void
    {
        $this->assertTrue(
            Schema::hasColumn('shop_zones', 'delivery_price_ranges'),
            'нет колонки shop_zones.delivery_price_ranges: пересобрать courier_test из admin_back feat/mid-280-delivery-price-ranges'
        );
    }

    public function test_orders_has_delivery_price_snapshot(): void
    {
        $this->assertTrue(
            Schema::hasColumn('orders', 'delivery_price_snapshot'),
            'нет колонки orders.delivery_price_snapshot: пересобрать courier_test из admin_back feat/mid-280-delivery-price-ranges'
        );
    }
}
