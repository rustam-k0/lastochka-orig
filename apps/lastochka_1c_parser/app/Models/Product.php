<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'external_id',
        'category_id',
        'name',
        'sku',
        'code',
        'plu',
        'barcode',
        'barcodes',
        'description',
        'attributes',
        'is_active',
        'synced_at',
        'shop_id',
    ];

    protected $casts = [
        'attributes' => 'array',
        'barcodes' => 'array',
        'is_active' => 'boolean',
        'synced_at' => 'datetime',
    ];

    /**
     * Категория товара
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Цены товара
     */
    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }

    /**
     * Ступени «цены от количества» (MID-308) по всем типам цен, по возрастанию порога
     */
    public function priceTiers(): HasMany
    {
        return $this->hasMany(PriceTier::class)->orderBy('min_quantity');
    }

    /**
     * Остатки на складах
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    /**
     * Изображения товара
     */
    public function images(): HasMany
    {
        return $this->hasMany(Image::class)->orderBy('sort');
    }

    /**
     * Главное изображение
     */
    public function mainImage(): HasOne
    {
        return $this->hasOne(Image::class)->where('is_main', true);
    }

    /**
     * Розничная цена (по ID типа из конфига или первая доступная)
     */
    public function retailPrice(): HasOne
    {
        $priceTypeId = config('export.retail_price_type_id');
        
        if ($priceTypeId) {
            return $this->hasOne(Price::class)->where('price_type', $priceTypeId);
        }
        
        // Если не задан — берём первую цену
        return $this->hasOne(Price::class);
    }

    /**
     * Общий остаток (по всем складам)
     */
    public function getTotalStockAttribute(): float
    {
        return $this->stocks->sum('quantity');
    }

    /**
     * Scope: по магазину
     */
    public function scopeForShop($query, int $shopId)
    {
        return $query->where('shop_id', $shopId);
    }

    /**
     * Найти по external_id из 1С
     */
    public static function findByExternalId(string $externalId, ?int $shopId = null): ?self
    {
        $query = static::where('external_id', $externalId);
        if ($shopId !== null) {
            $query->where('shop_id', $shopId);
        }
        return $query->first();
    }

    /**
     * Scope: только активные
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: в наличии
     */
    public function scopeInStock($query)
    {
        return $query->whereHas('stocks', function ($q) {
            $q->where('quantity', '>', 0);
        });
    }
}
