<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Contracts\PaymentGateway;
use App\DTOs\Payment\PaymentInfo;
use App\DTOs\Payment\PaymentResult;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\PaymentCard;
use App\Models\PaymentTransaction;
use App\Services\Payment\PaymentService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * GlitchTip STOREFRONT_BACK-CV (#837): deadlock на строке orders.
 *
 * INSERT в payment_transactions берёт S-lock на родительскую строку orders
 * (FK order_id), следующий UPDATE orders требует X-lock. Две такие транзакции
 * по одному заказу (capturePayment из settle-payment сборщика + вебхук
 * payment.succeeded, который ЮKassa шлёт через ~200 мс после capture) держат
 * по S-lock и обе ждут X-lock: InnoDB откатывает одну. Более частый исход той
 * же гонки — дубль capture-строки с одним yookassa_payment_id.
 *
 * Фикс: X-lock (lockForUpdate) на строку заказа ПЕРВЫМ оператором транзакции,
 * до вставки в payment_transactions. SQLite отбрасывает `for update`, поэтому
 * грамматика подменяется: lock компилируется в SQL-комментарий-маркер, по
 * которому проверяется порядок запросов внутри транзакции.
 */
final class PaymentServiceOrderLockTest extends TestCase
{
    use RefreshDatabase;

    private const LOCK_MARKER = '/* lock:for_update */';

    private const BEGIN = '<transaction:begin>';

    private FakePaymentGateway $gateway;

    private PaymentService $service;

    /** @var list<string> */
    private array $timeline = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
        $this->service = $this->app->make(PaymentService::class);

        $connection = DB::connection();
        $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar
        {
            protected function compileLock(Builder $query, $value)
            {
                return $value === true ? '/* lock:for_update */' : parent::compileLock($query, $value);
            }
        });

        Event::listen(TransactionBeginning::class, function (): void {
            $this->timeline[] = self::BEGIN;
        });
        DB::listen(function (QueryExecuted $query): void {
            $this->timeline[] = $query->sql;
        });
    }

    public function test_capture_payment_locks_order_before_inserting_capture_transaction(): void
    {
        [$order] = $this->cardOrderWithHold(1000.0);

        $this->act(fn () => $this->service->capturePayment($order, 1000.0));

        $this->assertOrderLockedFirstInTransactionBefore('insert into "payment_transactions"');
    }

    public function test_handle_payment_succeeded_locks_order_before_idempotency_check(): void
    {
        [, $paymentId] = $this->cardOrderWithHold(1000.0);
        $this->gateway->paymentInfo[$paymentId] = new PaymentInfo(
            paymentId: $paymentId,
            status: 'succeeded',
            amount: '1000.00',
            currency: 'RUB',
        );

        $this->act(fn () => $this->service->handlePaymentSucceeded($paymentId));

        $this->assertOrderLockedFirstInTransactionBefore('select exists(select * from "payment_transactions"');
    }

    public function test_charge_extra_locks_order_before_inserting_charge_transaction(): void
    {
        [$order] = $this->cardOrderWithHold(1000.0);
        $card = PaymentCard::create([
            'user_id' => $order->user_id,
            'payment_method_id' => 'pm_test_card',
            'card_type' => 'Visa',
            'last_four' => '1234',
            'expiry_month' => '12',
            'expiry_year' => '2030',
            'is_default' => true,
        ]);
        $order->update(['payment_card_id' => $card->id]);

        $this->act(fn () => $this->service->chargeExtra($order->fresh(), 150.0));

        $this->assertOrderLockedFirstInTransactionBefore('insert into "payment_transactions"');
    }

    public function test_attach_preauthorized_hold_locks_order_before_inserting_hold_transaction(): void
    {
        $order = Order::factory()->create([
            'payment_type' => PaymentType::Card,
            'total' => 1000.0,
            'is_paid' => false,
        ]);
        $preauth = new PaymentResult(
            success: true,
            paymentId: 'pay_test_preauth',
            status: 'waiting_for_capture',
            paymentMethodId: 'pm_test_card',
        );

        $this->act(fn () => $this->service->attachPreauthorizedHold($order, $preauth, 1000.0));

        $this->assertOrderLockedFirstInTransactionBefore('insert into "payment_transactions"');
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * @return array{0: Order, 1: string}
     */
    private function cardOrderWithHold(float $total): array
    {
        $order = Order::factory()->create([
            'payment_type' => PaymentType::Card,
            'total' => $total,
            'held_amount' => $total,
            'is_paid' => false,
        ]);

        $paymentId = 'pay_test_hold_'.$order->id;

        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Hold,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => $total,
            'yookassa_payment_id' => $paymentId,
        ]);

        return [$order, $paymentId];
    }

    /**
     * Выполнить действие с чистым таймлайном: сетап теста в проверку не попадает.
     */
    private function act(callable $action): void
    {
        $this->timeline = [];
        $action();
    }

    /**
     * Строка заказа заблокирована lockForUpdate первым запросом транзакции,
     * и это произошло раньше запроса $needle.
     */
    private function assertOrderLockedFirstInTransactionBefore(string $needle): void
    {
        $dump = fn (): string => "\nТаймлайн:\n - ".implode("\n - ", $this->timeline);

        $target = $this->firstIndex(fn (string $e) => str_contains($e, $needle));
        $this->assertNotNull($target, "Запрос «{$needle}» не выполнялся.".$dump());

        $lock = $this->firstIndex(
            fn (string $e) => str_contains($e, self::LOCK_MARKER) && str_contains($e, 'from "orders"'),
        );
        $this->assertNotNull($lock, 'Строка заказа не блокировалась lockForUpdate.'.$dump());
        $this->assertLessThan($target, $lock, "lockForUpdate по заказу должен идти ДО «{$needle}».".$dump());

        $this->assertGreaterThan(0, $lock, 'lockForUpdate вне транзакции.'.$dump());
        $this->assertSame(
            self::BEGIN,
            $this->timeline[$lock - 1],
            'lockForUpdate по заказу должен быть ПЕРВЫМ запросом транзакции.'.$dump(),
        );
    }

    /**
     * @param  callable(string): bool  $match
     */
    private function firstIndex(callable $match): ?int
    {
        foreach ($this->timeline as $i => $entry) {
            if ($match($entry)) {
                return $i;
            }
        }

        return null;
    }
}
