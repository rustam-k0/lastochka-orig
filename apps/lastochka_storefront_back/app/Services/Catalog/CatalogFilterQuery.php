<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Product;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Применение фильтров каталога к запросу товаров.
 *
 * Один источник правды для листинга категории, поиска в магазине и подсчёта
 * фасетов экрана «Фильтр»: счётчики значений и кнопка «Показать N товаров»
 * обязаны считаться одинаково, иначе значение с count=3 приводит к пустой
 * выдаче.
 *
 * $networkStoreId — магазин, чей оффер (store_products) уже присоединён к
 * запросу join'ом ({@see Product::scopeJoinStoreOffer()}): в сетевом каталоге
 * цена и старая цена живут в оффере. null — легаси-режим, колонки карточки.
 */
final class CatalogFilterQuery
{
    public function __construct(
        private readonly ModuleConfigService $moduleConfig,
    ) {}

    public static function priceColumn(?int $networkStoreId): string
    {
        return $networkStoreId !== null ? 'store_products.price' : 'products.price';
    }

    /**
     * Переключатели выдачи «только со скидкой» и «без сахара».
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function applyToggles(Builder $query, bool $onlyDiscounted, bool $sugarFree, ?int $networkStoreId): Builder
    {
        if ($onlyDiscounted) {
            $this->applyDiscounted($query, $networkStoreId);
        }

        if ($sugarFree) {
            $query->sugarFree();
        }

        return $query;
    }

    /**
     * Выбор пользователя на экране «Фильтр».
     *
     * $except — группа, собственный выбор которой не применяется: код атрибута,
     * код флага или {@see CatalogFilterCriteria::PRICE}. Так считаются фасеты:
     * доступность значений группы зависит от выбора в остальных группах, но не
     * от выбора в ней самой (внутри группы OR — выбранное соседнее значение не
     * должно делать остальные недоступными).
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function applyCriteria(
        Builder $query,
        CatalogFilterCriteria $criteria,
        ?int $networkStoreId,
        ?string $except = null,
    ): Builder {
        if ($except !== CatalogFilterCriteria::PRICE) {
            $price = self::priceColumn($networkStoreId);

            if ($criteria->priceMin !== null) {
                $query->where($price, '>=', $criteria->priceMin);
            }

            if ($criteria->priceMax !== null) {
                $query->where($price, '<=', $criteria->priceMax);
            }
        }

        foreach ($criteria->flags as $flag) {
            if ($flag !== $except) {
                $query->where("products.custom_checkboxes->{$flag}", true);
            }
        }

        foreach ($criteria->attributes as $code => $values) {
            if ($code === $except) {
                continue;
            }

            // Часть значений синхронизирована из 1С без attribute_value_code
            // (только attribute_value_id) — фасеты в таком случае отдают клиенту
            // id как code (строкой), поэтому здесь симметрично матчим и по code,
            // и по id, иначе такие значения невозможно выбрать в фильтре вообще
            // (whereIn по code с null никогда не совпадёт).
            $query->whereHas('attributes', function ($q) use ($code, $values) {
                $q->where('code', $code)
                    ->whereNotNull('attribute_value_id')
                    ->where(function ($sub) use ($values) {
                        $sub->whereIn('attribute_value_code', $values)
                            ->orWhereIn('attribute_value_id', $values);
                    });
            });
        }

        return $query;
    }

    /**
     * Фильтр «только со скидкой»: старая цена есть либо действует акция.
     *
     * Источник старой цены — оффер магазина контекста (присоединённый
     * store_products). Без контекста в сетевом режиме скидка ищется по любому
     * валидному офферу товара: колонка products.price_old в сети всегда пуста.
     *
     * @param  Builder<Product>  $query
     */
    private function applyDiscounted(Builder $query, ?int $networkStoreId): void
    {
        $networkWithoutContext = $networkStoreId === null
            && $this->moduleConfig->isNetworkCatalogEnabled();

        $query->where(function ($q) use ($networkStoreId, $networkWithoutContext) {
            if ($networkWithoutContext) {
                $q->whereHas('storeProducts', fn ($sq) => $sq->where('is_active', true)
                    ->where('price', '>', 0)
                    ->whereNotNull('price_old'))
                    ->orWhereHas('activePromotions');

                return;
            }

            $priceOldColumn = $networkStoreId !== null ? 'store_products.price_old' : 'products.price_old';

            $q->whereNotNull($priceOldColumn)
                ->orWhereHas('activePromotions');
        });
    }
}
