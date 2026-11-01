<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Brand extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'source',
        'external_id',
        'title',
        'link',
        'country_origin',
        'country_origin_code',
        'country_production',
        'country_production_code',
        'certificate_path',
        'certificate_name',
        'logo_path',
        'position',
        'is_active',
        'custom_checkboxes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'custom_checkboxes' => 'array',
        ];
    }

    /**
     * Активные бренды.
     */
    public function scopeAvailable($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Товары бренда.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Средний рейтинг активных товаров бренда (1 знак после запятой).
     * Использует предзагруженный агрегат withAvg, если он есть,
     * иначе выполняет отдельный запрос.
     */
    public function productsRatingAvg(): ?float
    {
        $avg = array_key_exists('products_rating_avg', $this->getAttributes())
            ? $this->getAttribute('products_rating_avg')
            : $this->products()->where('is_active', true)->avg('rating');

        return $avg === null ? null : round((float) $avg, 1);
    }
}
