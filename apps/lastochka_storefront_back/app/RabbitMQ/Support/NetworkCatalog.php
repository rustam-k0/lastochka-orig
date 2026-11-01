<?php

declare(strict_types=1);

namespace App\RabbitMQ\Support;

use App\Models\Store;
use App\Services\Settings\ModuleConfigService;

/**
 * Утилиты сетевого каталога для RabbitMQ-слоя storefront.
 */
class NetworkCatalog
{
    public static function enabled(): bool
    {
        return app(ModuleConfigService::class)->isNetworkCatalogEnabled();
    }

    /**
     * Якорный store — владелец глобальных карточек (UNIQUE(store_id, sku)).
     * Детерминированно: минимальный id. null, если stores ещё не синканы —
     * карточка тогда ретраится (RuntimeException в fromEvent), пока
     * shop.created события не создадут магазины.
     */
    public static function anchorStoreId(): ?int
    {
        $id = Store::query()->min('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Синхронизировать флаг global_scope сущности products с состоянием модуля:
     * карточка сети глобальна — дедуп по (source, external_id) без shop_code
     * (механизм global_scope пакета, как у categories).
     *
     * Пишем БЕЗУСЛОВНО, и true, и false: иначе выключение модуля не снимало бы
     * global_scope с запечённого `config:cache`, и «флаг off» переставал быть
     * байт-в-байт легаси.
     */
    public static function applyGlobalScopeConfig(): void
    {
        config(['rabbitmq-events.entities.products.global_scope' => self::enabled()]);
    }
}
