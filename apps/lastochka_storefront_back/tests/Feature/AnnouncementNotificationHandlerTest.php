<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserNotification;
use App\RabbitMQ\Handlers\AnnouncementNotificationHandler;
use App\Services\FirebaseNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AnnouncementNotificationHandlerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function eventData(array $overrides = []): array
    {
        return [
            'data' => array_merge([
                'announcement_id' => 7,
                'target' => 'ALL_USERS',
                'announcement_data' => [
                    'title' => 'Мясная акция и скидки!',
                    'short_description' => 'Раздел мясо, зайди и выбери своё!',
                    'description' => 'Полное описание акции',
                    'image_url' => null,
                    'promocode' => ['code' => 'LASTOCHKA', 'discount_percent' => 10],
                ],
            ], $overrides),
            'metadata' => [
                'routing_key' => 'announcement.notification.notification_sent',
            ],
        ];
    }

    private function mockFirebase(): void
    {
        $firebase = \Mockery::mock(FirebaseNotificationService::class);
        $firebase->shouldReceive('sendToTopic')
            ->andReturn(['success' => 1, 'failure' => 0, 'topic' => 'announcements']);
        $this->app->instance(FirebaseNotificationService::class, $firebase);
    }

    /**
     * Регрессия LASTOCHKI-JAMI-11020: push доставляется синхронно, а запись
     * уведомления раньше создавалась отдельным отложенным job'ом. Когда
     * queue-воркер отставал, push приходил, а в списке уведомлений было пусто.
     * Запись обязана появляться в момент обработки события, без отдельного воркера.
     */
    public function test_handler_persists_notifications_synchronously_without_deferred_queue(): void
    {
        // Имитируем прод: отложенная очередь (database), а не sync. С обычным
        // dispatch() job лёг бы в таблицу jobs и ждал воркера, и записи бы не было,
        // пока воркер не разгребёт очередь — ровно как 5 июня. dispatchSync обязан
        // выполнить вставку немедленно, в процессе консьюмера.
        config(['queue.default' => 'database']);
        $this->mockFirebase();

        $users = User::factory()->count(3)->create(['promo_enabled' => true]);

        $result = app(AnnouncementNotificationHandler::class)->handle($this->eventData());

        $this->assertSame('ack', $result);

        // Записи должны существовать сразу после обработки события —
        // не дожидаясь, пока отдельный queue-воркер разгребёт очередь.
        $this->assertSame(3, UserNotification::query()->where('type', 'promo')->count());

        foreach ($users as $user) {
            $this->assertDatabaseHas('user_notifications', [
                'user_id' => $user->id,
                'announcement_id' => 7,
                'type' => 'promo',
            ]);
        }

        // Сохранение уведомлений не должно остаться висеть в отложенной очереди —
        // иначе push снова обгонит запись. Проверяем именно этот job: рассылка
        // email (SendAnnouncementEmails) в очереди лежать может и должна — она не
        // участвует в доставке in-app записи и обогнать её не способна.
        $this->assertSame(
            0,
            DB::table('jobs')->where('payload', 'like', '%SaveAnnouncementNotifications%')->count(),
            'SaveAnnouncementNotifications не должен откладываться в очередь',
        );
    }

    public function test_handler_acks_and_sends_push_for_notification_sent_event(): void
    {
        $firebase = \Mockery::mock(FirebaseNotificationService::class);
        $firebase->shouldReceive('sendToTopic')
            ->once()
            ->with('announcements', \Mockery::type('array'), \Mockery::type('array'))
            ->andReturn(['success' => 1, 'failure' => 0, 'topic' => 'announcements']);
        $this->app->instance(FirebaseNotificationService::class, $firebase);

        User::factory()->create(['promo_enabled' => true]);

        $result = app(AnnouncementNotificationHandler::class)->handle($this->eventData());

        $this->assertSame('ack', $result);
        $this->assertSame(1, UserNotification::query()->where('type', 'promo')->count());
    }
}
