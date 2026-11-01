<?php

namespace Tests\Feature;

use App\Models\IntegrationConfig;
use App\Models\Order;
use App\RabbitMQ\DTO\OrderEventDTO;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use App\Services\OrderImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Регрессия: входящий заказ из RabbitMQ должен получать shop_id.
 *
 * Колонка orders.shop_id — NOT NULL без дефолта (миграция add_shop_id_to_orders).
 * До фикса fromEvent()/createOrder() не заполняли shop_id → INSERT падал с
 * SQLSTATE[HY000] 1364 и собранные заказы не доходили до выгрузки в 1С.
 */
class OrderImportShopIdTest extends TestCase
{
    use RefreshDatabase;

    private function makeConfig(int $shopId, string $shopCode): void
    {
        IntegrationConfig::create([
            'integration_type' => '1c',
            'driver' => '1c',
            'shop_id' => $shopId,
            'shop_code' => $shopCode,
        ]);
    }

    public function test_resolves_shop_id_by_shop_code_scoped_to_1c(): void
    {
        // Конфиг 1С нужного магазина
        $this->makeConfig(133, 'aladin-b2b');

        // Конфиг другого типа с тем же shop_code не должен участвовать в резолве
        IntegrationConfig::create([
            'integration_type' => 'payment',
            'driver' => 'yookassa',
            'shop_id' => 999,
            'shop_code' => 'aladin-b2b',
        ]);

        $this->assertSame(133, IntegrationConfig::resolveShopIdByCode('aladin-b2b'));
    }

    public function test_falls_back_to_first_configured_shop_when_code_unknown(): void
    {
        $this->makeConfig(3, 'aladdinmarketplace');

        $this->assertSame(3, IntegrationConfig::resolveShopIdByCode('does-not-exist'));
        $this->assertSame(3, IntegrationConfig::resolveShopIdByCode(null));
    }

    public function test_returns_null_when_no_1c_config(): void
    {
        $this->assertNull(IntegrationConfig::resolveShopIdByCode('whatever'));
    }

    public function test_from_event_fills_shop_id_from_shop_code(): void
    {
        $this->makeConfig(3, 'aladdinmarketplace');

        $transformer = new OrderEventTransformer();

        $data = $transformer->fromEvent([
            'payload' => [
                'id' => 299,
                'number' => '202606160001',
                'status' => 'assembled',
                'shop_code' => 'aladdinmarketplace',
                'grand_total' => 208,
                'client_info' => ['phone' => '+79882200871'],
            ],
        ]);

        $this->assertSame(3, $data['shop_id']);
        $this->assertSame(Order::STATUS_ASSEMBLED, $data['status']);
    }

    public function test_import_service_persists_order_with_shop_id(): void
    {
        $this->makeConfig(133, 'aladin-b2b');

        $dto = OrderEventDTO::fromMessage([
            'id' => 12345,
            'number' => '202606160099',
            'status' => 'assembled',
            'shop_code' => 'aladin-b2b',
            'total' => 500,
            'client_info' => ['phone' => '+79001234567'],
            'items' => [
                ['product_id' => 'product-uuid-1', 'name' => 'Товар', 'quantity' => 1, 'price' => 500, 'total' => 500],
            ],
        ]);

        $order = (new OrderImportService())->import($dto);

        $this->assertSame(133, (int) $order->shop_id);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'shop_order_id' => 12345,
            'shop_id' => 133,
        ]);
    }
}
