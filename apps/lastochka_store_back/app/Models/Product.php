<?php

namespace App\Models;

use App\Contracts\OnlyActive;
use App\Models\Scopes\ActiveScope;
use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperProduct
 */
class Product extends Model implements OnlyActive
{
  use HasFactory, ModelTools, HasImage, SoftDeletes;

  /**
   * The "booted" method of the model.
   *
   * @return void
   */
  protected static function booted()
  {
//    static::addGlobalScope('onlyWithPrice', function ($builder) {
//      $builder->whereHas('price');
//    });

//    static::addGlobalScope(new ActiveScope);
  }

  public function brand(): ?BelongsTo
  {
    return $this->belongsTo(Brand::class);
  }

  public function country(): ?BelongsTo
  {
    return $this->belongsTo(Country::class);
  }

  public function manufacturer(): ?BelongsTo
  {
    return $this->belongsTo(Manufacturer::class);
  }

  public function price(): ?HasOne
  {
    return $this->hasOne(Price::class)->whereTypeId(Type::PRICE_DEFAULT);
  }

  public function remains(): ?HasMany
  {
    return $this->hasMany(Remain::class);
  }

  public function remain(): ?HasMany
  {
    return $this->hasMany(Remain::class);
  }

  public function categories(): ?BelongsToMany
  {
    return $this->belongsToMany(Category::class);
  }

  public function attributes(): ?HasMany
  {
    return $this->hasMany(ProductAttribute::class);
  }

  public function parameters(): ?HasMany
  {
    return $this->hasMany(AdditionalParameter::class);
  }

  public function images(): ?HasManyThrough
  {
    return $this->hasManyThrough(Img::class, JoinImg::class,
      'item_id',
      'id',
      'id',
      'img_id')
      ->where([
        ["head_id", Status::CODE_SLIDER_IMG],
        ["table_name", $this->getTable()]
      ]);
  }

  public function productSets(): ?BelongsToMany
  {
    return $this->belongsToMany(ProductSet::class);
  }

  public function cookTime(): ?HasOne
  {
    return $this->hasOne(CookTime::class);
  }

  public function scopeCurrentShop($query)
  {
    return $query->whereHas('price', function ($query) {
      $query->currentShop();
    });
  }

  public function reviews(): \Illuminate\Database\Eloquent\Relations\MorphMany
  {
    return $this->morphMany(Review::class,'reviewable');
  }

  public function reviewsAvgMark(): float|int|null
  {
    return $this->reviews->avg('main_mark');
  }

  public function myReview(): \Illuminate\Database\Eloquent\Relations\MorphOne
  {
    return $this->morphOne(Review::class,'reviewable')->where('client_id',\Auth::id());
  }

  public function supplements(): HasMany
  {
    return $this->hasMany(Supplement::class);
  }

}
