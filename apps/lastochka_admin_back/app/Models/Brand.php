<?php

namespace App\Models;

use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin IdeHelperBrand
 */
class Brand extends Model
{
    use ModelTools, HasImage, SoftDeletes;

    const table = "brands";
    protected $table = self::table;

    protected $fillable = [
        'title',
        'link',
        'country_origin',
        'country_origin_code',
        'country_production',
        'country_production_code',
        'certificate_path',
        'certificate_name',
        'position',
        'is_active',
        'custom_checkboxes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'position' => 'integer',
    ];

    protected $attributes = [
        'position' => 0,
        'is_active' => true,
    ];

    /**
     * Получение логотипа бренда
     */
    public function logoImage(): HasOneThrough
    {
        return $this->hasOneThrough(
            Img::class,
            JoinImg::class,
            'item_id',
            'id',
            'id',
            'img_id'
        )->where([
            ['head_id', Status::CODE_BRAND_LOGO],
            ['table_name', $this->getTable()],
        ]);
    }

    /**
     * Получение связки логотипа
     */
    public function joinLogoImage(): BelongsTo
    {
        return $this->belongsTo(JoinImg::class, 'id', 'item_id')
            ->where([
                ['head_id', Status::CODE_BRAND_LOGO],
                ['table_name', $this->getTable()],
            ]);
    }

    /**
     * Scope: только активные бренды
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: фильтрация
     */
    public function scopeFilter(Builder $query, ?array $args = null): Builder
    {
        $isActive = $args['is_active'] ?? request()->input('is_active');
        $search = $args['search'] ?? request()->input('search');

        if ($isActive !== null) {
            $query->where('is_active', filter_var($isActive, FILTER_VALIDATE_BOOLEAN));
        }

        if ($search !== null && $search !== '') {
            $query->where('title', 'like', '%' . $search . '%');
        }

        return $query;
    }
}
