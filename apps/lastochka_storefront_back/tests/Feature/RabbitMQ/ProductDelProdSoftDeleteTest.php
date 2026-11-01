<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Store;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Витрина уже умеет и мягкое удаление, и восстановление товара по ключу
 * deleted_at в событии: fromEvent переносит значение в атрибуты, а
 * maybeSoftDelete (вызывается из afterCreate/afterUpdate) применяет его к
 * модели. Механизм не срабатывал только потому, что 1c_parser слал в этом
 * ключе захардкоженный null.
 *
 * Тест фиксирует поведение, чтобы правки трансформера его не сломали.
 */
class ProductDelProdSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // fromEvent резолвит магазин по shop_code и падает, если его нет.
        Store::factory()->create(['code' => 'aladdin', 'source' => '1c']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'id' => 'guid-delprod-1',
            'shop_code' => 'aladdin',
            'source' => '1c',
            'sku' => 'ART-1',
            'title' => 'Пряники Домашние 5кг',
            'unit_type' => 'piece',
            'price' => 199.90,
            'quantity' => 10,
        ], $overrides);
    }

    public function test_deleted_at_is_transferred_when_present(): void
    {
        $attributes = app(ProductEventTransformer::class)->fromEvent($this->payload([
            'deleted_at' => '2026-07-31T19:13:09+03:00',
        ]));

        $this->assertArrayHasKey('deleted_at', $attributes);
        $this->assertNotNull($attributes['deleted_at']);
    }

    public function test_explicit_null_restores_product(): void
    {
        $attributes = app(ProductEventTransformer::class)->fromEvent($this->payload([
            'deleted_at' => null,
        ]));

        $this->assertArrayHasKey('deleted_at', $attributes);
        $this->assertNull($attributes['deleted_at']);
    }

    public function test_absent_key_does_not_touch_deleted_at(): void
    {
        $attributes = app(ProductEventTransformer::class)->fromEvent($this->payload());

        $this->assertArrayNotHasKey('deleted_at', $attributes);
    }
}
