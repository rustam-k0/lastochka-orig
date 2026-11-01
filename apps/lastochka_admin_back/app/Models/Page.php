<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Статичная страница витрины: политика конфиденциальности, оферта и прочие.
 *
 * @mixin IdeHelperPage
 */
class Page extends Model
{
    use HasFactory, ModelTools, SoftDeletes;

    const table = "pages";
    protected $table = self::table;

    protected $fillable = [
        'slug',
        'title',
        'content',
        'meta_title',
        'meta_description',
        'position',
        'is_published',
        'published_at',
        'is_system',
    ];

    // Laravel 9: касты объявляются свойством, метод casts() тут не вызывается.
    protected $casts = [
        'is_published' => 'boolean',
        'is_system' => 'boolean',
        'published_at' => 'datetime',
        'position' => 'integer',
    ];

    protected $attributes = [
        'is_published' => false,
        'is_system' => false,
    ];

    /**
     * Scope: только опубликованные страницы
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * Scope: фильтрация списка страниц
     */
    public function scopeFilter(Builder $query, ?array $args = null): Builder
    {
        $search = $args['search'] ?? request()->input('search');
        $isPublished = $args['is_published'] ?? request()->input('is_published');

        // Поиск по названию и коду страницы
        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%' . $search . '%')
                    ->orWhere('slug', 'like', '%' . $search . '%');
            });
        }

        if ($isPublished !== null) {
            $query->where('is_published', filter_var($isPublished, FILTER_VALIDATE_BOOLEAN));
        }

        return $query;
    }

    /**
     * Scope: сортировка. По умолчанию — порядок отображения,
     * записи без позиции уходят в конец списка.
     */
    public function scopeSorter(Builder $query, ?array $args = null): Builder
    {
        $ordering = $args['ordering'] ?? request()->input('ordering');

        if (!empty($ordering)) {
            foreach ($ordering as $order) {
                $query->orderBy($order['orderColumn'] ?? $order['column'], $order['orderBy'] ?? $order['order'] ?? 'asc');
            }

            return $query;
        }

        return $query->orderByRaw('position is null, position')->orderBy('id');
    }
}
