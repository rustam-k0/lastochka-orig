<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Delivery\CartProductsMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Маппинг позиций корзины в products для slots-api (LASTOCHKI-JAMI-11145).
 *
 * Слоты доставки рассчитываются с учётом времени сборки заказа:
 * slots-api принимает [{articul, count}], где count — порции
 * (та же конверсия, что в OrderEventTransformer::buildItems).
 */
final class CartProductsMapperTest extends TestCase
{
    use RefreshDatabase;

    private Cart $cart;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->cart = Cart::query()->create(['user_id' => $user->id]);
        $this->store = Store::factory()->create();
    }

    private function makeItem(array $productAttributes, int $quantity): CartItem
    {
        $product = Product::factory()->for($this->store)->create($productAttributes);

        return CartItem::factory()->for($this->cart)->for($product)->create([
            'store_id' => $this->store->id,
            'quantity' => $quantity,
        ]);
    }

    private function map(): array
    {
        $items = CartItem::query()
            ->where('cart_id', $this->cart->id)
            ->with('product')
            ->get();

        return (new CartProductsMapper)->map($items);
    }

    public function test_piece_product_maps_quantity_as_count(): void
    {
        $this->makeItem(['sku' => 'SKU-PIECE', 'quantity_step' => 1, 'measurement_unit' => 'piece', 'quantity' => 100], 3);

        $this->assertSame([['articul' => 'SKU-PIECE', 'count' => 3]], $this->map());
    }

    public function test_weight_product_converts_grams_to_portions_by_step(): void
    {
        $this->makeItem(['sku' => 'SKU-WEIGHT', 'quantity_step' => 500, 'measurement_unit' => 'gram', 'quantity' => 10000], 1500);

        $this->assertSame([['articul' => 'SKU-WEIGHT', 'count' => 3]], $this->map());
    }

    public function test_kilogram_product_cart_quantity_is_already_kilograms(): void
    {
        // Корзина товара «по кг» без кванта хранит килограммы (прод 14.10:
        // quantity 1/2/3 у kilogram-товаров), порция = 1 кг.
        $this->makeItem(['sku' => 'SKU-KILO', 'quantity_step' => 1, 'measurement_unit' => 'kilogram', 'quantity' => 100000], 2);

        $this->assertSame([['articul' => 'SKU-KILO', 'count' => 2]], $this->map());
    }

    public function test_kilogram_product_three_kilos_are_three_portions(): void
    {
        // Раньше кг делились на 1000: round(0.003) → max(1, 0) → 1 порция,
        // и время сборки для слотов занижалось.
        $this->makeItem(['sku' => 'SKU-KILO-3', 'quantity_step' => 1, 'measurement_unit' => 'kilogram', 'quantity' => 100000], 3);

        $this->assertSame([['articul' => 'SKU-KILO-3', 'count' => 3]], $this->map());
    }

    public function test_item_with_deleted_product_is_skipped(): void
    {
        $item = $this->makeItem(['sku' => 'SKU-GONE', 'quantity_step' => 1, 'measurement_unit' => 'piece', 'quantity' => 100], 2);
        $item->product->delete();

        $this->assertSame([], $this->map());
    }

    public function test_duplicate_products_are_merged_with_summed_counts(): void
    {
        $product = Product::factory()->for($this->store)->create([
            'sku' => 'SKU-DUP',
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
            'quantity' => 100,
        ]);

        CartItem::factory()->for($this->cart)->for($product)->create(['store_id' => $this->store->id, 'quantity' => 2]);
        CartItem::factory()->for($this->cart)->for($product)->create(['store_id' => $this->store->id, 'quantity' => 1]);

        $this->assertSame([['articul' => 'SKU-DUP', 'count' => 3]], $this->map());
    }
}
