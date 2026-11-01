<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Contracts\PaymentGateway;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * Клиент сам указывает, куда его вернуть после оплаты (`returnUrl`):
 * создание заказа, повторная оплата, привязка карты. Адрес доезжает до
 * шлюза как есть и проверяется по белому списку доменов — чужой домен
 * отклоняется валидацией, платёж при этом не создаётся.
 */
final class ReturnUrlEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        config([
            'app.url' => 'https://api.example.ru',
            'services.yookassa.return_url' => 'https://store.example.ru/payment/success',
            'services.yookassa.allowed_return_hosts' => '',
            'services.yookassa.allowed_return_schemes' => '',
        ]);
    }

    // ==================== Создание заказа ====================

    public function test_create_order_passes_return_url_to_gateway(): void
    {
        [$user, $payload] = $this->checkoutFixture();

        $this->actingAs($user)
            ->postJson('/api/v1/orders', $payload + [
                'paymentType' => 'card',
                'returnUrl' => '/payment/success?orderId=42',
            ])
            ->assertCreated();

        $this->assertSame(
            'https://store.example.ru/payment/success?orderId=42',
            $this->lastHoldReturnUrl(),
        );
    }

    public function test_create_order_without_return_url_leaves_default_to_gateway(): void
    {
        [$user, $payload] = $this->checkoutFixture();

        $this->actingAs($user)
            ->postJson('/api/v1/orders', $payload + ['paymentType' => 'card'])
            ->assertCreated();

        // null → шлюз подставит дефолтный адрес из конфига.
        $this->assertNull($this->lastHoldReturnUrl());
    }

    public function test_create_order_with_foreign_return_url_is_rejected(): void
    {
        [$user, $payload] = $this->checkoutFixture();

        $this->actingAs($user)
            ->postJson('/api/v1/orders', $payload + [
                'paymentType' => 'card',
                'returnUrl' => 'https://evil.tld/payment/success',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('returnUrl');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame([], $this->gateway->calls, 'платёж не должен создаваться');
    }

    // ==================== Повторная оплата заказа ====================

    public function test_pay_passes_return_url_to_gateway(): void
    {
        [$user, $order] = $this->unpaidOrder();

        $this->actingAs($user)
            ->postJson("/api/v1/orders/{$order->id}/pay", [
                'returnUrl' => 'https://store.example.ru/orders/'.$order->id,
            ])
            ->assertOk()
            ->assertJsonPath('confirmationUrl', $this->gateway->confirmationUrl);

        $this->assertSame(
            'https://store.example.ru/orders/'.$order->id,
            $this->lastHoldReturnUrl(),
        );
    }

    public function test_pay_with_foreign_return_url_is_rejected(): void
    {
        [$user, $order] = $this->unpaidOrder();

        $this->actingAs($user)
            ->postJson("/api/v1/orders/{$order->id}/pay", [
                'returnUrl' => 'https://evil.tld/orders/'.$order->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('returnUrl');

        $this->assertSame([], $this->gateway->calls);
    }

    // ==================== Привязка карты ====================

    public function test_bind_card_passes_return_url_to_gateway(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/payment-cards/bind', ['returnUrl' => '/profile/cards'])
            ->assertOk()
            ->assertJsonPath('confirmationUrl', $this->gateway->confirmationUrl);

        $this->assertSame('https://store.example.ru/profile/cards', $this->lastHoldReturnUrl());
    }

    public function test_bind_card_accepts_deeplink_from_white_list(): void
    {
        config(['services.yookassa.allowed_return_schemes' => 'mendevapp']);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/payment-cards/bind', ['returnUrl' => 'mendevapp://profile/cards'])
            ->assertOk();

        $this->assertSame('mendevapp://profile/cards', $this->lastHoldReturnUrl());
    }

    public function test_bind_card_with_foreign_return_url_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/payment-cards/bind', ['returnUrl' => 'https://evil.tld/cards'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('returnUrl');

        $this->assertSame([], $this->gateway->calls);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_bind_card_rejects_protocol_relative_url(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/payment-cards/bind', ['returnUrl' => '//evil.tld/cards'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('returnUrl');
    }

    // ==================== Фикстуры ====================

    /**
     * Корзина с одним товаром + адрес и слот: боевой путь оформления заказа
     * (FakeDeliveryClient / FakeSlotsApiClient подставляет AppServiceProvider).
     *
     * @return array{0: User, 1: array<string, mixed>}
     */
    private function checkoutFixture(): array
    {
        $user = User::factory()->create();
        $store = Store::factory()->create([
            'min_order_amount' => null,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100,
            'quantity' => 10,
            'quantity_step' => 1,
            'measurement_unit' => 'piece',
        ]);
        $address = Address::factory()->active()->for($user)->create();

        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create([
            'store_id' => $store->id,
            'quantity' => 1,
            'price' => 100,
        ]);

        return [$user, [
            'storeId' => $store->id,
            'addressId' => $address->id,
            'deliverySlotId' => $this->getValidDeliverySlotId(),
        ]];
    }

    /**
     * @return array{0: User, 1: Order}
     */
    private function unpaidOrder(): array
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();

        $order = Order::factory()->for($user)->for($store)->create([
            'total' => 500,
            'payment_type' => 'card',
        ]);

        return [$user, $order];
    }

    private function lastHoldReturnUrl(): ?string
    {
        $holds = array_values(array_filter(
            $this->gateway->calls,
            static fn (array $call): bool => $call['method'] === 'hold',
        ));

        $this->assertNotEmpty($holds, 'шлюз должен получить hold');

        return end($holds)['args']['returnUrl'];
    }
}
