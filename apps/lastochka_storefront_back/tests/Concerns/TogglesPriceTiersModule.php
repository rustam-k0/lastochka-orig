<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\StorefrontSetting;
use App\RabbitMQ\Support\NetworkCatalog;
use App\Services\Pricing\PriceTierLadder;
use App\Services\Pricing\ProductTierLadderProvider;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Модуль «Цены от количества» (price_tiers, MID-308) в тестах: переключение
 * флага (вместе с сетевым каталогом, они лежат в одном блобе project_modules),
 * посев лесенки товара в магазине и страж «при выключенном флаге к таблице
 * ступеней не обращаемся».
 */
trait TogglesPriceTiersModule
{
    protected function setPriceTiersModule(bool $enabled, bool $networkCatalog = false): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'price_tiers' => ['label' => 'Цены от количества', 'enabled' => $enabled],
                    'network_catalog' => ['label' => 'Сетевой каталог', 'enabled' => $networkCatalog],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );

        // ModuleConfigService: общий кеш плюс memo singleton-экземпляра.
        Cache::flush();
        app(ModuleConfigService::class)->invalidateCache();
        NetworkCatalog::applyGlobalScopeConfig();
        app(ProductTierLadderProvider::class)->flush();
    }

    /**
     * Лесенка товара в магазине, в базовых единицах товара (как в событии цены).
     *
     * @param  array<int, array{min_quantity: int|float, price: int|float}>  $tiers
     */
    protected function seedPriceTiers(Product $product, int $storeId, array $tiers): void
    {
        ProductPriceTier::replaceLadder((int) $product->id, $storeId, PriceTierLadder::normalize($tiers) ?? []);

        // Scoped-экземпляр живёт весь тест: сбрасываем кеш лесенок после записи.
        app(ProductTierLadderProvider::class)->flush();
    }

    /**
     * Сколько запросов (чтение и запись) ушло к product_price_tiers за время
     * $callback. Контракт: при выключенном флаге их ноль.
     */
    protected function priceTierTableQueries(callable $callback): int
    {
        return $this->tableQueries('product_price_tiers', $callback);
    }

    /**
     * Сколько запросов (чтение и запись) ушло к произвольной таблице (по
     * подстроке в SQL) за время $callback. Обобщение priceTierTableQueries()
     * для стража других N+1 (например store_products при requote() в
     * сетевом каталоге, MID-308).
     */
    protected function tableQueries(string $table, callable $callback): int
    {
        $count = 0;
        $active = true;

        DB::listen(function ($query) use (&$count, &$active, $table): void {
            if ($active && str_contains($query->sql, $table)) {
                $count++;
            }
        });

        try {
            $callback();
        } finally {
            $active = false;
        }

        return $count;
    }
}
