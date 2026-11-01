<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Settings\ModuleConfigService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Category extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'source',
        'parent_id',
        'name',
        'slug',
        'image_path',
        'background_image_path',
        'position',
        'is_active',
        'is_perishable',
        'show_on_main',
        'tag_text',
        'tag_color',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'custom_checkboxes',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
            'is_perishable' => 'boolean',
            'show_on_main' => 'boolean',
            'custom_checkboxes' => 'array',
        ];
    }

    // ==================== Relations ====================

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'category_store')
            ->withPivot(['position', 'is_active'])
            ->withTimestamps();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)
            ->withPivot('position');
    }

    public function comparisons(): HasMany
    {
        return $this->hasMany(Comparison::class);
    }

    // ==================== Helpers ====================

    /**
     * ID всех категорий поддерева: сама категория + вложенные подкатегории
     * на любую глубину. Уже собранные id повторно не обходятся — защита
     * от зацикливания при некорректном parent_id.
     *
     * @return list<int>
     */
    public function subtreeIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];

        while ($frontier !== []) {
            $frontier = self::query()
                ->whereIn('parent_id', $frontier)
                ->whereNotIn('id', $ids)
                ->pluck('id')
                ->all();

            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    // ==================== Scopes ====================

    public function scopeActive($query)
    {
        return $query->where('categories.is_active', true);
    }

    public function scopeRoot($query)
    {
        return $query->whereNull('categories.parent_id');
    }

    /**
     * Категория видима в магазине.
     *
     * Легаси: привязка через pivot `category_store` (активная строка).
     * Сетевой каталог: дерево категорий общее на всю сеть — 1С отдаёт
     * категории привязанными к одному магазину, поэтому pivot-фильтр
     * оставил бы витрины всех остальных магазинов с пустым деревом.
     * Видимость в сетевом режиме определяет только `categories.is_active`
     * (его проверяет scopeActive у вызывающих).
     */
    public function scopeForStore($query, int $storeId)
    {
        if (self::networkCatalogEnabled()) {
            return $query;
        }

        return $query->whereHas('stores', fn ($q) => $q->where('stores.id', $storeId)
            ->where('category_store.is_active', true)
        );
    }

    /**
     * То же, что {@see self::scopeForStore()}, но для набора магазинов
     * (подсказки поиска по всем доступным пользователю магазинам).
     *
     * @param  array<int, int>  $storeIds
     */
    public function scopeForStores($query, array $storeIds)
    {
        if (self::networkCatalogEnabled()) {
            return $query;
        }

        return $query->whereHas('stores', fn ($q) => $q->whereIn('stores.id', $storeIds)
            ->where('category_store.is_active', true)
        );
    }

    /**
     * Категория привязана к магазину БЕЗ проверки активности привязки —
     * ровно семантика прежних inline-выборок по slug (slug уникален в
     * рамках магазина, а не глобально).
     *
     * В сетевом режиме привязок нет: slug резолвится по всей сети.
     */
    public function scopeAttachedToStore($query, int $storeId)
    {
        if (self::networkCatalogEnabled()) {
            return $query;
        }

        return $query->whereHas('stores', fn ($q) => $q->where('stores.id', $storeId));
    }

    /**
     * Видимые в магазине категории в порядке вывода витрины.
     *
     * Легаси: JOIN на pivot + порядок из `category_store.position`.
     * Сетевой каталог: без JOIN (см. scopeForStore) и порядок из
     * глобального `categories.position`.
     */
    public function scopeOrderedForStore($query, int $storeId)
    {
        if (self::networkCatalogEnabled()) {
            return $query
                ->orderBy('categories.position')
                ->orderBy('categories.name')
                ->select('categories.*');
        }

        return $query
            ->join('category_store', 'categories.id', '=', 'category_store.category_id')
            ->where('category_store.store_id', $storeId)
            ->where('category_store.is_active', true)
            ->orderBy('category_store.position')
            ->orderBy('categories.name')
            ->select('categories.*');
    }

    /**
     * Колонка позиции категории в магазине для запросов, которым нужно
     * вытащить её значение отдельным select'ом поверх orderedForStore().
     * В сетевом режиме pivot в запросе нет — позиция глобальная.
     */
    public static function storePositionColumn(): string
    {
        return self::networkCatalogEnabled()
            ? 'categories.position'
            : 'category_store.position';
    }

    private static function networkCatalogEnabled(): bool
    {
        return app(ModuleConfigService::class)->isNetworkCatalogEnabled();
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('position')->orderBy('name');
    }

    public function scopeShowOnMain($query)
    {
        return $query->where('categories.show_on_main', true);
    }
}
