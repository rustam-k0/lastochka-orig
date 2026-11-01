<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Actions\Order\CreateOrderAction;
use App\DTOs\Order\CreateOrderData;
use App\Enums\ShippingMethod;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Pickup\PickupSlotService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CreateOrderPickupOnlyRestrictionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Фиксируем время на безопасное утро — тест самовывоза (24/7 магазин)
        // читает слоты от `now()` и без заморозки флакует, если прогнать его
        // поздно вечером (см. PickupSlotService::getSlots — "пропускаем прошедшие слоты").
        Carbon::setTestNow(Carbon::parse('2026-07-02 08:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_courier_order_with_pickup_only_product_is_rejected(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create();

        $store = Store::factory()->create([
            'min_order_amount' => 0,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);

        $product = Product::factory()->for($store)->create([
            'sku' => 'WINE-ORDER',
            'price' => 500,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
            'pickup_only' => true,
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 500,
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(CreateOrderAction::class)->execute(new CreateOrderData(
                user: $user,
                storeId: $store->id,
                address: $address,
                deliverySlotId: $this->getValidDeliverySlotId(),
                paymentType: 'cash',
            ));
        } catch (ValidationException $e) {
            // Исключение сконструировано с явным `response:` (см. существующий паттерн
            // проверки pickup_payment_methods в этом же классе) — тело ошибки лежит в
            // публичном свойстве `response`, а не в `$e->errors()` (тот отражает пустой
            // `validator([], [])`, переданный первым аргументом).
            $data = $e->response->getData(true);
            $this->assertArrayHasKey('shippingMethod', $data['errors']);
            $this->assertDatabaseCount('orders', 0);

            throw $e;
        }
    }

    public function test_pickup_order_with_pickup_only_product_succeeds(): void
    {
        $user = User::factory()->create();

        $store = Store::factory()->create([
            'is_pickup_enabled' => true,
            'is_open_24_7' => true,
            'slot_duration' => 60,
            'min_pickup_order_amount' => 0,
            'pickup_payment_methods' => ['cash', 'card'],
        ]);

        $product = Product::factory()->for($store)->create([
            'sku' => 'WINE-PICKUP',
            'price' => 500,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
            'pickup_only' => true,
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 500,
        ]);

        $slots = app(PickupSlotService::class)->getSlots($store, now()->format('Y-m-d'), null);
        $this->assertNotEmpty($slots, 'у круглосуточного магазина должен быть хотя бы один слот самовывоза сегодня');

        $result = app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: null,
            deliverySlotId: $slots[0]['id'],
            shippingMethod: ShippingMethod::Pickup,
            paymentType: 'cash',
        ));

        $this->assertNotNull($result['orderId']);
        $this->assertDatabaseHas('orders', ['id' => $result['orderId'], 'shipping_method' => ShippingMethod::Pickup->value]);
    }

    public function test_courier_order_without_pickup_only_product_is_unaffected(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create();

        $store = Store::factory()->create([
            'min_order_amount' => 0,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);

        $product = Product::factory()->for($store)->create([
            'sku' => 'MILK-ORDER',
            'price' => 300,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
            'pickup_only' => false,
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 300,
        ]);

        $result = app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: $address,
            deliverySlotId: $this->getValidDeliverySlotId(),
            paymentType: 'cash',
        ));

        $this->assertNotNull($result['orderId']);
    }
}
