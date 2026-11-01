<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Actions\Order\CreateOrderAction;
use App\Contracts\PaymentGateway;
use App\DTOs\Order\CreateOrderData;
use App\Enums\ShippingMethod;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Pickup\PickupSlotService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * Товары 18+ при самовывозе запрещено оплачивать онлайн (правило заказчика):
 * если в заказе есть adult_only-товар — оплата только на кассе при получении
 * (paymentType=cash), онлайн-оплата картой отклоняется с 422.
 */
final class CreateOrderAdultOnlinePaymentTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        // Фиксируем время на безопасное утро — тест самовывоза (24/7 магазин)
        // читает слоты от `now()` и без заморозки флакует поздно вечером
        // (см. PickupSlotService::getSlots — "пропускаем прошедшие слоты").
        Carbon::setTestNow(Carbon::parse('2026-07-02 08:00:00', config('app.timezone')));

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pickup_order_with_adult_product_and_card_payment_is_rejected(): void
    {
        [$user, $store] = $this->makePickupContext(adultOnly: true);

        $this->expectException(ValidationException::class);

        try {
            app(CreateOrderAction::class)->execute(new CreateOrderData(
                user: $user,
                storeId: $store->id,
                address: null,
                deliverySlotId: $this->getPickupSlotId($store),
                shippingMethod: ShippingMethod::Pickup,
                paymentType: 'card',
            ));
        } catch (ValidationException $e) {
            // Тело ошибки — в публичном свойстве `response` (исключение сконструировано
            // с явным `response:`, см. паттерн проверки pickup_payment_methods).
            $data = $e->response->getData(true);
            $this->assertArrayHasKey('paymentType', $data['errors']);
            $this->assertDatabaseCount('orders', 0);
            $this->assertCount(0, $this->gateway->calls, 'онлайн-платёж не должен создаваться');

            throw $e;
        }
    }

    public function test_pickup_order_with_adult_product_and_cash_payment_succeeds(): void
    {
        [$user, $store] = $this->makePickupContext(adultOnly: true);

        $result = app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: null,
            deliverySlotId: $this->getPickupSlotId($store),
            shippingMethod: ShippingMethod::Pickup,
            paymentType: 'cash',
        ));

        $this->assertNotNull($result['orderId']);
        $this->assertDatabaseHas('orders', ['id' => $result['orderId'], 'payment_type' => 'cash']);
    }

    public function test_pickup_order_without_adult_product_and_card_payment_succeeds(): void
    {
        [$user, $store] = $this->makePickupContext(adultOnly: false);

        $result = app(CreateOrderAction::class)->execute(new CreateOrderData(
            user: $user,
            storeId: $store->id,
            address: null,
            deliverySlotId: $this->getPickupSlotId($store),
            shippingMethod: ShippingMethod::Pickup,
            paymentType: 'card',
        ));

        $this->assertNotNull($result['orderId']);
        $this->assertDatabaseHas('orders', ['id' => $result['orderId'], 'payment_type' => 'card']);
    }

    /**
     * @return array{0: User, 1: Store}
     */
    private function makePickupContext(bool $adultOnly): array
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
            'sku' => $adultOnly ? 'VODKA-18' : 'JUICE-0',
            'price' => 500,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
            'adult_only' => $adultOnly,
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 500,
        ]);

        return [$user, $store];
    }

    private function getPickupSlotId(Store $store): int
    {
        $slots = app(PickupSlotService::class)->getSlots($store, now()->format('Y-m-d'), null);
        $this->assertNotEmpty($slots, 'у круглосуточного магазина должен быть хотя бы один слот самовывоза сегодня');

        return $slots[0]['id'];
    }
}
