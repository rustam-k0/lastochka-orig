<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Address;
use App\Models\Product;
use App\Models\StoreProduct;
use App\Services\Settings\ModuleConfigService;
use App\Services\Store\AvailableStoreService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;

/**
 * Контекст магазина для выдачи каталога в сетевом режиме (network_catalog):
 * какой магазин является источником цены/остатка и как подгрузить его оффер
 * (store_products) к товарам ответа.
 *
 * Один источник правды для всех каталожных поверхностей: карточка, листинги,
 * группы, избранное, акции, секции меню. При выключенном модуле все методы
 * ничего не делают — ручки остаются байт-в-байт легаси.
 */
final class StoreOfferContext
{
    public function __construct(
        private readonly ModuleConfigService $moduleConfig,
        private readonly AvailableStoreService $availableStores,
    ) {}

    /**
     * Магазин, в контексте которого отдаём цену/остаток товара: явный
     * `?storeId=` либо первый доступный магазин активного адреса
     * авторизованного пользователя. 0 — контекста нет.
     *
     * Контекст резолвится ТОЛЬКО при включённом сетевом каталоге: при
     * выключенном флаге ручки обязаны отвечать как раньше, а раньше ни
     * storeId, ни адрес пользователя на них не влияли.
     */
    public function resolveStoreId(Request $request): int
    {
        if (! $this->moduleConfig->isNetworkCatalogEnabled()) {
            return 0;
        }

        $storeId = (int) $request->query('storeId', 0);

        if ($storeId > 0) {
            return $storeId;
        }

        $user = $request->user();

        if ($user === null) {
            return 0;
        }

        $address = Address::query()->forUser($user)->active()->first();

        if ($address === null) {
            return 0;
        }

        return (int) ($this->availableStores
            ->getAvailableStores($address, $user)
            ->first()?->id ?? 0);
    }

    /**
     * Constrained eager load оффера магазина — источник цены/остатка для
     * ресурсов ({@see Product::storeContext()}). Грузим только валидный оффер
     * (активный, с ценой): строка с price=0 (создана stock-событием раньше
     * цены) оффером не считается, ресурс по ней падает на колонки карточки.
     *
     * $storeId = 0 — контекста магазина нет: подставляем лучшее предложение
     * сети ({@see self::BEST_OFFER_ORDER}). Иначе выдача без контекста
     * (похожие/сопутствующие/штрихкод у гостя) уходила бы клиенту с нулевой
     * ценой карточки.
     *
     * Магазин оффера подтягиваем сразу — его читает isStockIgnored().
     *
     * @return array<string, mixed>
     */
    public static function eagerLoad(int $storeId = 0): array
    {
        if (! app(ModuleConfigService::class)->isNetworkCatalogEnabled()) {
            return [];
        }

        if ($storeId > 0) {
            return [
                'storeProducts' => fn ($q) => $q->where('store_id', $storeId)
                    ->where('is_active', true)
                    ->where('price', '>', 0),
                'storeProducts.store',
            ];
        }

        return [
            'storeProducts' => fn ($q) => self::applyBestOfferOrder(
                $q->where('is_active', true)->where('price', '>', 0)
            )->limit(1),
            'storeProducts.store',
        ];
    }

    /**
     * Порядок выбора «лучшего оффера сети»: сначала магазины, где товар
     * реально есть (иначе карточка ушла бы с stockQuantity=0 при остатке
     * в соседнем магазине), потом минимальная цена, потом id магазина —
     * чтобы выбор был детерминированным.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<StoreProduct>  $query
     * @return \Illuminate\Database\Eloquent\Builder<StoreProduct>
     */
    private static function applyBestOfferOrder($query)
    {
        return $query
            ->orderByRaw('(quantity > 0) desc')
            ->orderBy('price')
            ->orderBy('store_id');
    }

    /**
     * Догрузить офферы уже загруженным товарам.
     *
     * Нужно там, где constrained eager load при выборке не подходит: у каждой
     * пачки товаров свой магазин (товарные группы на главной) либо товары уже
     * загружены цепочкой связей (сметы, сравнение).
     *
     * $storeId = 0 — контекста нет, подставляем лучший оффер сети.
     *
     * Реализовано штатным eager-загрузчиком по тем же constraints, что и
     * {@see self::eagerLoad()} — то есть в ветке без контекста лучший оффер
     * выбирает САМ SQL (оконная функция row_number по partition product_id,
     * это `limit(1)` на eager load). Тянуть все офферы всех магазинов и
     * группировать в PHP нельзя: в полной сети это сотни строк на товар,
     * а публичная смета по share-токену всегда идёт без контекста.
     *
     * @param  iterable<mixed>  $products
     */
    public static function attach(iterable $products, int $storeId = 0): void
    {
        $relations = self::eagerLoad($storeId);

        // Пустой массив — модуль выключен, легаси-выдача офферов не знает.
        if ($relations === []) {
            return;
        }

        $items = collect($products)
            ->filter(fn ($product) => $product instanceof Product)
            ->values()
            ->all();

        if ($items === []) {
            return;
        }

        // Коллекция из ТЕХ ЖЕ инстансов: load() проставит связи прямо на них.
        (new EloquentCollection($items))->load($relations);
    }
}
