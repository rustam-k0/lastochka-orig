<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Http\Resources\Api\V1\OrderItemResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OrderItemResource.cartQuantity («повторить заказ») для весовых позиций — количество,
 * которое корзина реально примет: кратное quantity_step товара.
 *
 * После сборки quantity позиции = фактический вес (204 г при шаге 200), и мобилка
 * слала его в POST /cart/items как есть → 422 «Количество должно быть кратно 200»,
 * которое приложение показывало клиенту как «Нет в наличии» (прод-заказ 180).
 */
final class OrderItemCartQuantityStepTest extends TestCase
{
    use RefreshDatabase;

    private function makeItem(string $productUnit, int $step, string $storageUnit, int $quantity, ?int $actualWeight = null): OrderItem
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'price' => 130,
            'quantity' => 10000,
            'quantity_step' => $step,
            'measurement_unit' => $productUnit,
            'is_active' => true,
        ]);
        $order = Order::factory()->create(['store_id' => $store->id]);

        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'title' => $product->title,
            'sku' => $product->sku ?? '',
            'price' => 130,
            'quantity' => $quantity,
            'actual_weight' => $actualWeight,
            'total' => 132.6,
            'measurement_unit' => $storageUnit,
        ])->fresh();
    }

    private function cartQuantity(OrderItem $item): int
    {
        return (new OrderItemResource($item))->toArray(request())['cartQuantity'];
    }

    public function test_gram_actual_weight_is_rounded_to_product_step(): void
    {
        // 204 г при шаге 200 → 200; 536 г при шаге 500 → 500; 352 г при шаге 200 → 400
        $this->assertSame(200, $this->cartQuantity($this->makeItem('gram', 200, 'gram', 204, 204)));
        $this->assertSame(500, $this->cartQuantity($this->makeItem('gram', 500, 'gram', 536, 536)));
        $this->assertSame(400, $this->cartQuantity($this->makeItem('gram', 200, 'gram', 352, 352)));
    }

    public function test_gram_actual_weight_below_step_is_at_least_one_step(): void
    {
        // 192 г при шаге 200 → 200 (корзина не принимает меньше шага)
        $this->assertSame(200, $this->cartQuantity($this->makeItem('gram', 200, 'gram', 192, 192)));
    }

    public function test_gram_quantity_already_multiple_of_step_is_unchanged(): void
    {
        $this->assertSame(600, $this->cartQuantity($this->makeItem('gram', 200, 'gram', 600)));
    }

    public function test_milliliter_actual_volume_is_rounded_to_product_step(): void
    {
        $this->assertSame(500, $this->cartQuantity($this->makeItem('milliliter', 250, 'milliliter', 480, 480)));
    }

    public function test_kilogram_and_liter_are_converted_to_large_units(): void
    {
        // Крупные единицы: граммы → кг, без изменений поведения (1930 г → 2 кг)
        $this->assertSame(2, $this->cartQuantity($this->makeItem('kilogram', 1, 'gram', 1930, 1930)));
        $this->assertSame(1, $this->cartQuantity($this->makeItem('liter', 1, 'milliliter', 700, 700)));
    }

    public function test_piece_quantity_is_unchanged(): void
    {
        $this->assertSame(3, $this->cartQuantity($this->makeItem('piece', 1, 'piece', 3)));
    }
}
