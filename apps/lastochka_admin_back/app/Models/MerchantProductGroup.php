<?php

namespace App\Models;

use App\Enums\ProductGroupFillType;
use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin IdeHelperMerchantProductGroup
 */
class MerchantProductGroup extends Model
{
    use HasFactory, ModelTools, HasImage, SoftDeletes;

    const table = "merchant_product_groups";
    protected $table = self::table;

    protected $fillable = [
        'shop_id',
        'title',
        'description',
        'meta',
        'show_on_main',
        'show_on_merchant_page',
        'show_in_merchant_catalog',
        'show_on_search',
        'sort_order',
        'is_active',
        'is_recommended',
        'is_cart_page',
        'is_complex_solution',
        'custom_checkboxes',
        'fill_type',
    ];

    protected $casts = [
        'meta' => 'array',
        'show_on_main' => 'boolean',
        'show_on_merchant_page' => 'boolean',
        'show_in_merchant_catalog' => 'boolean',
        'show_on_search' => 'boolean',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'is_recommended' => 'boolean',
        'is_cart_page' => 'boolean',
        'is_complex_solution' => 'boolean',
        // Значения — App\Enums\ProductGroupFillType. Каст строковый, а не enum:
        // поле отдаётся в GraphQL как String, а enum-объект String-скаляр не сериализует.
        'fill_type' => 'string',
    ];

