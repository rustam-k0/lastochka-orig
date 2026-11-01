<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class ShopWorkingHour extends Model
{
  use SoftDeletes;

  protected $fillable = [
    'shop_id',
    'day_of_week',
    'opening_time',
    'closing_time',
    'type_id',
  ];


}
