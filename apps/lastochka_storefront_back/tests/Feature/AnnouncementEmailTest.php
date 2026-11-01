<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SaveAnnouncementNotifications;
use App\Mail\UserNotificationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class AnnouncementEmailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function announcement(): array
    {
        return [
            'title' => 'Скидки недели',
            'short_description' => 'Коротко',
            'body' => 'Подробности акции',
            'image_url' => null,
            'promo_code' => null,
            'announcement_id' => 42,
        ];
    }

    public function test_email_goes_only_to_users_with_all_conditions_met(): void
    {
        Mail::fake();

        $recipient = User::factory()->create([
            'promo_enabled' => true,
            'email_enabled' => true,
            'email' => 'yes@example.com',
        ]);

        // Промо выключено.
        User::factory()->create([
            'promo_enabled' => false,
            'email_enabled' => true,
            'email' => 'promo-off@example.com',
        ]);

        // Email-канал выключен.
        User::factory()->create([
            'promo_enabled' => true,
            'email_enabled' => false,
            'email' => 'channel-off@example.com',
        ]);

        // Адреса нет.
        User::factory()->create([
            'promo_enabled' => true,
            'email_enabled' => true,
            'email' => null,
        ]);

        SaveAnnouncementNotifications::dispatchSync($this->announcement());

        Mail::assertQueuedCount(1);
        Mail::assertQueued(
            UserNotificationMail::class,
            fn (UserNotificationMail $mail) => $mail->hasTo($recipient->email)
        );
    }

    public function test_announcement_email_has_no_order_button(): void
    {
        Mail::fake();
        config(['notifications.email.order_url_template' => 'https://shop.test/orders/{id}']);

        User::factory()->create([
            'promo_enabled' => true,
            'email_enabled' => true,
            'email' => 'yes@example.com',
        ]);

        SaveAnnouncementNotifications::dispatchSync($this->announcement());

        Mail::assertQueued(
            UserNotificationMail::class,
            fn (UserNotificationMail $mail) => $mail->orderUrl === null
        );
    }

    public function test_in_app_notifications_are_still_created(): void
    {
        Mail::fake();

        User::factory()->count(2)->create([
            'promo_enabled' => true,
            'email_enabled' => false,
        ]);

        SaveAnnouncementNotifications::dispatchSync($this->announcement());

        $this->assertDatabaseCount('user_notifications', 2);
    }
}
