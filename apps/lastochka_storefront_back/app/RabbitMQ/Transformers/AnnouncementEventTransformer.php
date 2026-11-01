<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\Announcement;
use App\Models\Promocode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

final class AnnouncementEventTransformer extends BaseEventTransformer
{
    /**
     * @return array<string>
     */
    protected function requiredFields(): array
    {
        return ['event_id', 'id', 'source', 'title', 'short_description', 'description'];
    }

    /**
     * Не используется — только consume.
     *
     * @return array<string, mixed>
     */
    public function toEvent(Model $announcement, ?object $relatedModel = null, ?string $eventId = null): array
    {
        return [];
    }

    /**
     * Преобразование данных события в атрибуты модели Announcement.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fromEvent(array $data): array
    {
        return [
            'source' => $data['source'],
            'external_id' => (string) $data['id'],
            'title' => $data['title'],
            'short_description' => $data['short_description'],
            'description' => $data['description'],
            'image_path' => null, // Устанавливается в afterCreate/afterUpdate
            'promocode_id' => null, // Устанавливается в afterCreate/afterUpdate
            'is_active' => $data['is_active'] ?? true,
        ];
    }

    /**
     * После создания — связать промокод и скачать изображение.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterCreate(Model $announcement, array $eventData): void
    {
        $this->linkPromocode($announcement, $eventData);
        $this->processImage($announcement, $eventData);

        Log::info('Announcement created from event', [
            'announcement_id' => $announcement->id,
            'title' => $announcement->title,
            'external_id' => $announcement->external_id,
            'source' => $announcement->source,
        ]);
    }

    /**
     * После обновления — пересвязать промокод и обновить изображение.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterUpdate(Model $announcement, array $eventData): void
    {
        $this->linkPromocode($announcement, $eventData);

        if (isset($eventData['image_url'])) {
            $this->processImage($announcement, $eventData);
        }

        Log::info('Announcement updated from event', [
            'announcement_id' => $announcement->id,
            'title' => $announcement->title,
            'external_id' => $announcement->external_id,
            'source' => $announcement->source,
        ]);
    }

    /**
     * Поиск существующего уведомления по source и external_id.
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $transformedData
     */
    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        // Пакет в delete/orphan-путях зовёт findExistingEntity($eventData, []) —
        // source и external_id берём из самого события
        $source = $transformedData['source'] ?? $eventData['source'] ?? null;
        $externalId = $transformedData['external_id'] ?? (isset($eventData['id']) ? (string) $eventData['id'] : null);

        if (! $source || ! $externalId) {
            return null;
        }

        return Announcement::where('source', $source)
            ->where('external_id', $externalId)
            ->first();
    }

    /**
     * Связать объявление с промокодом.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function linkPromocode(Announcement $announcement, array $eventData): void
    {
        $promocodeId = $eventData['promocode_id'] ?? null;
        $promocodeSource = $eventData['promocode_source'] ?? null;

        if (!$promocodeId || !$promocodeSource) {
            return;
        }

        // Поиск промокода через metadata
        $promocodeEntityId = $this->resolveEntityId(
            'merchant_promocodes',
            $promocodeId,
            $promocodeSource
        );

        if (!$promocodeEntityId) {
            // Fallback: ищем по source и external_id в таблице promocodes
            $promocodeEntityId = Promocode::where('source', $promocodeSource)
                ->where('external_id', $promocodeId)
                ->value('id');
        }

        if ($promocodeEntityId) {
            $announcement->update(['promocode_id' => $promocodeEntityId]);

            Log::info('Announcement linked to promocode', [
                'announcement_id' => $announcement->id,
                'promocode_id' => $promocodeEntityId,
            ]);

            return;
        }

        // Промокод ещё не создан — создать pending reference для будущего разрешения
        $this->createPendingReference(
            $announcement,
            'promocode_id',
            'merchant_promocodes',
            $promocodeSource,
            $promocodeId,
            null // shop_code не нужен для промокодов
        );

        Log::warning('Promocode not found, created pending reference', [
            'announcement_id' => $announcement->id,
            'promocode_external_id' => $promocodeId,
            'promocode_source' => $promocodeSource,
        ]);
    }

    /**
     * Скачать и сохранить изображение объявления.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function processImage(Announcement $announcement, array $eventData): void
    {
        $imageUrl = $eventData['image_url'] ?? null;

        if (!$imageUrl) {
            return;
        }

        try {
            $imagePath = $this->downloadImage($imageUrl, 'announcements');
            $announcement->update(['image_path' => $imagePath]);

            Log::info('Announcement image downloaded', [
                'announcement_id' => $announcement->id,
                'image_path' => $imagePath,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to download announcement image', [
                'announcement_id' => $announcement->id,
                'image_url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }
}