<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * Построение выборки товаров для Excel-выгрузки.
 * Скоупинг по магазину пользователя — Product::scopeShop, фильтры и
 * сортировка — те же scope'ы, что у admin_products_paginate, чтобы
 * выгрузка «Выгрузить все» совпадала с тем, что видно на экране «Товары».
 */
class ProductExportQuery
{
    private const LIMIT = 10000;

    /** Ключи фильтров, которые обрабатывает ProductFilter (scope filter). */
    private const FILTER_KEYS = ['title', 'categories', 'is_culinary', 'is_new', 'is_hurry_up'];

    /**
     * Только колонки, нужные листу выгрузки: без description/meta_* и прочих
     * текстов 10k строк не раздувают память php-fpm.
     */
    private const COLUMNS = [
        'products.id',
        'products.shop_id',
        'products.articul',
        'products.barcode',
        'products.barcodes',
        'products.code',
        'products.title',
        'products.effective_stock_mode',
        'products.effective_fixed_stock',
        // Единицы остатка и фикс-лимита (колонки «Ед. остатка», «Ед. фикс. остатков»)
        'products.base_unit_type_id',
        'products.info_weight_type_id',
        'products.fill_percent',
        'products.status_id',
        'products.created_at',
    ];

    public function build(array $input): Builder
    {
        $query = Product::query()->with('categories');

        $this->applyShopScope($query);

        if (array_key_exists('product_ids', $input)) {
            // Выгрузка выделенных строк: фильтры экрана намеренно не применяются
            $query->whereIn('id', $input['product_ids']);
        } else {
            $this->applyScreenFilters($query, $input);
        }

        $ordering = $input['ordering'] ?? null;

        if (is_array($ordering) && $ordering !== []) {
            $query->sorter(['ordering' => $ordering]);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        // Строго после sorter(): QuerySorter::apply() делает select('products.*'),
        // а Query\Builder::select() заменяет список колонок целиком
        return $query->select(self::COLUMNS)->limit(self::LIMIT);
    }

    /**
     * Скоупинг по магазину через Product::scopeShop(), но без его дыры:
     * у не-глобального пользователя без привязки в user_shops scopeShop()
     * даёт where shop_id IS NULL — товары без владельца. Для выгрузки
     * (как и OrderExportQuery в purchase_back) это пустая выборка.
     */
    private function applyShopScope(Builder $query): void
    {
        $user = auth()->guard('api')->user();

        if (!$user->isGlobalScope() && !$user->shops()->first()) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->shop();
    }

    /**
     * Зеркало аргументов admin_products_paginate: @eq-поля, @scope-поля
     * и ProductFilter. Пустые значения (null/'') не фильтруют — так же,
     * как отсутствующий аргумент в GraphQL.
     */
    private function applyScreenFilters(Builder $query, array $input): void
    {
        $filterArgs = array_intersect_key($input, array_flip(self::FILTER_KEYS));
        $filterArgs = array_filter($filterArgs, static fn ($value) => $value !== null && $value !== '' && $value !== []);

        if ($filterArgs !== []) {
            $query->filter($filterArgs);
        }

        foreach (['shop_id', 'status_id', 'is_configurable'] as $eqField) {
            if (isset($input[$eqField]) && $input[$eqField] !== '') {
                $query->where($eqField, $input[$eqField]);
            }
        }

        if (isset($input['available_in_shop_id'])) {
            $query->availableInShop((int) $input['available_in_shop_id']);
        }

        if (!empty($input['exclude_children'])) {
            $query->excludeChildren();
        }

        if (!empty($input['only_children'])) {
            $query->onlyChildren();
        }

        if (isset($input['has_img'])) {
            $query->hasImg((bool) $input['has_img']);
        }

        if (isset($input['has_reviews'])) {
            $query->hasReviews((bool) $input['has_reviews']);
        }

        if (isset($input['fill_percent_lt'])) {
            $query->fillPercentLt((int) $input['fill_percent_lt']);
        }

        if (isset($input['fill_percent_gte'])) {
            $query->fillPercentGte((int) $input['fill_percent_gte']);
        }
    }
}
