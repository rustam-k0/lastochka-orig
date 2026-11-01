<?php

declare(strict_types=1);

namespace App\RabbitMQ\Handlers;

use App\Jobs\SaveAnnouncementNotifications;
use App\Services\FirebaseNotificationService;
use Comrades\RabbitMQEvents\Exceptions\InvalidEventException;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnnouncementNotificationHandler
{
    /**
     * Обрабатывает событие уведомления об объявлении/акции.
     *
     * @param  array<string, mixed>  $eventData
     *
     * @throws InvalidEventException
     */
    public function handle(array $eventData): string
    {
        $this->validate($eventData);

        $routingKey = $eventData['metadata']['routing_key'] ?? '';
        $data = $eventData['data'] ?? $eventData;

        Log::info('Processing announcement notification event', [
            'routing_key' => $routingKey,
            'announcement_id' => $data['announcement_id'] ?? null,
            'target' => $data['target'] ?? null,
        ]);

        return match (true) {
            str_contains($routingKey, 'notification_sent') => $this->handleNotificationSent($data),
            default => $this->handleUnknownEvent($routingKey),
        };
    }

    /**
     * Обрабатывает отправку push-уведомления об акции.
     *
     * @param  array<string, mixed>  $data
     */
    private function handleNotificationSent(array $data): string
    {
        $announcementData = $data['announcement_data'] ?? [];

        $notificationPayload = [
            'title' => $announcementData['title'] ?? 'Новая акция!',
            'body' => $announcementData['short_description'] ?? 'У нас есть отличная новость для вас!',
        ];

        $body = $announcementData['description'] ?? $notificationPayload['body'];
        $promoCode = $announcementData['promocode'] ?? null;

        // 1) БД-уведомления для пользователей — основная гарантия доставки.
        // Сохраняем СИНХРОННО (dispatchSync) ДО отправки push, прямо в процессе
        // RabbitMQ-консьюмера. Иначе push (он уходит синхронно ниже) обгоняет
        // отложенный job: при простое/отставании queue-воркера пользователь
        // получает push, а в списке уведомлений ещё пусто — баг LASTOCHKI-JAMI-11020
        // (5 июня воркер отставал на час, push'и дошли, записи появились спустя час).
        // Привязав запись к тому же процессу, что доставляет push, мы гарантируем
        // инвариант: push не приходит раньше записи.
        try {
            SaveAnnouncementNotifications::dispatchSync([
                'title' => $notificationPayload['title'],
                'short_description' => $notificationPayload['body'],
                'body' => is_array($body) ? json_encode($body) : (string) $body,
                'image_url' => $announcementData['image_url'] ?? null,
                'promo_code' => is_array($promoCode) ? ($promoCode['code'] ?? json_encode($promoCode)) : $promoCode,
                'announcement_id' => $data['announcement_id'] ?? null,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to dispatch SaveAnnouncementNotifications job', [
                'error' => $e->getMessage(),
                'announcement_id' => $data['announcement_id'] ?? null,
            ]);

            // Если даже job не удалось поставить — это серьёзно, поднимаем nack.
            return 'nack';
        }

        // 2) Push через FCM topic — best-effort. Резолвим сервис лениво,
        // чтобы отсутствующий/ещё не подгруженный Firebase Project ID
        // не валил обработку всего события.
        try {
            $dataPayload = [
                'type' => 'announcement',
                'action' => 'open_announcement',
                'announcement_id' => (string) ($data['announcement_id'] ?? ''),
            ];

            if (! empty($announcementData['image_url'])) {
                $dataPayload['image_url'] = $announcementData['image_url'];
            }

            if (! empty($announcementData['promocode'])) {
                $promocode = $announcementData['promocode'];
                $dataPayload['promocode'] = is_array($promocode) ? ($promocode['code'] ?? json_encode($promocode)) : (string) $promocode;
            }

            $firebase = app(FirebaseNotificationService::class);
            $sendResult = $firebase->sendToTopic('announcements', $notificationPayload, $dataPayload);

            Log::info('Announcement notification sent successfully', [
                'topic' => 'announcements',
                'send_result' => $sendResult,
                'announcement_id' => $data['announcement_id'] ?? null,
                'title' => $notificationPayload['title'],
            ]);
        } catch (Throwable $e) {
            Log::warning('Announcement FCM push skipped (Firebase unavailable)', [
                'error' => $e->getMessage(),
                'announcement_id' => $data['announcement_id'] ?? null,
            ]);
        }

        return 'ack';
    }

    private function handleUnknownEvent(string $routingKey): string
    {
        Log::warning('Unknown announcement notification event', [
            'routing_key' => $routingKey,
        ]);

        return 'ack';
    }

    /**
     * Валидирует структуру события.
     *
     * @param  array<string, mixed>  $eventData
     *
     * @throws InvalidEventException
     */
    private function validate(array $eventData): void
    {
        $data = $eventData['data'] ?? $eventData;

        if (! isset($data['announcement_id'])) {
            throw new InvalidEventException('Missing required field: announcement_id');
        }

        if (empty($data['announcement_data'])) {
            throw new InvalidEventException('Missing required field: announcement_data');
        }

        $announcementData = $data['announcement_data'];
        if (empty($announcementData['title'])) {
            throw new InvalidEventException('Missing required field: announcement_data.title');
        }
    }
}
