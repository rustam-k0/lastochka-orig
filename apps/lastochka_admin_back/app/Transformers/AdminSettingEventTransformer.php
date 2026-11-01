<?php

namespace App\Transformers;

use App\Models\AdminSetting;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Outbound-трансформер для таблицы admin_settings.
 *
 * Публикует событие `admin_setting.updated` (или `.created`) в RabbitMQ,
 * когда администратор изменяет настройку через мутацию SaveAdminSetting
 * или SaveProjectModules. Storefront_back (и другие consumers) подхватывает
 * событие и обновляет локальную проекцию настройки — это позволяет использовать
 * настройку как feature flag (например, marketplace_mode) и менять её из
 * одной точки входа.
 *
 * admin_back — только publisher. fromEvent() здесь как заглушка по контракту
 * EventTransformer (см. MerchantSaleEventTransformer — тот же паттерн).
 *
 * IMPORTANT: event_timestamp = now() (а не $model->updated_at). Это критично
 * для Lost Updates protection в EventConsumer — см. rabbitmq-pitfalls.md, п.9.
 */
class AdminSettingEventTransformer implements EventTransformer
{
    use ValidatesEventData;
    use HasEventHandlerHooks;

    protected array $requiredFields = ['event_id', 'id', 'source', 'key'];

    /**
     * @param AdminSetting $setting
     */
    public function toEvent(Model $setting, ?object $relatedModel = null, ?string $eventId = null): array
    {
        $event = [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            // now() — не $setting->updated_at. См. rabbitmq-pitfalls.md п.9.
            'event_timestamp' => now()->toIso8601String(),
            'id' => (string) $setting->id,
            'source' => config('rabbitmq-events.source'),
            'key' => $setting->key,
            'value' => $setting->value,
            'type' => $setting->type,
            'created_at' => $setting->created_at?->toIso8601String(),
            'updated_at' => $setting->updated_at?->toIso8601String(),
        ];

        // Файловые настройки: value — относительный путь admin-сервера,
        // снаружи не резолвится. Consumers скачивают файл по file_url.
        if ($setting->type === 'file' && $setting->value) {
            $event['file_url'] = str_starts_with($setting->value, 'http')
                ? $setting->value
                : rtrim(config('app.url'), '/') . $setting->value;
        }

        return $event;
    }

    /**
     * admin_back только публикует admin_settings — входящих событий нет.
     * Метод остаётся как формальная реализация контракта EventTransformer
     * (см. MerchantSaleEventTransformer, IntegrationConfigEventTransformer).
     */
    public function fromEvent(array $data): array
    {
        return [
            'key' => $data['key'] ?? '',
            'value' => $data['value'] ?? null,
            'type' => $data['type'] ?? 'string',
        ];
    }
}
