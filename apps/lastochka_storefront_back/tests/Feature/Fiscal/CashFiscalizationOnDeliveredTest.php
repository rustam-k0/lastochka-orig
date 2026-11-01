<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Jobs\FiscalizeSaleJob;
use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Task 9: факт доставки cash-заказа = факт оплаты → фискализация наличных.
 *
 * Триггер живёт в OrderEventTransformer::afterUpdate, внутри ветки
 * `$newStatus === OrderStatus::Delivered`. Внутри этой ветки триггер не
 * гейтится на КОНКРЕТНЫЙ фискальный драйвер — наличные фискализируются при
 * ЛЮБОМ активном драйвере (ATOL — обычная продажа, YooKassa — независимый
 * чек).
 *
 * GAP-2 (rollout safety): весь блок ДОБАВЛЕНО гейтится на наличие активной
 * фискальной интеграции вообще (`IntegrationConfigService::get('fiscal')`).
 * Без сконфигурированного драйвера FiscalizerFactory падает на
 * YooKassaFiscalizer → createIndependentReceipt бил бы реальный
 * `POST /receipts` на каждую cash-доставку на форках, которые фискализацию
 * не подключали. Поэтому тесты этого файла явно создают IntegrationConfig
 * (driver=atol), а отдельный тест проверяет поведение БЕЗ конфигурации.
 */
final class CashFiscalizationOnDeliveredTest extends TestCase
{
    use RefreshDatabase;

    private function atolConfig(): void
    {
        IntegrationConfig::create([
            'integration_type' => 'fiscal',
            'driver' => 'atol',
            'is_active' => true,
            'credentials' => ['atol' => [
                'mode' => 'test',
                'login' => 'u',
                'password' => 'p',
                'group_code' => 'grp',
            ]],
        ]);
    }

    private function incomingDelivered(Order $order, float $grandTotal): void
    {
        (new OrderEventTransformer)->afterUpdate($order, [
            'status' => 'delivered',
            'grand_total' => $grandTotal,
        ]);
    }

    private function cashOrder(float $total = 300): Order
    {
        $store = Store::factory()->create();
        $user = User::factory()->create();

        return Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Assembled,
            'payment_type' => PaymentType::Cash,
            'is_paid' => false,
            'total' => $total,
        ]);
    }

    public function test_cash_delivered_marks_paid_and_dispatches_sale_job(): void
    {
        Queue::fake();
        $this->atolConfig();
        $order = $this->cashOrder(300);

        $this->incomingDelivered($order, 300.0);

        $this->assertTrue($order->fresh()->is_paid);
        Queue::assertPushed(FiscalizeSaleJob::class, function (FiscalizeSaleJob $job) use ($order) {
            return $this->jobOrderId($job) === $order->id
                && $this->jobPaymentType($job) === PaymentType::Cash->value
                && abs($this->jobAmount($job) - 300.0) < 0.001;
        });
    }

    public function test_cash_delivered_full_return_does_not_fiscalize(): void
    {
        Queue::fake();
        $this->atolConfig();
        $order = $this->cashOrder(300);

        $this->incomingDelivered($order, 0.0);

        $this->assertFalse($order->fresh()->is_paid);
        Queue::assertNotPushed(FiscalizeSaleJob::class);
    }

    public function test_delivered_twice_is_idempotent(): void
    {
        Queue::fake();
        $this->atolConfig();
        $order = $this->cashOrder(300);

        $this->incomingDelivered($order, 300.0);
        $this->incomingDelivered($order->fresh(), 300.0);

        Queue::assertPushed(FiscalizeSaleJob::class, 1);
    }

    /**
     * GAP-2: без активной фискальной интеграции (нет IntegrationConfig fiscal
     * или она неактивна) cash-доставка ведёт себя КАК ДО этой фичи — is_paid
     * не флипается, ATOL-джоб не диспатчится. Никакого фолбэка на
     * YooKassaFiscalizer::createIndependentReceipt для форков, которые
     * фискализацию не подключали.
     */
    public function test_cash_delivered_without_fiscal_config_does_not_mark_paid_or_dispatch(): void
    {
        Queue::fake();
        // Намеренно НЕ вызываем atolConfig() — фискальная интеграция отсутствует.
        $order = $this->cashOrder(300);

        $this->incomingDelivered($order, 300.0);

        $this->assertFalse($order->fresh()->is_paid);
        Queue::assertNotPushed(FiscalizeSaleJob::class);
    }

    // ------------------------------------------------------------------
    // Reflection helpers — FiscalizeSaleJob хранит orderId/paymentType/amount
    // в private промоутед-свойствах без публичных геттеров.
    // ------------------------------------------------------------------

    private function jobOrderId(FiscalizeSaleJob $job): int
    {
        return $this->readPrivateProperty($job, 'orderId');
    }

    private function jobPaymentType(FiscalizeSaleJob $job): string
    {
        return $this->readPrivateProperty($job, 'paymentType');
    }

    private function jobAmount(FiscalizeSaleJob $job): float
    {
        return $this->readPrivateProperty($job, 'amount');
    }

    private function readPrivateProperty(object $object, string $property): mixed
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setAccessible(true);

        return $ref->getValue($object);
    }
}
