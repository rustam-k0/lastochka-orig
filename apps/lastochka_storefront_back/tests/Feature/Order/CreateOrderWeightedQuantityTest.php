<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Actions\Order\CreateOrderAction;
use App\DTOs\Order\CreateOrderData;
use App\Enums\ShippingMethod;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Pickup\PickupSlotService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Единица хранения quantity в позиции заказа для весовых товаров.
 *
 * Корзина хранит quantity в граммах у любого товара с квантом (step>1) и в кг
 * только у кг-товара без кванта (step=1) — см. CartItem::grams(),
 * CartProductsMapper::portions(), OrderEventTransformer::buildItems(). Позиция
 * заказа всегда хранит граммы, поэтому кг→г конвертируется ТОЛЬКО при step<=1.
 *
 * Прод-инцидент 10.09.2026 (заказ 204): 1С прислал карточку огурцов с
 * measurement_unit=kilogram и без quantity_step, товар остался с шагом 500 г от
 * админки, и конверсия «kilogram → ×1000» без проверки шага превратила 1 кг
 * в корзине (1000 г) в 1 000 000 г — 1000 кг у сборщика и 865 995 ₽ в заказе.
 */
final class CreateOrderWeightedQuantityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Заморозка утра: самовывоз читает слоты от now() и поздним вечером флакует
        // (тот же приём, что в CreateOrderAssemblyCostTest).
        Carbon::setTestNow(Carbon::parse('2026-07-02 08:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Товар с квантом: корзина уже в граммах, ×1000 применять нельзя.
     * Это регресс на прод-инцидент с огурцами.
     */
    public function test_kilogram_product_with_step_keeps_cart_grams(): void
    {
        $order = $this->createPickupOrder(
            measurementUnit: 'kilogram',
            step: 500,
            cartQuantity: 1000,
            price: 145,
        );

        $item = $order->items()->firstOrFail();

        $this->assertSame(1000, (int) $item->quantity, 'квант 500 г: 1000 г корзины остаются 1000 г');
        $this->assertSame('gram', $item->measurement_unit->value);
        $this->assertSame(290.0, (float) $item->total, 'две порции по 145 ₽');
    }

    /**
     * Кг-товар без кванта: корзина в килограммах, ×1000 обязателен.
     */
    public function test_kilogram_product_without_step_converts_to_grams(): void
    {
        $order = $this->createPickupOrder(
            measurementUnit: 'kilogram',
            step: 1,
            cartQuantity: 2,
            price: 677,
        );

        $item = $order->items()->firstOrFail();

        $this->assertSame(2000, (int) $item->quantity, '2 кг корзины → 2000 г');
        $this->assertSame('gram', $item->measurement_unit->value);
        $this->assertSame(1354.0, (float) $item->total);
    }

    /**
     * Граммовый товар с квантом: конверсии нет ни при каком шаге.
     */
    public function test_gram_product_with_step_is_stored_as_is(): void
    {
        $order = $this->createPickupOrder(
            measurementUnit: 'gram',
            step: 200,
            cartQuantity: 400,
            price: 124.8,
        );

        $item = $order->items()->firstOrFail();

        $this->assertSame(400, (int) $item->quantity);
        $this->assertSame('gram', $item->measurement_unit->value);
        $this->assertSame(249.6, (float) $item->total);
    }

    /**
     * Юзер + самовывозный магазин + корзина с одним весовым товаром;
     * самовывоз даёт детерминированную сумму (без slots-api и доставки).
     */
    private function createPickupOrder(string $measurementUnit, int $step, int $cartQuantity, float $price): Order
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'is_pickup_enabled' => true,
            'is_open_24_7' => true,
            'slot_duration' => 60,
            'min_pickup_order_amount' => 0,
            'min_order_amount' => 0,
            'pickup_payment_methods' => ['cash', 'card'],
            'is_bonus_enabled' => false,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);

        $product = Product::factory()->for($store)->create([
            'sku' => 'WEIGHTED-SKU',
            'price' => $price,
            'quantity' => 1_000_000,
            'quantity_step' => $step,
            'measurement_unit' => $measurementUnit,
            'unit_type' => 'kg',
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => $cartQuantity,
            'price' => $price,
        ]);

        $slots = app(PickupSlotService::class)->getSlots($store, now()->format('Y-m-d'), null);
        $this->assertNotEmpty($slots, 'у круглосуточного магазина должен быть слот самовывоза сегодня');

        $result = app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: null,
            deliverySlotId: $slots[0]['id'],
            shippingMethod: ShippingMethod::Pickup,
            paymentType: 'cash',
        ));

        $this->assertNotNull($result['orderId']);

        return Order::findOrFail($result['orderId']);
    }
}
