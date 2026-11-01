<?php

namespace App\Models;

use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;


class Category extends Model
{
  use HasFactory, ModelTools, HasImage, SoftDeletes;

  public function childCategories(): ?HasMany
  {
    return $this->hasMany(Category::class, 'parent_id', 'id');
  }

  public function siteCatalogImg(): ?HasOneThrough
  {
    return $this->hasOneThrough(Img::class, JoinImg::class,
      'item_id',
      'id',
      'id',
      'img_id')
      ->where([
        ["head_id", Status::CODE_SITE_CATALOG_IMG],
        ["table_name", $this->getTable()]
      ]);
  }

  public function siteSquareImg(): ?HasOneThrough
  {
    return $this->hasOneThrough(Img::class, JoinImg::class,
      'item_id',
      'id',
      'id',
      'img_id')
      ->where([
        ["head_id", Status::CODE_SITE_SQUARE_IMG],
        ["table_name", $this->getTable()]
      ]);
  }

  public function siteRectImg(): ?HasOneThrough
  {
    return $this->hasOneThrough(Img::class, JoinImg::class,
      'item_id',
      'id',
      'id',
      'img_id')
      ->where([
        ["head_id", Status::CODE_SITE_RECT_IMG],
        ["table_name", $this->getTable()]
      ]);
  }

  public function mobileColumnImg(): ?HasOneThrough
  {
    return $this->hasOneThrough(Img::class, JoinImg::class,
      'item_id',
      'id',
      'id',
      'img_id')
      ->where([
        ["head_id", Status::CODE_MOB_COLUMN_IMG],
        ["table_name", $this->getTable()]
      ]);
  }

  public function mobileSquareImg(): ?HasOneThrough
  {
    return $this->hasOneThrough(Img::class, JoinImg::class,
      'item_id',
      'id',
      'id',
      'img_id')
      ->where([
        ["head_id", Status::CODE_MOB_SQUARE_IMG],
        ["table_name", $this->getTable()]
      ]);
  }

  public function mobileRectImg(): ?HasOneThrough
  {
    return $this->hasOneThrough(Img::class, JoinImg::class,
      'item_id',
      'id',
      'id',
      'img_id')
      ->where([
        ["head_id", Status::CODE_MOB_RECT_IMG],
        ["table_name", $this->getTable()]
      ]);
  }

  public function products(): BelongsToMany
  {
    return $this->belongsToMany(Product::class);
  }

  public function parent(): ?HasOne
  {
    return $this->hasOne(self::class,'id','parent_id');
  }

  public function scopeParent($query)
  {
    $query->whereNull('parent_id');
  }

  public function scopeImportant($query)
  {
    $query->where('important', 1);
  }

  public function scopeOnlyChild($query): void
  {
    $query->whereDoesntHave('childCategories');
  }

  public function hasChildren(): bool
  {
    return $this->childCategories->isNotEmpty();
  }

}
