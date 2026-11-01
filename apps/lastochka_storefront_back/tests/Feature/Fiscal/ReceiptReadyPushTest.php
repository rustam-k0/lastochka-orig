<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Models\Order;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Models\UserNotification;
use App\Jobs\SendReceiptReadyNotification;
use App\Services\EmailNotificationService;
use App\Services\FirebaseNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReceiptReadyPushTest extends TestCase
{
    use RefreshDatabase;

    private function succeededReceipt(User $user): PaymentReceipt
    {
        $order = Order::factory()->create(['user_id' => $user->id]);

        return PaymentReceipt::create([
            'order_id' => $order->id,
            'provider' => 'atol',
            'external_id' => 'U1',
            'type' => 'payment',
            'status' => 'succeeded',
            'amount' => 100,
            'items' => [],
            'raw' => [],
        ]);
    }

    public function test_push_sent_once_even_if_dispatched_twice(): void
    {
        $user = User::factory()->create(['push_enabled' => true, 'orders_enabled' => true]);
        $receipt = $this->succeededReceipt($user);

        $fake = \Mockery::mock(FirebaseNotificationService::class);
        $fake->shouldReceive('sendToUser')->once()->andReturn(['success' => 1, 'failure' => 0, 'total' => 1]);
        $this->app->instance(FirebaseNotificationService::class, $fake);

        (new SendReceiptReadyNotification($receipt->id))->handle($fake, app(EmailNotificationService::class));
        (new SendReceiptReadyNotification($receipt->id))->handle($fake, app(EmailNotificationService::class)); // второй раз — noop

        $receipt->refresh();
        $this->assertNotNull($receipt->notified_at);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $user->id,
            'deep_link' => "order/{$receipt->order_id}",
        ]);
        $this->assertDatabaseCount('user_notifications', 1);
    }

    public function test_push_not_sent_when_user_cannot_receive_push_but_still_marks_notified(): void
    {
        $user = User::factory()->create(['push_enabled' => false]);
        $receipt = $this->succeededReceipt($user);

        $fake = \Mockery::mock(FirebaseNotificationService::class);
        $fake->shouldReceive('sendToUser')->never();
        $this->app->instance(FirebaseNotificationService::class, $fake);

        (new SendReceiptReadyNotification($receipt->id))->handle($fake, app(EmailNotificationService::class));

        $receipt->refresh();
        $this->assertNotNull($receipt->notified_at);
    }

    public function test_missing_receipt_is_noop(): void
    {
        $fake = \Mockery::mock(FirebaseNotificationService::class);
        $fake->shouldReceive('sendToUser')->never();
        $this->app->instance(FirebaseNotificationService::class, $fake);

        (new SendReceiptReadyNotification(999999))->handle($fake, app(EmailNotificationService::class));

        $this->assertDatabaseCount('user_notifications', 0);
    }

    public function test_null_order_is_guarded(): void
    {
        $user = User::factory()->create(['push_enabled' => true, 'orders_enabled' => true]);
        $receipt = $this->succeededReceipt($user);
        $orderId = $receipt->order_id;

        // Симулируем чек, у которого заказ пропал (удалён/недоступен):
        // временно снимаем FK, чтобы удалить только Order, не каскадя receipt.
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        Order::whereKey($orderId)->delete();
        \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();

        $fake = \Mockery::mock(FirebaseNotificationService::class);
        $fake->shouldReceive('sendToUser')->never();
        $this->app->instance(FirebaseNotificationService::class, $fake);

        (new SendReceiptReadyNotification($receipt->id))->handle($fake, app(EmailNotificationService::class));

        $this->assertDatabaseCount('user_notifications', 0);
    }
}
