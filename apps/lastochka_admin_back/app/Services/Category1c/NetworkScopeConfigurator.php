<?php

declare(strict_types=1);

namespace App\Services\Category1c;

use App\Services\ModuleConfigService;
use Throwable;

/**
 * При включённом сетевом режиме включает global_scope пакета для categories_1c:
 * метадата-идентичность (source, external_id) без shop_code — одна запись
 * справочника на все магазины сети.
 *
 * Вызывается из AppServiceProvider::boot(). Воркеры-консьюмеры долгоживущие:
 * после переключения флага нужен рестарт supervisor-группы *_rabbitmq.
 */
class NetworkScopeConfigurator
{
    /**
     * Полностью безмолвен при любой ошибке: boot() выполняется и в CI под
     * gitlab-runner (composer package:discover), где недоступны и file-cache,
     * и лог-файлы www-data — там даже Log::warning из catch роняет artisan.
     * Любой сбой → global_scope остаётся выключенным (безопасный дефолт).
     */
    public static function apply(): void
    {
        try {
            if (ModuleConfigService::getCategory1cNetworkAnchorShopId() !== null) {
                config(['rabbitmq-events.entities.categories_1c.global_scope' => true]);
            }
        } catch (Throwable $e) {
            // Намеренно без логирования — сломанный логгер и есть сценарий сбоя.
        }
    }
}
