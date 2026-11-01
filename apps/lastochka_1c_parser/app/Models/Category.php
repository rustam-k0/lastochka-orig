<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_id',
        'parent_id',
        'name',
        'sort',
        'is_active',
        'synced_at',
        'shop_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'synced_at' => 'datetime',
        'sort' => 'integer',
    ];

    /**
     * Родительская категория
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * Дочерние категории
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * Товары категории
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
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
     * Получить все ID дочерних категорий (рекурсивно)
     */
    public function getAllDescendantIds(): array
    {
        $ids = [];

        foreach ($this->children as $child) {
            $ids[] = $child->id;
            $ids = array_merge($ids, $child->getAllDescendantIds());
        }

        return $ids;
    }

    /**
     * Получить ID категории и всех её потомков по названию
     */
    public static function getExcludedCategoryIds(array $names): array
    {
        $ids = [];

        foreach ($names as $name) {
            $category = static::whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($name) . '%'])->first();

            if ($category) {
                $ids[] = $category->id;
                $ids = array_merge($ids, $category->getAllDescendantIds());
            }
        }

        return array_unique($ids);
    }
}
