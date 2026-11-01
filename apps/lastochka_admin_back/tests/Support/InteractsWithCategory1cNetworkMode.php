<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\AdminSetting;
use App\Services\ModuleConfigService;

/**
 * Включение/выключение сетевого режима категорий 1С в тестах.
 * После каждой записи настройки сбрасывает кеш ModuleConfigService.
 *
 * tearDown обязателен: кеш (array driver) живёт весь процесс PHPUnit, а
 * DatabaseTransactions откатывает только БД — без сброса включённый режим
 * «протёк» бы в следующие тесты (включая тесты без этого трейта, создающие
 * Shop → ShopObserver увидел бы устаревший якорь).
 */
trait InteractsWithCategory1cNetworkMode
{
    protected function tearDown(): void
    {
        ModuleConfigService::invalidateCache();
        parent::tearDown();
    }

    protected function enableCategory1cNetworkMode(int $anchorShopId): void
    {
        $this->writeCategory1cNetworkModule([
            'label' => 'Сетевой маппинг категорий 1С',
            'enabled' => true,
            'anchor_shop_id' => $anchorShopId,
        ]);
    }

    protected function disableCategory1cNetworkMode(): void
    {
        $this->writeCategory1cNetworkModule([
            'label' => 'Сетевой маппинг категорий 1С',
            'enabled' => false,
        ]);
    }

    protected function writeCategory1cNetworkModule(?array $module): void
    {
        $config = $module === null
            ? []
            : [ModuleConfigService::CATEGORY_1C_NETWORK_KEY => $module];

        AdminSetting::setValue(
            ModuleConfigService::PROJECT_MODULES_KEY,
            json_encode($config),
            'json'
        );
        ModuleConfigService::invalidateCache();
    }
}
