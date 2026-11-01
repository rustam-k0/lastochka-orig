<?php

namespace Tests\Feature;

use App\RabbitMQ\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Номер заказа в 1С должен совпадать с номером заказа в магазине (shop_order_id),
 * а не генерироваться автоматически (раньше выходило 202606160001 вместо 299).
 */
class OrderNumberFromShopIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_number_equals_shop_order_id(): void
    {
        $data = (new OrderEventTransformer())->fromEvent([
            'payload' => [
                'id' => 299,
                'status' => 'assembled',
                'shop_code' => 'aladdinmarketplace',
                'grand_total' => 208,
                'client_info' => ['phone' => '+79882200871'],
            ],
        ]);

        $this->assertSame('299', (string) $data['number']);
        $this->assertSame(299, $data['shop_order_id']);
    }

    public function test_payload_number_does_not_override_shop_order_id(): void
    {
        // Даже если придёт сгенерированный number — приоритет у id магазина
        $data = (new OrderEventTransformer())->fromEvent([
            'payload' => [
                'id' => 305,
                'number' => '202606160001',
                'status' => 'assembled',
                'client_info' => ['phone' => '+79990000000'],
            ],
        ]);

        $this->assertSame('305', (string) $data['number']);
    }
}