    protected $attributes = [
        'show_on_main' => false,
        'show_on_merchant_page' => true,
        'show_in_merchant_catalog' => false,
        'show_on_search' => false,
        'sort_order' => 0,
        'is_active' => true,
        'is_recommended' => false,
        'is_cart_page' => false,
        'is_complex_solution' => false,
        'fill_type' => 'manual',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->link = self::getUniqueLink($model->title);
        });
        static::updating(function ($model) {
            $model->link = self::getUniqueLink($model->title, $model->id);
        });
    }

    /**
     * Получение магазина
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Получение товаров группы
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'merchant_product_group_items',
            'merchant_product_group_id',
            'product_id'
        )->withPivot('sort_order', 'quantity', 'created_at')
         ->orderByPivot('sort_order');
    }

    public function filterValues(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductGroupFilterValue::class,
            'merchant_product_group_filter_values',
            'merchant_product_group_id',
            'product_group_filter_value_id'
        );
    }

    /**
     * Получение изображения группы
     */
    public function image(): HasOneThrough
    {
        return $this->hasOneThrough(
            Img::class,
            JoinImg::class,
            'item_id',
            'id',
            'id',
            'img_id'
        )->where([
            ['head_id', Status::CODE_MERCHANT_PRODUCT_GROUP_IMG],
            ['table_name', $this->getTable()],
        ]);
    }

    /**
     * Получение связки изображения
     */
    public function joinImage(): BelongsTo
    {
        return $this->belongsTo(JoinImg::class, 'id', 'item_id')
            ->where([
                ['head_id', Status::CODE_MERCHANT_PRODUCT_GROUP_IMG],
                ['table_name', $this->getTable()],
            ]);
    }

    /**
     * Количество товаров в группе
     */
    public function getProductsCountAttribute(): int
    {
        return $this->products()->count();
    }

    /**
     * Scope: только активные группы
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: группы на главной
     */
    public function scopeOnMain(Builder $query): Builder
    {
        return $query->where('show_on_main', true);
    }

    /**
     * Scope: группы на странице мерчанта
     */
    public function scopeOnMerchantPage(Builder $query): Builder
    {
        return $query->where('show_on_merchant_page', true);
    }

    /**
     * Scope: группы в каталоге мерчанта
     */
    public function scopeInMerchantCatalog(Builder $query): Builder
    {
        return $query->where('show_in_merchant_catalog', true);
    }

    /**
     * Scope: группы на странице поиска
     */
    public function scopeOnSearch(Builder $query): Builder
    {
        return $query->where('show_on_search', true);
    }

    /**
     * Scope: рекомендуемые группы
     */
    public function scopeRecommended(Builder $query): Builder
    {
        return $query->where('is_recommended', true);
    }

    /**
     * Scope: группы для страницы корзины
     */
    public function scopeCartPage(Builder $query): Builder
    {
        return $query->where('is_cart_page', true);
    }

    /**
     * Scope: комплексные решения
     */
    public function scopeComplexSolution(Builder $query): Builder
    {
        return $query->where('is_complex_solution', true);
    }

    /**
     * Scope: фильтрация по магазинам текущего пользователя
     * Админ видит всё, селлер — только свои магазины
     */
    public function scopeForCurrentUser(Builder $query): Builder
    {
        $user = Auth::user();

        if (!$user || $user->isGlobalScope()) {
            return $query;
        }

        $shopIds = $user->shops()->pluck('shop_id')->toArray();

        return $query->whereIn('shop_id', $shopIds);
    }

    /**
     * Scope: фильтрация
     */
    public function scopeFilter(Builder $query, ?array $args = null): Builder
    {
        // Автоматически применяем фильтр по пользователю
        $query->forCurrentUser();
        $shopId = $args['shop_id'] ?? request()->input('shop_id');
        $isActive = $args['is_active'] ?? request()->input('is_active');
        $showOnMain = $args['show_on_main'] ?? request()->input('show_on_main');
        $showOnMerchantPage = $args['show_on_merchant_page'] ?? request()->input('show_on_merchant_page');
        $showInMerchantCatalog = $args['show_in_merchant_catalog'] ?? request()->input('show_in_merchant_catalog');
        $showOnSearch = $args['show_on_search'] ?? request()->input('show_on_search');
        $isRecommended = $args['is_recommended'] ?? request()->input('is_recommended');
        $isCartPage = $args['is_cart_page'] ?? request()->input('is_cart_page');
        $isComplexSolution = $args['is_complex_solution'] ?? request()->input('is_complex_solution');
        $search = $args['search'] ?? request()->input('search');

        if ($shopId !== null) {
            $query->where('shop_id', $shopId);
        }

        if ($isActive !== null) {
            $query->where('is_active', filter_var($isActive, FILTER_VALIDATE_BOOLEAN));
        }

        if ($showOnMain !== null) {
            $query->where('show_on_main', filter_var($showOnMain, FILTER_VALIDATE_BOOLEAN));
        }

        if ($showOnMerchantPage !== null) {
            $query->where('show_on_merchant_page', filter_var($showOnMerchantPage, FILTER_VALIDATE_BOOLEAN));
        }

        if ($showInMerchantCatalog !== null) {
            $query->where('show_in_merchant_catalog', filter_var($showInMerchantCatalog, FILTER_VALIDATE_BOOLEAN));
        }

        if ($showOnSearch !== null) {
            $query->where('show_on_search', filter_var($showOnSearch, FILTER_VALIDATE_BOOLEAN));
        }

        if ($isRecommended !== null) {
            $query->where('is_recommended', filter_var($isRecommended, FILTER_VALIDATE_BOOLEAN));
        }

        if ($isCartPage !== null) {
            $query->where('is_cart_page', filter_var($isCartPage, FILTER_VALIDATE_BOOLEAN));
        }

        if ($isComplexSolution !== null) {
            $query->where('is_complex_solution', filter_var($isComplexSolution, FILTER_VALIDATE_BOOLEAN));
        }

        if ($search !== null && $search !== '') {
            $query->where('title', 'like', '%' . $search . '%');
        }

        return $query;
    }

    /**
     * Способ наполнения группы (null и неизвестное значение = manual).
     */
    public function fillType(): ProductGroupFillType
    {
        return ProductGroupFillType::tryFrom((string) $this->fill_type) ?? ProductGroupFillType::MANUAL;
    }

    /**
     * Синхронизация товаров группы
     */
    /**
     * Синхронизация товаров группы.
     *
     * @param array $products Массив ID или массив [{id, quantity}]
     */
    public function syncProducts(array $products): void
    {
        $syncData = [];
        foreach ($products as $index => $item) {
            if (is_array($item) || is_object($item)) {
                $item = (array) $item;
                $productId = $item['id'] ?? $item['product_id'] ?? null;
                $quantity = $item['quantity'] ?? 1;
            } else {
                $productId = $item;
                $quantity = 1;
            }
            if ($productId) {
                $syncData[(int) $productId] = [
                    'sort_order' => $index,
                    'quantity' => max(1, (int) $quantity),
                ];
            }
        }
        $this->products()->sync($syncData);
    }
}
