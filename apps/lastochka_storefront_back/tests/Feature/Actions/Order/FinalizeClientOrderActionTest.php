<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\Order;

use App\Actions\Order\FinalizeClientOrderAction;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\Order\ClientOrderStatusAggregator;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Unit\Actions\Order\SpyFinalizeClientOrderAction;

/**
 * Feature-уровень: проверка что FinalizeClientOrderAction корректно работает через DI container
 * Laravel + обновляет БД. Детали бизнес-логики покрыты unit-тестом {@see \Tests\Unit\Actions\Order\FinalizeClientOrderActionTest}.
 *
 * PaymentService::captureClientOrder / cancelConsolidatedHold / refundClientOrder ещё НЕ существуют
 * в main ветке storefront_back (придут с P2.1 / feature/marketplace-p2-payment). Поэтому container
 * биндим на {@see SpyFinalizeClientOrderAction} — spy перехватывает protected хуки do*.
 * После финального merge P2.1 спай можно будет убрать и использовать настоящий PaymentService.
 */
final class FinalizeClientOrderActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(FinalizeClientOrderAction::class, static fn ($app): SpyFinalizeClientOrderAction => new SpyFinalizeClientOrderAction(
            $app->make(ClientOrderStatusAggregator::class),
            $app->make(PaymentService::class),
        ));
    }

    public function test_finalize_through_container_marks_all_sub_orders_finalized(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $clientOrderId = (string) Str::uuid();

        Order::factory()->for($user)->for($store)->delivered()->create([
            'client_order_id' => $clientOrderId,
            'payment_type' => PaymentType::Card,
            'total' => 1000,
            'held_amount' => 1000.00,
        ]);
        Order::factory()->for($user)->for($store)->delivered()->create([
            'client_order_id' => $clientOrderId,
            'payment_type' => PaymentType::Card,
            'total' => 500,
            'held_amount' => 500.00,
        ]);

        /** @var SpyFinalizeClientOrderAction $action */
        $action = app(FinalizeClientOrderAction::class);
        $action->execute($clientOrderId);

        $this->assertSame(2, Order::query()
            ->where('client_order_id', $clientOrderId)
            ->whereNotNull('client_order_finalized_at')
            ->count());

        $this->assertCount(1, $action->calls);
        $this->assertSame('captureClientOrder', $action->calls[0]['method']);
        $this->assertSame(1500.0, $action->calls[0]['amount']);
    }

    public function test_finalize_through_container_skips_when_not_all_terminal(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $clientOrderId = (string) Str::uuid();

        Order::factory()->for($user)->for($store)->delivered()->create([
            'client_order_id' => $clientOrderId,
            'payment_type' => PaymentType::Card,
            'total' => 1000,
            'held_amount' => 1000.00,
        ]);
        Order::factory()->for($user)->for($store)->assembling()->create([
            'client_order_id' => $clientOrderId,
            'payment_type' => PaymentType::Card,
            'total' => 500,
            'held_amount' => 500.00,
        ]);

        /** @var SpyFinalizeClientOrderAction $action */
        $action = app(FinalizeClientOrderAction::class);
        $action->execute($clientOrderId);

        $this->assertSame([], $action->calls);

        $this->assertSame(0, Order::query()
            ->where('client_order_id', $clientOrderId)
            ->whereNotNull('client_order_finalized_at')
            ->count());
    }
}
