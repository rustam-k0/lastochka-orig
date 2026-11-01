<?php

namespace Tests\Feature;

use App\Events\ProductStockChanged;
use App\Models\Product;
use App\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression: остаток весового товара из 1С — килограммы с дробной частью
 * (0.828, 2.168). remains.quantity была bigint, processQuantity/getProductQuantity
 * приводили к int, ProductStockChanged принимал только int — остаток меньше
 * килограмма терялся (0), сборщик видел «нет на складе», а эхо product.updated
 * уносило ноль на витрину. Остаток хранится и публикуется с точностью до грамма.
 */
class RemainFractionalKgTest extends TestCase
{
    use DatabaseTransactions;

    private int $shopId;

    private string $shopCode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopCode = 'frac_kg_' . uniqid();
        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Fractional kg shop',
            'code' => $this->shopCode,
            'id_1c' => 999882,
            'default' => 0,
            'slot_max_orders' => 10,
            'slot_time' => 60,
            'is_popular' => 0,
            'is_pickup_enabled' => 0,
            'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0,
            'is_bonus_enabled' => 0,
            'bonus_spend_percent' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertWeightProduct(): Product
    {
        $id = \DB::table('products')->insertGetId([
            'title' => 'Конфеты Вес ' . uniqid(),
            'articul' => 'ART-KG-' . uniqid(),
            'unit_type_id' => 1,
            'info_weight' => 200,
            'info_weight_type_id' => 2,
            'status_id' => 1,
            'shop_id' => $this->shopId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Product::withoutGlobalScopes()->findOrFail($id);
    }

    private function remainQuantity(int $productId): ?float
    {
        $value = \DB::table('remains')
            ->where('product_id', $productId)
            ->where('shop_id', $this->shopId)
            ->value('quantity');

        return $value === null ? null : (float) $value;
    }

    public function test_stock_updated_event_keeps_fractional_kg(): void
    {
        $product = $this->insertWeightProduct();

        app(ProductEventTransformer::class)->processStockUpdatedEvent($product, [
            'event_id' => 'evt-' . uniqid(),
            'shop_code' => $this->shopCode,
            'quantity' => 0.828,
        ]);

        $this->assertEqualsWithDelta(0.828, $this->remainQuantity($product->id), 0.0005, 'Остаток 0.828 кг не должен усекаться до 0');
    }

    public function test_stock_changed_event_publishes_fractional_kg_to_storefront(): void
    {
        $product = $this->insertWeightProduct();

        $payload = app(ProductEventTransformer::class)->toEvent(
            $product,
            new ProductStockChanged($product, 2.168, 1.968, $this->shopCode)
        );

        $this->assertSame('stock_updated', $payload['event_type']);
        $this->assertEqualsWithDelta(1.968, (float) $payload['quantity'], 0.0005, 'stock_updated к витрине должен нести дробные кг');
        $this->assertEqualsWithDelta(2.168, (float) $payload['old_quantity'], 0.0005);
    }

    public function test_stock_changed_event_accepts_fractional_quantities(): void
    {
        $product = $this->insertWeightProduct();

        $event = new ProductStockChanged($product, 2.168, 1.968, $this->shopCode);

        $this->assertEqualsWithDelta(2.168, $event->oldQuantity, 0.0005);
        $this->assertEqualsWithDelta(1.968, $event->newQuantity, 0.0005);
    }
}
