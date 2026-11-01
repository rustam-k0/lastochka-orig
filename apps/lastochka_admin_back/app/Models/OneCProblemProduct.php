<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Проблемный товар 1С (без обязательного реквизита) — локальный снимок
 * диагностики обмена для страницы «Проблемные товары 1С» (FOODLINK-11039).
 *
 * code: missing_name | missing_sku | missing_barcode | missing_unit |
 *       missing_image | missing_price | missing_stock
 */
class OneCProblemProduct extends Model
{
    protected $table = 'onec_problem_products';

    protected $fillable = [
        'shop_id',
        'code',
        'external_id',
        'sku',
        'name',
        'barcode',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** Название магазина для колонки на единой странице «Карантин товаров» (FOODLINK-11039). */
    public function getShopTitleAttribute(): ?string
    {
        return $this->shop?->title;
    }

    /**
     * id товара курьерки для перехода на карточку из карантина (FOODLINK-11458).
     * Резолв по уникальному ключу products(shop_id, articul=sku); null — нет
     * sku (missing_sku/missing_name) либо товара в курьерке не существует.
     */
    public function getLocalProductIdAttribute(): ?int
    {
        if (empty($this->sku)) {
            return null;
        }

        return Product::where('shop_id', $this->shop_id)
            ->where('articul', $this->sku)
            ->value('id');
    }

    /**
     * Фильтр по магазину; null — без фильтра (кросс-магазинный список для
     * единой страницы карантина). @scope(name: "filterByShop").
     */
    public function scopeFilterByShop(Builder $query, ?int $shopId): Builder
    {
        if ($shopId === null) {
            return $query;
        }

        return $query->where('shop_id', $shopId);
    }
}
