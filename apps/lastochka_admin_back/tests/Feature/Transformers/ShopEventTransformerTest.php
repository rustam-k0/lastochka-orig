<?php

namespace Tests\Feature\Transformers;

use App\Models\Shop;
use App\Models\Type;
use App\Transformers\ShopEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Тесты payload магазина (tg-14398): описание магазина уходит в RabbitMQ-событие
 * для отображения в карточке магазина клиентского приложения.
 */
class ShopEventTransformerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_shop_payload_contains_description(): void
    {
        $shop = Shop::create([
            'code' => 'aladdinmarketplace',
            'title' => 'Аладдин',
            'description' => 'Продукты, Своя Кухня, Бытовая Химия',
        ]);

        $payload = (new ShopEventTransformer())->toEvent($shop->fresh());

        $this->assertArrayHasKey('description', $payload);
        $this->assertSame('Продукты, Своя Кухня, Бытовая Химия', $payload['description']);
    }

    public function test_shop_payload_description_null_when_empty(): void
    {
        $shop = Shop::create([
            'code' => 'no-desc-shop',
            'title' => 'Без описания',
        ]);

        $payload = (new ShopEventTransformer())->toEvent($shop->fresh());

        $this->assertArrayHasKey('description', $payload);
        $this->assertNull($payload['description']);
    }

    public function test_shop_payload_type_is_restaurant_for_cafe_shop(): void
    {
        $shop = Shop::create([
            'code' => 'cafe-shop',
            'title' => 'Кафе на Ленина',
            'type_id' => Type::SHOP_TYPE_CAFE,
        ]);

        $payload = (new ShopEventTransformer())->toEvent($shop->fresh());

        $this->assertSame('restaurant', $payload['type']);
    }

    public function test_shop_payload_type_is_shop_for_store_shop(): void
    {
        $shop = Shop::create([
            'code' => 'store-shop',
            'title' => 'Магазин на Ленина',
            'type_id' => Type::SHOP_TYPE_STORE,
        ]);

        $payload = (new ShopEventTransformer())->toEvent($shop->fresh());

        $this->assertSame('shop', $payload['type']);
    }

    public function test_shop_payload_type_defaults_to_shop_when_type_id_missing(): void
    {
        $shop = Shop::create([
            'code' => 'no-type-shop',
            'title' => 'Без типа',
        ]);

        $payload = (new ShopEventTransformer())->toEvent($shop->fresh());

        $this->assertSame('shop', $payload['type']);
    }
}
