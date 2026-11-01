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
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * Лазейка «создал pickup-заказ 18+ с оплатой на кассе, потом дёрнул
 * POST /orders/{id}/pay и оплатил онлайн» должна быть закрыта: retryPayment
 * отклоняет онлайн-оплату pickup-заказа с adult_only-товарами.
 */
final class PayOrderAdultOnlyRestrictionTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-02 08:00:00', config('app.timezone')));

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pay_endpoint_rejects_pickup_order_with_adult_product(): void
    {
        [$user, $orderId] = $this->makePickupCashOrder(adultOnly: true);

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/pay");

        $response->assertUnprocessable()
            ->assertJsonPath('reason', 'adult_only_offline_payment');

        $this->assertCount(0, $this->gateway->calls, 'онлайн-платёж не должен создаваться');
    }

    public function test_pay_endpoint_allows_pickup_order_without_adult_products(): void
    {
        [$user, $orderId] = $this->makePickupCashOrder(adultOnly: false);

        $response = $this->actingAs($user)->postJson("/api/v1/orders/{$orderId}/pay");

        $response->assertOk();
        $this->assertCount(1, $this->gateway->callsFor('hold'));
    }

    /**
     * Создаёт pickup-заказ с оплатой «на кассе» (cash) — легальный путь,
     * из которого затем пробуют онлайн-оплату через /pay.
     *
     * @return array{0: User, 1: int}
     */
    private function makePickupCashOrder(bool $adultOnly): array
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
            'sku' => $adultOnly ? 'WHISKY-18' : 'WATER-0',
            'price' => 700,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
            'adult_only' => $adultOnly,
        ]);

        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 700,
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

        return [$user, (int) $result['orderId']];
    }
}
