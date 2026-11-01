<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Справочник 1С-категорий, синхронизируемый из 1c_parser через RabbitMQ.
 * Привязка к витринным (admin) категориям — через pivot `category_1c_category`.
 *
 * См. docs/superpowers/specs/2026-05-05-1c-categories-mapping-design.md §3.4.
 *
 * @mixin IdeHelperCategory1c
 */
class Category1c extends Model
{
  use HasFactory, SoftDeletes;

  protected $table = 'categories_1c';

  protected $fillable = [
    'shop_id',
    'external_id',
    'parent_id',
    'parent_external_id',
    'name',
    'position',
    'is_active',
    'linked_at',
    'last_synced_at',
    'synced_from_1c_at',
    'quantum_grams',
    'quantum_auto_recalc',
  ];

  protected $casts = [
    'is_active' => 'boolean',
    'linked_at' => 'datetime',
    'last_synced_at' => 'datetime',
    'synced_from_1c_at' => 'datetime',
    'quantum_grams' => 'integer',
    'quantum_auto_recalc' => 'boolean',
  ];

  public function shop(): BelongsTo
  {
    return $this->belongsTo(Shop::class);
  }

  public function parent(): BelongsTo
  {
    return $this->belongsTo(self::class, 'parent_id');
  }

  public function children(): HasMany
  {
    return $this->hasMany(self::class, 'parent_id');
  }

  public function showcaseCategories(): BelongsToMany
  {
    return $this->belongsToMany(
      Category::class,
      'category_1c_category',
      'categories_1c_id',
      'category_id'
    )
      ->withPivot('position')
      ->orderBy('category_1c_category.position');
  }

  public function products(): HasMany
  {
    return $this->hasMany(Product::class, 'category_1c_id');
  }

  /**
   * Является ли категория листом (нет дочерних).
   */
  public function getIsLeafAttribute(): bool
  {
    return $this->children()->count() === 0;
  }

  /**
   * Привязана ли к витринной категории.
   *
   * При eager-loaded relation (tree-запрос) — БЕЗ SQL: на 6206 категориях
   * per-row exists() давал 2 подзапроса на строку и раздувал загрузку
   * раздела «Категории 1С» до минут.
   *
   * ⚠ Если на инстансе с загруженным relation сделать attach/detach pivot —
   * снапшот устареет и is_linked/is_new соврут; после мутации pivot читать
   * флаги только с fresh()/новой выборки (мутации Link/Unlink так и делают).
   */
  public function getIsLinkedAttribute(): bool
  {
    if ($this->relationLoaded('showcaseCategories')) {
      return $this->showcaseCategories->isNotEmpty();
    }

    return $this->showcaseCategories()->exists();
  }

  /**
   * Удалена ли в источнике (1С).
   */
  public function getIsSourceDeletedAttribute(): bool
  {
    return $this->trashed();
  }

  /**
   * Новая категория из 1С (не старше 30 дней) и ещё не привязана.
   * «Не привязана» переиспользует is_linked (учитывает eager-loaded relation).
   */
  public function getIsNewAttribute(): bool
  {
    return $this->created_at !== null
      && $this->created_at->greaterThan(Carbon::now()->subDays(30))
      && !$this->is_linked;
  }

  /**
   * Фильтр по статусу привязки (LINKED / UNLINKED / ALL).
   */
  public function scopeLinkedStatus(Builder $query, ?string $status): Builder
  {
    return match ($status) {
      'LINKED' => $query->whereHas('showcaseCategories'),
      'UNLINKED' => $query->whereDoesntHave('showcaseCategories'),
      default => $query,
    };
  }

  /**
   * Поиск по части названия (case-insensitive LIKE).
   */
  public function scopeSearchByName(Builder $query, ?string $term): Builder
  {
    if ($term === null || $term === '') {
      return $query;
    }
    return $query->where('name', 'like', '%' . $term . '%');
  }

  /**
   * Фильтр по родителю. null означает "корневые" (parent_id IS NULL).
   */
  public function scopeFilterByParent(Builder $query, $parentId): Builder
  {
    if ($parentId === null) {
      return $query;
    }
    return $query->where('parent_id', $parentId);
  }

  /**
   * Только листья (без children).
   */
  public function scopeOnlyLeaves(Builder $query, bool $only = false): Builder
  {
    if (!$only) {
      return $query;
    }
    return $query->whereDoesntHave('children');
  }

  /**
   * Включать ли soft-deleted (по умолчанию global scope SoftDeletes их прячет).
   */
  public function scopeIncludeDeleted(Builder $query, bool $include = false): Builder
  {
    if (!$include) {
      return $query;
    }
    return $query->withTrashed();
  }
}
