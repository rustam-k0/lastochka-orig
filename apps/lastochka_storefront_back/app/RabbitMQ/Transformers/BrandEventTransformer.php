<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

final class BrandEventTransformer extends BaseEventTransformer
{
    /**
     * @return array<string>
     */
    protected function requiredFields(): array
    {
        return ['event_id', 'id', 'source', 'title'];
    }

    /**
     * Не используется — только consume.
     *
     * @return array<string, mixed>
     */
    public function toEvent(Model $brand, ?object $shop = null, ?string $eventId = null): array
    {
        return [];
    }

    /**
     * Преобразование данных события в атрибуты модели Brand.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fromEvent(array $data): array
    {
        $attributes = [
            'source' => $data['source'],
            'title' => $data['title'],
            'is_active' => $data['is_active'] ?? true,
            'position' => $data['position'] ?? 0,
            'logo_path' => '',
        ];

        // Динамические чекбоксы: conditional set — события без ключа
        // не затирают локальные значения.
        if (array_key_exists('custom_checkboxes', $data)) {
            $attributes['custom_checkboxes'] = is_array($data['custom_checkboxes'])
                ? array_map(fn ($v) => (bool) $v, $data['custom_checkboxes'])
                : [];
        }

        // Поля блока бренда: явный null очищает значение,
        // отсутствие ключа — не затирает локальное.
        foreach (['link', 'country_origin', 'country_origin_code', 'country_production', 'country_production_code', 'certificate_name'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        // external_id нужен findExistingEntity(): по нему пакет находит сущность, когда
        // метаданных нет или entity_id в них потерян (иначе удаление оставляет зомби).
        // Conditional set — как у остальных полей: payload без id его не затирает.
        if (isset($data['id'])) {
            $attributes['external_id'] = (string) $data['id'];
        }

        return $attributes;
    }

    /**
     * После создания — скачать логотип и сертификат.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterCreate(Model $brand, array $eventData): void
    {
        $this->processLogo($brand, $eventData);

        if (isset($eventData['certificate_url'])) {
            $this->processCertificate($brand, $eventData);
        }
    }

    /**
     * После обновления — обновить логотип и сертификат, если ключи предоставлены.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterUpdate(Model $brand, array $eventData): void
    {
        if (isset($eventData['logo_image_url'])) {
            $this->processLogo($brand, $eventData);
        }

        // Явный null очищает сертификат, отсутствие ключа — не трогает.
        if (array_key_exists('certificate_url', $eventData)) {
            if ($eventData['certificate_url'] === null) {
                $brand->update(['certificate_path' => null]);
            } else {
                $this->processCertificate($brand, $eventData);
            }
        }
    }

    /**
     * Скачать и сохранить сертификат официального дилера (PDF).
     *
     * @param  array<string, mixed>  $eventData
     */
    private function processCertificate(Brand $brand, array $eventData): void
    {
        $certificateUrl = $eventData['certificate_url'] ?? null;

        if (! $certificateUrl) {
            return;
        }

        try {
            $localPath = $this->downloadImage($certificateUrl, 'brands/certificates', self::LARGE_FILE_DOWNLOAD_TIMEOUT);
            $brand->update(['certificate_path' => $localPath]);

            Log::info('Brand certificate downloaded', [
                'brand_id' => $brand->id,
                'certificate_path' => $localPath,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to download brand certificate', [
                'brand_id' => $brand->id,
                'certificate_url' => $certificateUrl,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Скачать и сохранить логотип бренда.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function processLogo(Brand $brand, array $eventData): void
    {
        $imageUrl = $eventData['logo_image_url'] ?? null;

        if (! $imageUrl) {
            return;
        }

        try {
            $localPath = $this->downloadImage($imageUrl, 'brands');
            $brand->update(['logo_path' => $localPath]);

            Log::info('Brand logo downloaded', [
                'brand_id' => $brand->id,
                'image_path' => $localPath,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to download brand logo', [
                'brand_id' => $brand->id,
                'image_url' => $imageUrl,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Поиск существующего бренда по external_id и source.
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $transformedData
     */
    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        $externalId = $eventData['id'] ?? null;
        $source = $eventData['source'] ?? null;

        if (! $externalId || ! $source) {
            return null;
        }

        return Brand::withTrashed()
            ->where('external_id', $externalId)
            ->where('source', $source)
            ->first();
    }
}
