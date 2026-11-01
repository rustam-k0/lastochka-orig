<?php

namespace Tests\Feature\Transformers;

use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Транзит orders.checkout_options (опции чекаута, MENDEV-17) через события.
 *
 * Витрина пишет снапшот выбранных опций ([{code, label}], например
 * «Охладить перед доставкой») в событие заказа — сборщик должен видеть
 * его в приложении. События сервисов без поля не затирают сохранённое.
 */
class OrderCheckoutOptionsTransitTest extends TestCase
{
    use DatabaseTransactions;

    private function transformer(): OrderEventTransformer
    {
        return app(OrderEventTransformer::class);
    }

    private function baseEvent(array $overrides = []): array
    {
        return array_merge([
            'id' => 'uuid-co-1',
            'shop_code' => 'default',
            'status' => 'created',
            'payment_type' => 'card',
            'shipping_method' => 'courier',
            'grand_total' => 500,
        ], $overrides);
    }

    /** @test */
    public function from_event_maps_checkout_options()
    {
        $attributes = $this->transformer()->fromEvent($this->baseEvent([
            'checkout_options' => [
                ['code' => 'chill_before_delivery', 'label' => 'Охладить перед доставкой'],
            ],
        ]));

        $this->assertSame(
            [['code' => 'chill_before_delivery', 'label' => 'Охладить перед доставкой']],
            $attributes['checkout_options']
        );
    }

    /** @test */
    public function from_event_skips_checkout_options_when_absent()
    {
        $attributes = $this->transformer()->fromEvent($this->baseEvent(['id' => 'uuid-co-2']));

        $this->assertArrayNotHasKey('checkout_options', $attributes);
    }
}
