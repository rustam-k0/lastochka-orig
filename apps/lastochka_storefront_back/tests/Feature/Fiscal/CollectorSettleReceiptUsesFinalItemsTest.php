<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Contracts\PaymentGateway;
use App\DTOs\Payment\ReceiptData;
use App\DTOs\Payment\ReceiptItemData;
use App\Enums\MeasurementUnit;
use App\Enums\OrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\PaymentType;
use App\Enums\ShippingMethod;
use App\Jobs\PollAtolReceiptJob;
use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Models\PaymentCard;
use App\Models\PaymentReceipt;
use App\Models\PaymentTransaction;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * Модуль collector_payment: сборщик списывает оплату синхронным запросом
 * delivery_back → POST /internal/orders/{id}/settle-payment ДО того, как событие
 * order.updated с фактическими позициями дойдёт до витрины. Без позиций в запросе
 * чек прихода (АТОЛ — FiscalizeSaleJob, ЮKassa — receipt в запросе capture)
 * строился по заказанным количествам. delivery_back передаёт в `order_event`
 * тот же payload, что публикует в order.updated, и витрина применяет позиции
 * до списания.
 */
final class CollectorSettleReceiptUsesFinalItemsTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake([PollAtolReceiptJob::class]);
        config(['services.internal_api.token' => 'test-internal-token']);
        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode(['collector_payment' => ['label' => 'x', 'enabled' => true]], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ],
        );
        app(ModuleConfigService::class)->invalidateCache();
    }

    private function atolDriver(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'atol', 'is_active' => true,
            'credentials' => ['atol' => ['mode' => 'test', 'login' => 'u', 'password' => 'p',
                'group_code' => 'grp', 'inn' => '77', 'payment_address' => 'a', 'company_email' => 'k@e.ru',
                'vat' => 'none']]]);
        Http::fake([
            '*/getToken' => Http::response(['token' => 'TKN']),
            '*/grp/sell*' => Http::response(['uuid' => 'U-SELL', 'status' => 'wait']),
        ]);
    }

    /**
     * Заказано: филе 1500 г на 756 ₽ и 2 пирожка по 45 ₽. Холд 846 ₽.
     */
    private function heldOrder(): Order
    {
        $user = User::factory()->create(['email' => 'buyer@example.com']);
        $store = Store::factory()->create();
        $card = PaymentCard::create([
            'user_id' => $user->id, 'payment_method_id' => 'pm_test',
            'card_type' => 'Visa', 'last_four' => '4444',
            'expiry_month' => '12', 'expiry_year' => '2030',
        ]);
        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Assembling,
            'shipping_method' => ShippingMethod::Courier,
            'payment_type' => PaymentType::Card,
            'subtotal' => 846, 'discount' => 0, 'delivery_cost' => 0, 'assembly_cost' => 0,
            'total' => 846, 'held_amount' => 846, 'captured_amount' => null,
            'payment_card_id' => $card->id,
        ]);
        $order->items()->create([
            'title' => 'Филе Грудки Куриное', 'sku' => 'FILLET', 'quantity' => 1500,
            'measurement_unit' => MeasurementUnit::Gram, 'price' => 252, 'total' => 756,
            'eligible_for_bonus' => true, 'status' => null,
        ]);
        $order->items()->create([
            'title' => 'Пирожок С Яйцом', 'sku' => 'PIE', 'quantity' => 2,
            'measurement_unit' => MeasurementUnit::Piece, 'price' => 45, 'total' => 90,
            'eligible_for_bonus' => true, 'status' => null,
        ]);
        PaymentTransaction::create([
            'order_id' => $order->id, 'type' => PaymentTransactionType::Hold,
            'status' => PaymentTransactionStatus::Succeeded, 'amount' => 846,
            'yookassa_payment_id' => 'pay_hold_1', 'payment_method_id' => 'pm_test',
        ]);

        return $order->fresh();
    }

    /**
     * Payload order.updated от delivery_back по итогам сборки: филе 750 г (378 ₽).
     *
     * @return array<string, mixed>
     */
    private function orderEvent(Order $order): array
    {
        $source = config('rabbitmq-events.source');

        return [
            'id' => (string) $order->id,
            'status' => 'assembling',
            'grand_total' => 468.0,
            'shipping_total' => 0,
            'items' => [
                [
                    'id' => (string) $order->items->firstWhere('sku', 'FILLET')->id,
                    'source' => $source,
                    'product_name' => 'Филе Грудки Куриное',
                    'item_status' => 'confirmed',
                    'quantity' => 3,
                    'actual_weight' => 750,
                    'unit_price' => 252,
                    'unit_price_effective' => 252,
                    'line_total' => 378,
                ],
                [
                    'id' => (string) $order->items->firstWhere('sku', 'PIE')->id,
                    'source' => $source,
                    'product_name' => 'Пирожок С Яйцом',
                    'item_status' => 'confirmed',
                    'quantity' => 2,
                    'unit_price' => 45,
                    'unit_price_effective' => 45,
                    'line_total' => 90,
                ],
            ],
        ];
    }

    private function settle(Order $order, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('X-Internal-Token', 'test-internal-token')
            ->postJson("/api/v1/internal/orders/{$order->id}/settle-payment", $payload);
    }

    public function test_atol_receipt_uses_items_from_settle_request(): void
    {
        $this->atolDriver();
        $order = $this->heldOrder();

        $this->settle($order, ['amount' => 468, 'order_event' => $this->orderEvent($order)])
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertSame(468.0, $this->gateway->callsFor('capture')[0]['args']['amount']);

        $receipt = PaymentReceipt::where('order_id', $order->id)->where('type', 'payment')->firstOrFail();
        $items = collect($receipt->items)->keyBy('description');
        $this->assertSame('0.75', $items['Филе Грудки Куриное']['quantity'], 'в чеке привезённый вес');
        $this->assertSame('378.00', $items['Филе Грудки Куриное']['sum']);
        $this->assertSame('90.00', $items['Пирожок С Яйцом']['sum']);

        $fillet = $order->items()->where('sku', 'FILLET')->firstOrFail();
        $this->assertSame(750, (int) $fillet->quantity, 'позиции витрины обновлены до списания');
    }

    public function test_yookassa_capture_receipt_uses_items_from_settle_request(): void
    {
        IntegrationConfig::create(['integration_type' => 'fiscal', 'driver' => 'yookassa', 'is_active' => true,
            'credentials' => []]);
        config(['services.yookassa.send_receipt' => true]);
        $order = $this->heldOrder();

        $this->settle($order, ['amount' => 468, 'order_event' => $this->orderEvent($order)])->assertOk();

        /** @var ReceiptData $receipt */
        $receipt = $this->gateway->callsFor('capture')[0]['args']['receipt'];
        $this->assertNotNull($receipt, 'ЮKassa получает чек в запросе capture');
        $sums = collect($receipt->items)->mapWithKeys(
            fn (ReceiptItemData $i) => [$i->description => round($i->amount * $i->quantity, 2)],
        );
        $this->assertEqualsWithDelta(378.0, $sums['Филе Грудки Куриное'], 0.02, 'строка филе по факту сборки');
        $this->assertEqualsWithDelta(90.0, $sums['Пирожок С Яйцом'], 0.02);
    }

    public function test_settle_without_order_event_still_settles(): void
    {
        // Обратная совместимость: delivery_back форка, ещё не присылающий позиции.
        $this->atolDriver();
        $order = $this->heldOrder();

        $this->settle($order, ['amount' => 468])->assertOk()->assertJsonPath('status', 'ok');

        $this->assertSame(468.0, $this->gateway->callsFor('capture')[0]['args']['amount']);
    }

    public function test_order_event_must_be_an_object(): void
    {
        $order = $this->heldOrder();

        $this->settle($order, ['amount' => 468, 'order_event' => 'broken'])->assertStatus(422);
        $this->assertCount(0, $this->gateway->callsFor('capture'));
    }
}
