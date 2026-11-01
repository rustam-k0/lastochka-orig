<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperAttribute
 */
class Attribute extends Model
{
  use HasFactory, ModelTools, SoftDeletes;

  protected $fillable = [
    'title',
    'code',
    'description',
    'type_id',
    'category_type_id',
    'position',
  ];


  protected static function boot()
  {
    parent::boot();
    static::creating(function ($model) {
      $model->category_type_id = $model->category_type_id ?: Type::ATTRIBUTE_OTHER;
      $model->type_id = $model->type_id ?: Type::ATTRIBUTE_SELECT_ONE;
    });

    // ALADDIN-11991: attributes.code под UNIQUE-индексом, поэтому код,
    // оставшийся у soft-deleted строки, блокировал бы повторное создание
    // атрибута с тем же кодом. Освобождаем код при удалении.
    static::deleted(function (Attribute $model) {
      if ($model->isForceDeleting()) {
        return;
      }

      $suffix = '__del' . $model->id;

      if (str_ends_with((string) $model->code, $suffix)) {
        return;
      }

      $freedCode = mb_substr((string) $model->code, 0, 100 - mb_strlen($suffix)) . $suffix;

      $model->newQueryWithoutScopes()->whereKey($model->id)->update(['code' => $freedCode]);
      $model->code = $freedCode;
    });
  }

  public function defaultAttribute(): ?HasOne
  {
    return $this->hasOne(DefaultAttribute::class);
  }

  public function getIsDefaultAttribute(): bool
  {
    return $this->defaultAttribute()->exists();
  }

  public function productAttributes(): ?HasMany
  {
    return $this->hasMany(ProductAttribute::class);
  }

  public function attributeValues(): HasMany
  {
    return $this->hasMany(AttributeValue::class);
  }

  public function categories(): BelongsToMany
  {
    return $this->belongsToMany(Category::class,"category_attributes");
  }

  public function families(): BelongsToMany
  {
    return $this->belongsToMany(AttributeFamily::class, 'attribute_family_attributes')
        ->withPivot(['id', 'position', 'is_required', 'is_configurable']);
  }

  public function scopeDefault($query): void
  {
    $query->whereHas('defaultAttribute');
  }

  public function scopeNotDefault(Builder $query): void
  {
    $query->whereDoesntHave('defaultAttribute');
  }

  public function scopeWithCategory($query,$category_id)
  {
    $category = Category::find($category_id);
    if (!$category) {
      return;
    }

    $categories_id = [$category->id];

    while ($category?->parent_id) {
      $categories_id[] = $category->parent_id;
      $category = Category::find($category->parent_id);
    }


    $query->whereHas("categories",function ($query)use ($categories_id) {
      $query->whereIn((new Category())->getTable().".id",$categories_id);
    });
  }


}
