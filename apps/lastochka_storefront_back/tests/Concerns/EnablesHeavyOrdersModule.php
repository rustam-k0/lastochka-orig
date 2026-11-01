<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\StorefrontSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Включение feature toggle модуля `heavy_orders` (project_modules) в тестах.
 *
 * Флаг «Тяжёлые заказы» управляет расчётом is_heavy при чекауте и в событии
 * order.created. Трейт переиспользуется задачами T5/T6 (расчёт при заказе,
 * isHeavy в ресурсах корзины).
 */
trait EnablesHeavyOrdersModule
{
    protected function enableHeavyOrdersModule(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => 'project_modules'],
            [
                'value' => json_encode(['heavy_orders' => ['label' => 'Тяжёлые заказы', 'enabled' => true]]),
                'type' => 'json',
            ]
        );

        Cache::flush();
    }
}
