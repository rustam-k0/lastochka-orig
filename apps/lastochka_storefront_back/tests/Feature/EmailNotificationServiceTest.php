<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\UserNotificationMail;
use App\Models\User;
use App\Services\EmailNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class EmailNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): EmailNotificationService
    {
        return app(EmailNotificationService::class);
    }

    public function test_queues_mail_for_eligible_user(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'client@example.com',
            'email_enabled' => true,
            'orders_enabled' => true,
        ]);

        $result = $this->service()->sendToUser(
            $user,
            ['title' => 'Заказ собран', 'body' => 'Ваш заказ №7 собран.'],
            ['order_id' => '7'],
        );

        $this->assertSame(1, $result['sent']);
        $this->assertNull($result['skipped']);

        Mail::assertQueued(UserNotificationMail::class, function (UserNotificationMail $mail) use ($user) {
            return $mail->hasTo($user->email)
                && $mail->title === 'Заказ собран';
        });
    }

    public function test_skips_when_channel_disabled(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'client@example.com',
            'email_enabled' => false,
        ]);

        $result = $this->service()->sendToUser($user, ['title' => 'T', 'body' => 'B']);

        $this->assertSame(0, $result['sent']);
        $this->assertSame('email_disabled', $result['skipped']);
        Mail::assertNothingQueued();
    }

    public function test_skips_when_address_missing(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => null,
            'email_enabled' => true,
        ]);

        $result = $this->service()->sendToUser($user, ['title' => 'T', 'body' => 'B']);

        $this->assertSame(0, $result['sent']);
        $this->assertSame('no_email', $result['skipped']);
        Mail::assertNothingQueued();
    }

    public function test_builds_order_url_from_config_template(): void
    {
        Mail::fake();
        config(['notifications.email.order_url_template' => 'https://shop.test/orders/{id}']);

        $user = User::factory()->create([
            'email' => 'client@example.com',
            'email_enabled' => true,
        ]);

        $this->service()->sendToUser($user, ['title' => 'T', 'body' => 'B'], ['order_id' => '42']);

        Mail::assertQueued(UserNotificationMail::class, function (UserNotificationMail $mail) {
            return $mail->orderUrl === 'https://shop.test/orders/42';
        });
    }

    public function test_order_url_is_null_without_template_or_order_id(): void
    {
        Mail::fake();
        config(['notifications.email.order_url_template' => null]);

        $user = User::factory()->create([
            'email' => 'client@example.com',
            'email_enabled' => true,
        ]);

        $this->service()->sendToUser($user, ['title' => 'T', 'body' => 'B'], ['order_id' => '42']);

        Mail::assertQueued(UserNotificationMail::class, fn (UserNotificationMail $mail) => $mail->orderUrl === null);
    }

    /**
     * Mail::fake() не рендерит blade — без этих двух тестов битый шаблон
     * обнаружился бы только на проде.
     */
    public function test_template_renders_with_order_button(): void
    {
        $html = (new UserNotificationMail(
            'Заказ собран',
            'Ваш заказ №7 собран.',
            'https://shop.test/orders/7',
        ))->render();

        $this->assertStringContainsString('Заказ собран', $html);
        $this->assertStringContainsString('Ваш заказ №7 собран.', $html);
        $this->assertStringContainsString('https://shop.test/orders/7', $html);
        $this->assertStringContainsString('Перейти к заказу', $html);
    }

    public function test_template_renders_without_order_button(): void
    {
        $html = (new UserNotificationMail('Скидки недели', 'Подробности акции'))->render();

        $this->assertStringContainsString('Скидки недели', $html);
        $this->assertStringNotContainsString('Перейти к заказу', $html);
    }
}
