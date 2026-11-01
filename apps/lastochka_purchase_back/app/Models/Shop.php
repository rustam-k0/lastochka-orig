<?php

namespace App\Models;

use App\Traits\HasImage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperShop
 */
class Shop extends Model
{
  use HasFactory, SoftDeletes, HasImage;

  public function workingHours(): HasMany
  {
    return $this->hasMany(ShopWorkingHour::class)->orderBy('day_of_week');
  }

  public function orders(): HasMany
  {
    return $this->hasMany(Order::class);
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

  public function zones(): HasMany
  {
    return $this->hasMany(ShopZone::class);
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


  public function reviews(): \Illuminate\Database\Eloquent\Relations\MorphMany
  {
    return $this->morphMany(Review::class,'reviewable');
  }
}
