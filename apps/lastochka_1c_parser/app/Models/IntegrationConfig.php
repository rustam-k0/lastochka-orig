<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class IntegrationConfig extends Model
{
    protected $table = 'integration_configs';

    protected $fillable = [
        'integration_type',
        'driver',
        'credentials',
        'is_active',
        'sync_categories_enabled',
        'sync_images_enabled',
        'deactivate_missing_enabled',
        'tested_at',
        'test_error',
        'shop_id',
        'shop_code',
    ];

    protected $casts = [
        'credentials' => 'encrypted:json',
        'is_active' => 'boolean',
        'sync_categories_enabled' => 'boolean',
        'sync_images_enabled' => 'boolean',
        'deactivate_missing_enabled' => 'boolean',
        'tested_at' => 'datetime',
    ];

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('integration_type', $type);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForShop(Builder $query, ?int $shopId): Builder
    {
        return $shopId ? $query->where('shop_id', $shopId) : $query;
    }

    public function scopeForShopCode(Builder $query, string $shopCode): Builder
    {
        return $query->where('shop_code', $shopCode);
    }

    /**
     * Резолв shop_id для входящего заказа по shop_code из конфигов 1С.
     *
     * Колонка orders.shop_id — NOT NULL без дефолта: если не заполнить,
     * INSERT входящего заказа падает с SQLSTATE[HY000] 1364 и заказ не
     * доходит до выгрузки в 1С. Точный матч по shop_code, иначе fallback
     * на первый сконфигурированный магазин 1С.
     */
    public static function resolveShopIdByCode(?string $shopCode): ?int
    {
        if ($shopCode !== null && $shopCode !== '') {
            $shopId = static::ofType('1c')->forShopCode($shopCode)->value('shop_id');

            if ($shopId !== null) {
                return (int) $shopId;
            }
        }

        $fallback = static::ofType('1c')->whereNotNull('shop_id')->value('shop_id');

        return $fallback !== null ? (int) $fallback : null;
    }
}
