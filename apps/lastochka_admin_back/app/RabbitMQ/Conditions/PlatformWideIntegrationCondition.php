<?php

namespace App\RabbitMQ\Conditions;

use Comrades\RabbitMQEvents\Contracts\PublishCondition;
use Illuminate\Database\Eloquent\Model;

/**
 * Publish rules для IntegrationConfig:
 *
 * 1. Platform-wide (shop_id=null) — публикуем всегда. Таких конфигов
 *    по одному на integration_type (SMS, payment, client_app и т.п.),
 *    storefront_back обрабатывает их как глобальные настройки.
 *
 * 2. Per-shop 1С (integration_type='1c') — публикуем тоже: 1c_parser
 *    хранит свою копию IntegrationConfig per-shop (credentials 1С +
 *    флаг sync_categories_enabled для гейтинга category-events).
 *    storefront_back 1С-конфиг не использует и не имеет колонки
 *    shop_id — прилетевшее событие создаст безобидную запись
 *    integration_type='1c' без shop_id.
 *
 * 3. Per-shop любых других типов (SMS-per-shop и т.п.) — не публикуем:
 *    storefront ожидает один конфиг на integration_type и events от
 *    разных магазинов конфликтовали бы за одну строку.
 */
class PlatformWideIntegrationCondition implements PublishCondition
{
    public function __invoke(Model $config): bool
    {
        if ($config->shop_id === null) {
            return true;
        }

        return $config->integration_type === '1c';
    }
}
