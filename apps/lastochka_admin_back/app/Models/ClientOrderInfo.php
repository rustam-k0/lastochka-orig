<?php

namespace App\Models;

use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperClientOrderInfo
 */
class ClientOrderInfo extends Model
{
  use SoftDeletes;

  protected $fillable = [
    'order_id',
    'name',
    'surname',
    'phone',
    'email',
    'address',
    'latitude',
    'longitude',
    'first_order',
    'entrance',
    'intercom',
    'floor',
    'flat',
    'object_data',
  ];

  protected $casts = [
    'object_data' => 'array',
  ];


}
