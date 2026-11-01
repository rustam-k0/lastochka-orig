<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductGroupFillType;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

final class ProductGroup extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'store_id',
        'source',
        'external_id',
        'title',
        'slug',
        'description',
        'image_path',
        'meta',
        'show_on_store_page',
        'show_in_catalog',
        'show_on_home',
        'show_on_search',
        'is_recommended',
        'is_cart_page',
        'is_complex_solution',
        'position',
        'is_active',
        'is_recommended',
        'is_cart_page',
        'custom_checkboxes',
        'fill_type',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'fill_type' => 'manual',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'show_on_store_page' => 'boolean',
            'show_in_catalog' => 'boolean',
            'show_on_home' => 'boolean',
            'show_on_search' => 'boolean',
            'is_recommended' => 'boolean',
            'is_cart_page' => 'boolean',
            'is_active' => 'boolean',
            'is_recommended' => 'boolean',
            'is_cart_page' => 'boolean',
            'is_complex_solution' => 'boolean',
            'custom_checkboxes' => 'array',
        ];
    }

    // ==================== Relations ====================

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(ProductGroupFavorite::class);
    }

    public function isFavorite(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->favorites()->where('user_id', $user->id)->exists();
    }

    /**
     * Способ наполнения группы. Строку в enum не кастуем: неизвестное значение
     * (например, из будущей версии админки) не должно ронять выдачу — оно
     * трактуется как manual.
     */
    public function fillType(): ProductGroupFillType
    {
        return ProductGroupFillType::fromValue($this->fill_type);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductGroupItem::class);
    }

    /**
     * Товары подборки.
     *
     * В сетевом каталоге видимость товара считается по офферу магазина САМОЙ
     * группы (product_groups.store_id): цена и остаток карточки там нулевые.
     * Магазин берём подзапросом через pivot, а не из `$this->store_id`, —
     * Laravel строит связь на ПУСТОЙ модели при eager load и withCount
     * (счётчик productsCount), а pivot в запросе есть всегда.
     *
     * Цену/остаток в выдачу подставляет {@see \App\Services\Catalog\StoreOfferContext} на стороне
     * контроллеров: у каждой группы свой магазин, одним eager load'ом
     * офферы не подтянуть.
     */
    public function products(): BelongsToMany
    {
        // products.id — tie-break для одинаковых position (детерминированная
        // пагинация в ProductGroupController::products()).
        $relation = $this->belongsToMany(Product::class, 'product_group_items')
            ->withPivot('position', 'quantity')
            ->orderByPivot('position')
            ->orderBy('products.id');

        if (! app(ModuleConfigService::class)->isNetworkCatalogEnabled()) {
            return $relation->purchasable();
        }

        return $relation->purchasableInNetworkStore(
            DB::raw('(select pg.store_id from product_groups pg where pg.id = product_group_items.product_group_id)')
        );
    }

    public function filterValues(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductGroupFilterValue::class,
            'product_group_filter_value',
            'product_group_id',
            'product_group_filter_value_id'
        );
    }

    // ==================== Scopes ====================

    /**
     * Только активные группы.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Группы для страницы мерчанта.
     */
    public function scopeForStorePage($query)
    {
        return $query->where('show_on_store_page', true);
    }

    /**
     * Группы для каталога.
     */
    public function scopeForCatalog($query)
    {
        return $query->where('show_in_catalog', true);
    }

    /**
     * Группы для главной страницы.
     */
    public function scopeForHome($query)
    {
        return $query->where('show_on_home', true);
    }

    /**
     * Группы для страницы поиска.
     */
    public function scopeForSearch($query)
    {
        return $query->where('show_on_search', true);
    }

    /**
     * Рекомендованные группы.
     */
    public function scopeRecommended($query)
    {
        return $query->where('is_recommended', true);
    }

    /**
     * Группы для страницы корзины.
     */
    public function scopeForCartPage($query)
    {
        return $query->where('is_cart_page', true);
    }

    /**
     * Только комплексные решения (фасетный каталог).
     */
    public function scopeComplexSolution($query)
    {
        return $query->where('is_complex_solution', true);
    }

    /**
     * Обычные группы без комплексных решений.
     */
    public function scopeWithoutComplexSolutions($query)
    {
        return $query->where('is_complex_solution', false);
    }
}
