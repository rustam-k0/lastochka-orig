<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Enums\ShippingMethod;
use App\Jobs\SaveAnnouncementNotifications;
use App\Jobs\SendOrderStatusNotification;
use App\Mail\UserNotificationMail;
use App\Models\Order;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\FirebaseNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class NotificationGateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * handle() резолвит FirebaseNotificationService через контейнер даже когда
     * push выключен, а Messaging биндится на реальный Kreait Factory с
     * credentials из БД — без подмены тест уходил бы в Firebase.
     */
    private function mockFirebaseSilent(): void
    {
        $firebase = \Mockery::mock(FirebaseNotificationService::class);
        $firebase->shouldReceive('sendToUser')->zeroOrMoreTimes()
            ->andReturn(['success' => 0, 'failure' => 0, 'total' => 0]);
        $this->app->instance(FirebaseNotificationService::class, $firebase);
    }

    // ==================== promo gate (SaveAnnouncementNotifications) ====================

    public function test_promo_gate_skips_users_with_promo_disabled(): void
    {
        $enabled = User::factory()->count(2)->create(['promo_enabled' => true]);
        User::factory()->count(3)->create(['promo_enabled' => false]);

        SaveAnnouncementNotifications::dispatchSync([
            'title' => 'Promo title',
            'short_description' => 'Short',
            'body' => 'Body',
            'image_url' => null,
            'promo_code' => null,
            'announcement_id' => 42,
        ]);

        $this->assertSame(2, UserNotification::query()->count());

        foreach ($enabled as $user) {
            $this->assertDatabaseHas('user_notifications', [
                'user_id' => $user->id,
                'announcement_id' => 42,
            ]);
        }
    }

    // ==================== order gate (SendOrderStatusNotification) ====================

    public function test_order_gate_skips_in_app_and_push_when_orders_disabled(): void
    {
        $user = User::factory()->create([
            'orders_enabled' => false,
            'push_enabled' => true,
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::Pending,
            'shipping_method' => ShippingMethod::Courier,
            'payment_type' => PaymentType::Card,
            'total' => 1000,
        ]);

        $firebase = \Mockery::mock(FirebaseNotificationService::class);
        $firebase->shouldNotReceive('sendToUser');
        $this->app->instance(FirebaseNotificationService::class, $firebase);

        SendOrderStatusNotification::dispatchSync($order->id, OrderStatus::Assembling);

        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $user->id,
            'type' => 'order',
        ]);
    }

    public function test_order_gate_creates_in_app_but_skips_push_when_only_push_disabled(): void
    {
        $user = User::factory()->create([
            'orders_enabled' => true,
            'push_enabled' => false,
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::Pending,
            'shipping_method' => ShippingMethod::Courier,
            'payment_type' => PaymentType::Card,
            'total' => 1000,
        ]);

        $firebase = \Mockery::mock(FirebaseNotificationService::class);
        $firebase->shouldNotReceive('sendToUser');
        $this->app->instance(FirebaseNotificationService::class, $firebase);

        SendOrderStatusNotification::dispatchSync($order->id, OrderStatus::Assembling);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $user->id,
            'type' => 'order',
        ]);
    }

    public function test_order_gate_sends_both_when_everything_enabled(): void
    {
        $user = User::factory()->create([
            'orders_enabled' => true,
            'push_enabled' => true,
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::Pending,
            'shipping_method' => ShippingMethod::Courier,
            'payment_type' => PaymentType::Card,
            'total' => 1000,
        ]);

        $firebase = \Mockery::mock(FirebaseNotificationService::class);
        $firebase->shouldReceive('sendToUser')
            ->once()
            ->andReturn(['success' => 1, 'failure' => 0, 'total' => 1]);
        $this->app->instance(FirebaseNotificationService::class, $firebase);

        SendOrderStatusNotification::dispatchSync($order->id, OrderStatus::Assembling);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $user->id,
            'type' => 'order',
        ]);
    }

    // ==================== email gate (SendOrderStatusNotification) ====================

    /**
     * @return \App\Models\Order
     */
    private function orderForUser(User $user): Order
    {
        return Order::factory()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::Pending,
            'shipping_method' => ShippingMethod::Courier,
            'payment_type' => PaymentType::Card,
            'total' => 1000,
        ]);
    }

    public function test_email_is_sent_when_channel_and_type_enabled(): void
    {
        Mail::fake();
        $this->mockFirebaseSilent();

        $user = User::factory()->create([
            'orders_enabled' => true,
            'push_enabled' => false,
            'email_enabled' => true,
            'email' => 'client@example.com',
        ]);

        SendOrderStatusNotification::dispatchSync($this->orderForUser($user)->id, OrderStatus::Assembling);

        Mail::assertQueued(UserNotificationMail::class);
    }

    public function test_email_is_skipped_when_channel_disabled(): void
    {
        Mail::fake();
        $this->mockFirebaseSilent();

        $user = User::factory()->create([
            'orders_enabled' => true,
            'push_enabled' => false,
            'email_enabled' => false,
            'email' => 'client@example.com',
        ]);

        SendOrderStatusNotification::dispatchSync($this->orderForUser($user)->id, OrderStatus::Assembling);

        Mail::assertNothingQueued();
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $user->id,
            'type' => 'order',
        ]);
    }

    public function test_email_is_skipped_when_user_has_no_address(): void
    {
        Mail::fake();
        $this->mockFirebaseSilent();

        $user = User::factory()->create([
            'orders_enabled' => true,
            'push_enabled' => false,
            'email_enabled' => true,
            'email' => null,
        ]);

        SendOrderStatusNotification::dispatchSync($this->orderForUser($user)->id, OrderStatus::Assembling);

        Mail::assertNothingQueued();
    }

    public function test_email_is_skipped_when_order_type_disabled(): void
    {
        Mail::fake();
        $this->mockFirebaseSilent();

        $user = User::factory()->create([
            'orders_enabled' => false,
            'push_enabled' => false,
            'email_enabled' => true,
            'email' => 'client@example.com',
        ]);

        SendOrderStatusNotification::dispatchSync($this->orderForUser($user)->id, OrderStatus::Assembling);

        Mail::assertNothingQueued();
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $user->id,
            'type' => 'order',
        ]);
    }
}
