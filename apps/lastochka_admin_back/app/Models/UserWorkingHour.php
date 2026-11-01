<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;


class UserWorkingHour extends Model
{
  use SoftDeletes;

  protected $fillable = [
    'user_shop_id',
    'day_of_week',
    'opening_time',
    'closing_time',
  ];

  public function userShop(): BelongsTo
  {
    return $this->belongsTo(UserShop::class);
  }

}
