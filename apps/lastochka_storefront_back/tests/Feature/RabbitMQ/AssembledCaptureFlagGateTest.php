<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\PaymentType;
use App\Jobs\SendOrderStatusNotification;
use App\Models\Order;
use App\Models\PaymentCard;
use App\Models\PaymentTransaction;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * Гейт старого асинхронного event-capture фича-флагом collector_payment
 * (OrderEventTransformer::afterUpdate → captureOnAssembled при order.updated=assembled).
 *
 * При ВЫКЛЮЧЕННОМ модуле storefront по-прежнему списывает оплату по входящему
 * событию (существующее поведение). При ВКЛЮЧЁННОМ collector_payment списание
 * выполняется синхронно мутацией сборщика (settleAssemblyPayment), поэтому
 * событийный capture обязан отключиться — иначе гонка / двойное списание.
 */
final class AssembledCaptureFlagGateTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Bus::fake([SendOrderStatusNotification::class]);
        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    private function enableCollectorPayment(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode(
                    ['collector_payment' => ['label' => 'x', 'enabled' => true]],
                    JSON_UNESCAPED_UNICODE,
                ),
                'type' => 'json',
            ],
        );

        app(ModuleConfigService::class)->invalidateCache();
    }

    /**
     * Card-заказ на статусе Assembling с активным холдом и сохранённой картой —
     * готовый к событийному capture: без гейта order.updated(assembled) списал бы
     * холд (и досписал бы разницу при grand_total > held).
     */
    private function makeAssembledCardOrder(Store $store, User $user): Order
    {
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
            'payment_type' => PaymentType::Card,
            'total' => 500,
            'held_amount' => 500,
            'captured_amount' => null,
            'payment_card_id' => $card->id,
        ]);

        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Hold,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 500,
            'yookassa_payment_id' => 'pay_hold_1',
        ]);

        return $order;
    }

    private function incomingAssembled(Order $order, float $grandTotal): void
    {
        $transformer = new OrderEventTransformer;
        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'status' => 'assembled',
            'grand_total' => $grandTotal,
        ];
        // Путь обновления EventConsumer: capture выполняется в afterUpdate,
        // после синхронизации позиций (чек прихода строится по факту сборки).
        $transformed = $transformer->beforeUpdate($order, $eventData, ['status' => OrderStatus::Assembled]);
        $order->withoutEvents(fn () => $order->forceFill($transformed)->save());
        $transformer->afterUpdate($order->fresh(), $eventData);
    }

    public function test_module_on_skips_event_capture_and_charge(): void
    {
        $this->enableCollectorPayment();

        $store = Store::factory()->create(['code' => 'MSK_CP_ON']);
        $user = User::factory()->create();
        $order = $this->makeAssembledCardOrder($store, $user);

        // grand_total (700) > held (500): без гейта сработали бы и capture, и charge.
        $this->incomingAssembled($order, 700.0);

        $this->assertCount(0, $this->gateway->callsFor('capture'), 'collector_payment ON: событийный capture отключён');
        $this->assertCount(0, $this->gateway->callsFor('charge'), 'collector_payment ON: событийного досписания нет');
    }

    public function test_module_off_captures_on_assembled_event(): void
    {
        // Модуль по умолчанию OFF — существующее асинхронное поведение сохранено.
        $store = Store::factory()->create(['code' => 'MSK_CP_OFF']);
        $user = User::factory()->create();
        $order = $this->makeAssembledCardOrder($store, $user);

        // grand_total (500) == held (500): срабатывает только capture на фактическую сумму.
        $this->incomingAssembled($order, 500.0);

        $captureCalls = $this->gateway->callsFor('capture');
        $this->assertCount(1, $captureCalls, 'collector_payment OFF: событийный capture выполняется');
        $this->assertSame(500.0, $captureCalls[0]['args']['amount']);
    }

    public function test_module_off_exceeding_hold_charges_extra_first_then_captures(): void
    {
        // Багфикс порядка в legacy-ветке (модуль OFF): досписание разницы идёт
        // ПЕРВЫМ, capture холда — вторым. Итоговые суммы прежние.
        $store = Store::factory()->create(['code' => 'MSK_CP_OFF2']);
        $user = User::factory()->create();
        $order = $this->makeAssembledCardOrder($store, $user);

        $this->incomingAssembled($order, 700.0);

        $methods = array_column($this->gateway->calls, 'method');
        $chargeIdx = array_search('charge', $methods, true);
        $captureIdx = array_search('capture', $methods, true);

        $this->assertNotFalse($chargeIdx, 'досписание вызвано');
        $this->assertNotFalse($captureIdx, 'capture вызван');
        $this->assertLessThan($captureIdx, $chargeIdx, 'досписание идёт ПЕРЕД capture холда');
        $this->assertSame(200.0, $this->gateway->callsFor('charge')[0]['args']['amount']);
        $this->assertSame(500.0, $this->gateway->callsFor('capture')[0]['args']['amount']);
    }

    public function test_module_off_sub_minimum_shortfall_skips_charge_and_captures_hold(): void
    {
        // Тот же порог, что в синхронном флоу (PaymentService::MIN_GATEWAY_CHARGE):
        // недобор в 9 копеек шлюз не проведёт, поэтому доплату не запрашиваем —
        // капчурим холд и не помечаем заказ «Ошибка оплаты» из-за копеек.
        $store = Store::factory()->create(['code' => 'MSK_CP_OFF4']);
        $user = User::factory()->create();
        $order = $this->makeAssembledCardOrder($store, $user);

        $this->incomingAssembled($order, 500.09);

        $this->assertCount(0, $this->gateway->callsFor('charge'), 'копеечный недобор в шлюз не уходит');
        $captureCalls = $this->gateway->callsFor('capture');
        $this->assertCount(1, $captureCalls);
        $this->assertSame(500.0, $captureCalls[0]['args']['amount']);
        $this->assertTrue((bool) $order->fresh()->is_paid);

        $this->assertDatabaseMissing('event_outbox', [
            'entity_type' => 'orders',
            'routing_key' => 'order.order_payment_failed',
        ]);
    }

    public function test_module_off_failed_extra_still_captures_hold_and_publishes_payment_failed(): void
    {
        // Провал досписания в legacy-ветке: холд всё равно капчурится (мерчант
        // получает захолдированное), а недосбор перестаёт быть молчаливым —
        // публикуется payment_failed (бейдж «Ошибка оплаты» в АП).
        $store = Store::factory()->create(['code' => 'MSK_CP_OFF3']);
        $user = User::factory()->create();
        $order = $this->makeAssembledCardOrder($store, $user);

        $this->gateway->chargeShouldFail = true;

        $this->incomingAssembled($order, 700.0);

        $this->assertCount(1, $this->gateway->callsFor('charge'), 'досписание попытались выполнить');
        $captureCalls = $this->gateway->callsFor('capture');
        $this->assertCount(1, $captureCalls, 'холд капчурится несмотря на провал досписания');
        $this->assertSame(500.0, $captureCalls[0]['args']['amount']);

        $this->assertTrue((bool) $order->fresh()->is_paid, 'холд собран — заказ помечен оплаченным');

        $this->assertDatabaseHas('event_outbox', [
            'entity_type' => 'orders',
            'routing_key' => 'order.order_payment_failed',
        ]);
    }
}
