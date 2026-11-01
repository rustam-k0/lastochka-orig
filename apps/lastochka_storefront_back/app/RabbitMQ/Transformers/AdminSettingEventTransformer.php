<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\StorefrontSetting;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use App\Services\Images\ImageThumbnails;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class AdminSettingEventTransformer extends BaseEventTransformer
{
    /**
     * @return array<string>
     */
    protected function requiredFields(): array
    {
        return ['event_id', 'source', 'key'];
    }

    /**
     * Storefront не публикует admin_settings обратно — только consume.
     *
     * @return array<string, mixed>
     */
    public function toEvent(Model $model, ?object $shop = null, ?string $eventId = null): array
    {
        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => now()->toIso8601String(),
            'id' => (string) ($model->id ?? ''),
            'source' => config('rabbitmq-events.source'),
            'key' => $model->key ?? null,
            'value' => $model->value,
            'type' => $model->type ?? 'string',
        ];
    }

    /**
     * Нормализовать входящее событие в атрибуты StorefrontSetting.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fromEvent(array $data): array
    {
        $type = $data['type'] ?? 'string';
        $value = $data['value'] ?? null;

        // Файловые настройки: value — относительный путь admin-сервера,
        // снаружи не резолвится. Локальный путь проставляют
        // afterCreate/afterUpdate после скачивания по file_url.
        if ($type === 'file') {
            return [
                'key' => $data['key'],
                'value' => null,
                'type' => $type,
            ];
        }

        // Для JSON значений сохраняем как строку (geter декодирует обратно)
        if ($type === 'json' && is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        } elseif ($value !== null && ! is_scalar($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        } elseif ($value !== null) {
            $value = (string) $value;
        }

        return [
            'key' => $data['key'],
            'value' => $value,
            'type' => $type,
        ];
    }

    /**
     * Поиск существующей настройки по ключу.
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $transformedData
     */
    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        $key = $eventData['key'] ?? null;

        if ($key === null) {
            return null;
        }

        return StorefrontSetting::where('key', $key)->first();
    }

    /**
     * После создания записи — инвалидировать кеш.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterCreate(Model $setting, array $eventData): void
    {
        $this->processFile($setting, $eventData);
        $this->invalidateModuleCache();

        Log::info('AdminSettingEventTransformer: created', [
            'key' => $setting->key ?? null,
        ]);
    }

    /**
     * После обновления записи — инвалидировать кеш.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterUpdate(Model $setting, array $eventData): void
    {
        $this->processFile($setting, $eventData);
        $this->invalidateModuleCache();

        Log::info('AdminSettingEventTransformer: updated', [
            'key' => $setting->key ?? null,
        ]);
    }

    /**
     * Скачать файл файловой настройки к себе (как картинки баннеров):
     * мобилка получает URL storefront-домена и не зависит от admin-сервера.
     * Ошибка скачивания — warning, настройка остаётся без файла (value=null).
     *
     * @param  array<string, mixed>  $eventData
     */
    private function processFile(Model $setting, array $eventData): void
    {
        if (($eventData['type'] ?? null) !== 'file' || empty($eventData['file_url'])) {
            return;
        }

        try {
            $localPath = $this->downloadImage($eventData['file_url'], 'admin-settings');
            $setting->update(['value' => $localPath]);
        } catch (\Exception $e) {
            Log::warning('AdminSettingEventTransformer: failed to download file', [
                'key' => $setting->key ?? null,
                'file_url' => $eventData['file_url'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * После удаления настройки — удалить локальную копию файла.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterDelete(Model $setting, array $eventData): void
    {
        if (($setting->type ?? null) === 'file' && $setting->value) {
            Storage::disk('public')->delete(ImageThumbnails::withThumbnails([(string) $setting->value]));
        }

        $this->invalidateModuleCache();

        Log::info('AdminSettingEventTransformer: deleted', [
            'key' => $setting->key ?? null,
        ]);
    }

    private function invalidateModuleCache(): void
    {
        try {
            app(ModuleConfigService::class)->invalidateCache();
        } catch (\Throwable $e) {
            Log::warning('AdminSettingEventTransformer: failed to invalidate cache', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
