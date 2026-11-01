<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\Store;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Остаток весового товара (unit_type=kg) приходит из admin/1С в килограммах,
 * а внутри storefront количество весовых хранится в наименьшей единице —
 * граммах (как quantity_step, корзина и заказ). Приёмный слой обязан
 * конвертировать кг→граммы (×1000): иначе in_stock (quantity >= quantity_step,
 * где step в граммах) и проверка наличия сравнивают кг с граммами, остаток в
 * кг < шага в граммах → товар выпадает из поиска. Штучные (piece) — без конвертации.
 */
final class ProductStockUnitConversionTest extends TestCase
{
    use RefreshDatabase;

    private ProductEventTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        Product::unsetEventDispatcher();
        $this->transformer = new ProductEventTransformer();
    }

    public function test_fromEvent_converts_kg_stock_to_grams(): void
    {
        Store::factory()->create(['code' => 'SHOP_KG']);

        $attributes = $this->transformer->fromEvent($this->event([
            'source' => '1c',
            'shop_code' => 'SHOP_KG',
            'unit_type' => 'kg',
            'quantity' => 5,
        ]));

        // 5 кг → 5000 граммов
        $this->assertSame(5000, $attributes['quantity']);
    }

    /**
     * 1С шлёт дробные килограммы (0.216, 2.168). Усечение до целых кг ДО
     * умножения на 1000 давало 0 г и 2000 г: весовой товар с остатком меньше
     * килограмма пропадал из каталога, а часть остатка терялась.
     */
    public function test_fromEvent_converts_fractional_kg_without_truncation(): void
    {
        Store::factory()->create(['code' => 'SHOP_KGF']);

        $attributes = $this->transformer->fromEvent($this->event([
            'source' => '1c',
            'shop_code' => 'SHOP_KGF',
            'unit_type' => 'kg',
            'quantity' => 0.216,
        ]));

        $this->assertSame(216, $attributes['quantity']);
    }

    public function test_stock_updated_event_converts_fractional_kg_without_truncation(): void
    {
        $store = Store::factory()->create(['code' => 'SHOP_KGF2']);
        $product = Product::factory()->for($store)->create([
            'sku' => 'KGF-1',
            'unit_type' => 'kg',
            'quantity' => 0,
            'quantity_step' => 200,
        ]);

        $this->transformer->processStockUpdatedEvent($product, [
            'event_id' => Str::uuid()->toString(),
            'quantity' => 2.168,
        ]);

        $this->assertSame(2168, (int) $product->fresh()->quantity);
    }

    public function test_stock_changed_event_converts_fractional_kg_without_truncation(): void
    {
        $store = Store::factory()->create(['code' => 'SHOP_KGF3']);
        $product = Product::factory()->for($store)->create([
            'sku' => 'KGF-2',
            'unit_type' => 'kg',
            'quantity' => 0,
            'quantity_step' => 200,
        ]);

        $this->transformer->processStockChangedEvent($product, [
            'event_id' => Str::uuid()->toString(),
            'quantity' => 0.504,
        ]);

        $this->assertSame(504, (int) $product->fresh()->quantity);
    }

    public function test_fromEvent_keeps_piece_stock_unchanged(): void
    {
        Store::factory()->create(['code' => 'SHOP_PC']);

        $attributes = $this->transformer->fromEvent($this->event([
            'source' => '1c',
            'shop_code' => 'SHOP_PC',
            'unit_type' => 'piece',
            'quantity' => 42,
        ]));

        $this->assertSame(42, $attributes['quantity']);
    }

    public function test_stock_updated_event_converts_kg_to_grams(): void
    {
        $store = Store::factory()->create(['code' => 'SHOP_KG2']);
        $product = Product::factory()->for($store)->create([
            'sku' => 'KG-1',
            'unit_type' => 'kg',
            'quantity' => 0,
            'quantity_step' => 200,
        ]);

        $this->transformer->processStockUpdatedEvent($product, [
            'event_id' => Str::uuid()->toString(),
            'quantity' => 5,
        ]);

        $this->assertSame(5000, (int) $product->fresh()->quantity);
    }

    public function test_stock_updated_event_keeps_piece_unchanged(): void
    {
        $store = Store::factory()->create(['code' => 'SHOP_PC2']);
        $product = Product::factory()->for($store)->create([
            'sku' => 'PC-1',
            'unit_type' => 'piece',
            'quantity' => 0,
            'quantity_step' => 1,
        ]);

        $this->transformer->processStockUpdatedEvent($product, [
            'event_id' => Str::uuid()->toString(),
            'quantity' => 7,
        ]);

        $this->assertSame(7, (int) $product->fresh()->quantity);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function event(array $overrides = []): array
    {
        return array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'prod_'.Str::random(5),
            'source' => '1c',
            'shop_code' => 'SHOP',
            'sku' => 'SKU-'.Str::random(5),
            'title' => 'Тестовый товар',
            'price' => 100.00,
            'active' => true,
        ], $overrides);
    }
}
