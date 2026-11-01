<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Contracts\PaymentGateway;
use App\Enums\MeasurementUnit;
use App\Enums\OrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\PaymentType;
use App\Enums\ShippingMethod;
use App\Jobs\PollAtolReceiptJob;
use App\Jobs\SendOrderStatusNotification;
use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Models\PaymentCard;
use App\Models\PaymentReceipt;
use App\Models\PaymentTransaction;
use App\Models\Store;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * Чек прихода по карте после сборки строится по ФАКТИЧЕСКИМ позициям.
 *
 * Прод-заказ 1064 (17.10.2026): списание при order.updated=assembled выполнялось
 * в beforeUpdate, а позиции по факту сборки записываются только в afterUpdate.
 * FiscalizeSaleJob ставился в очередь из capturePayment, воркер забирал его за
 * миллисекунды и читал позиции ещё в заказанном виде: в чеке «Филе 1,5 кг»
 * вместо привезённых 750 г, итог фактический, а суммы строк разнесены
 * пропорционально заказанному. На проде так пробит 131 карточный чек из 529.
 *
 * Синхронная очередь теста = самый быстрый воркер: чек строится в момент диспатча.
 */
final class AssembledCardReceiptUsesFinalItemsTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake([PollAtolReceiptJob::class, SendOrderStatusNotification::class]);
        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

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
    private function makeOrder(): Order
    {
        $user = User::factory()->create(['email' => 'buyer@example.com']);
        $store = Store::factory()->create();
        $card = PaymentCard::create([
            'user_id' => $user->id,
            'payment_method_id' => 'pm_test_card',
            'card_type' => 'MasterCard',
            'last_four' => '4444',
            'expiry_month' => 12,
            'expiry_year' => 2030,
            'is_default' => true,
        ]);

        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Assembling,
            'shipping_method' => ShippingMethod::Courier,
            'payment_type' => PaymentType::Card,
            'subtotal' => 846,
            'discount' => 0,
            'delivery_cost' => 0,
            'assembly_cost' => 0,
            'total' => 846,
            'held_amount' => 846,
            'captured_amount' => null,
            'payment_card_id' => $card->id,
        ]);

        $order->items()->create([
            'product_id' => null,
            'title' => 'Филе Грудки Куриное',
            'sku' => 'FILLET',
            'quantity' => 1500,
            'measurement_unit' => MeasurementUnit::Gram,
            'price' => 252,
            'total' => 756,
            'eligible_for_bonus' => true,
            'status' => null,
        ]);
        $order->items()->create([
            'product_id' => null,
            'title' => 'Пирожок С Яйцом',
            'sku' => 'PIE',
            'quantity' => 2,
            'measurement_unit' => MeasurementUnit::Piece,
            'price' => 45,
            'total' => 90,
            'eligible_for_bonus' => true,
            'status' => null,
        ]);

        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Hold,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 846,
            'yookassa_payment_id' => 'pay_hold_1064',
        ]);

        return $order->fresh();
    }

    /**
     * Событие «собрано» из курьерки: филе взвесили на 750 г (378 ₽), пирожки как заказаны.
     *
     * @return array<string, mixed>
     */
    private function assembledEvent(Order $order): array
    {
        $fillet = $order->items->firstWhere('sku', 'FILLET');
        $pie = $order->items->firstWhere('sku', 'PIE');
        $source = config('rabbitmq-events.source');

        return [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'status' => 'assembled',
            'grand_total' => 468.0,
            'shipping_total' => 0,
            'items' => [
                [
                    'id' => (string) $fillet->id,
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
                    'id' => (string) $pie->id,
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

    /**
     * Повторяет путь обновления в EventConsumer: beforeUpdate → forceFill/save
     * без событий модели → afterUpdate по свежей модели.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function consume(Order $order, array $eventData): void
    {
        $transformer = app(OrderEventTransformer::class);
        $transformed = $transformer->beforeUpdate($order, $eventData, ['status' => OrderStatus::Assembled]);

        $order->withoutEvents(fn () => $order->forceFill($transformed)->save());

        $transformer->afterUpdate($order->fresh(), $eventData);
    }

    public function test_card_receipt_after_assembly_has_actual_weight_and_line_sums(): void
    {
        $order = $this->makeOrder();

        $this->consume($order, $this->assembledEvent($order));

        $captures = $this->gateway->callsFor('capture');
        $this->assertCount(1, $captures, 'списание по факту сборки выполнено');
        $this->assertSame(468.0, $captures[0]['args']['amount']);

        $receipt = PaymentReceipt::where('order_id', $order->id)->where('type', 'payment')->firstOrFail();
        $items = collect($receipt->items)->keyBy('description');

        $this->assertSame('0.75', $items['Филе Грудки Куриное']['quantity'], 'в чеке привезённый вес, а не заказанный');
        $this->assertSame('кг', $items['Филе Грудки Куриное']['unit']);
        $this->assertSame('378.00', $items['Филе Грудки Куриное']['sum'], 'сумма строки = фактическая стоимость позиции');
        $this->assertSame('2.00', $items['Пирожок С Яйцом']['quantity']);
        $this->assertSame('90.00', $items['Пирожок С Яйцом']['sum']);
        $this->assertSame('468.00', number_format((float) $receipt->amount, 2, '.', ''));
    }

    public function test_repeated_assembled_event_does_not_capture_twice(): void
    {
        $order = $this->makeOrder();
        $event = $this->assembledEvent($order);

        $this->consume($order, $event);
        $this->consume($order->fresh(), array_merge($event, ['event_id' => Str::uuid()->toString()]));

        $this->assertCount(1, $this->gateway->callsFor('capture'));
        $this->assertSame(1, PaymentReceipt::where('order_id', $order->id)->where('type', 'payment')->count());
    }

    public function test_assembled_event_on_cancelled_order_does_not_capture(): void
    {
        $order = $this->makeOrder();
        $order->forceFill(['status' => OrderStatus::Cancelled])->saveQuietly();

        $this->consume($order->fresh(), $this->assembledEvent($order));

        $this->assertCount(0, $this->gateway->callsFor('capture'), 'terminal-guard: отменённый заказ не списываем');
    }
}
