<?php

namespace App\Models;

use App\Services\ZoneChecker;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;


class Shop extends Model
{
  use HasFactory, ModelTools, SoftDeletes;

  public function products(): HasMany
  {
    return $this->hasMany(Product::class);
  }

  public function categories(): BelongsToMany
  {
    return $this->belongsToMany(Category::class, 'shops_categories');
  }

  public function img(): ?HasOneThrough
  {
    return $this->hasOneThrough(Img::class, JoinImg::class,
      'item_id',
      'id',
      'id',
      'img_id')
      ->where([
        ["head_id", Status::CODE_HEAD_IMG],
        ["table_name", $this->getTable()]
      ]);
  }

  public function logoImg(): ?HasOneThrough
  {
    return $this->hasOneThrough(Img::class, JoinImg::class,
      'item_id',
      'id',
      'id',
      'img_id')
      ->where([
        ["head_id", Status::CODE_LOGO_IMG],
        ["table_name", $this->getTable()]
      ]);
  }

  public function logo_img(): ?HasOneThrough
  {
    return $this->logoImg();
  }

  public function backgroundImg(): ?HasOneThrough
  {
    return $this->hasOneThrough(Img::class, JoinImg::class,
      'item_id',
      'id',
      'id',
      'img_id')
      ->where([
        ["head_id", Status::CODE_BACKGROUND_IMG],
        ["table_name", $this->getTable()]
      ]);
  }

  public function zones(): HasMany
  {
    return $this->hasMany(ShopZone::class);
  }

  public function scopeAuth($query)
  {
    $address = Auth::user()?->deliveryInfo?->selectedAddress;
    if (!$address) {
      return $query->whereNull('id');
    }
    $zones = ZoneChecker::getAvailableZones($address->latitude, $address->longitude);

    return $query->whereHas('zones', fn($query) => $query->whereIn('shop_zones.id', $zones));
  }

  public function reviews(): \Illuminate\Database\Eloquent\Relations\MorphMany
  {
    return $this->morphMany(Review::class,'reviewable');
  }

  public function myReview(): \Illuminate\Database\Eloquent\Relations\MorphOne
  {
    return $this->morphOne(Review::class,'reviewable')->where('client_id',Auth::id());
  }

  public function reviewsAvgMark(): float|int|null
  {
    return $this->reviews->avg('main_mark');
  }
}
