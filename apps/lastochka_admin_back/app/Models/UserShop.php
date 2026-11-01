<?php

namespace App\Models;

use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;


class UserShop extends Model
{
  use SoftDeletes;

  protected $fillable = [
    'shop_id',
    'user_id',
  ];


  public function workingHours(): HasMany
  {
    return $this->hasMany(UserWorkingHour::class)->orderBy('day_of_week');
  }

  public function shop(): BelongsTo
  {
    return $this->belongsTo(Shop::class);
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  public function getWorkingDaysAsArray()
  {
    return $this->workingHours->pluck('day_of_week');
  }

  public function getGetOpeningTime()
  {
    return $this->workingHours?->first()?->opening_time;
  }

  public function getGetClosingTime()
  {
    return $this->workingHours?->first()?->closing_time;
  }
}
