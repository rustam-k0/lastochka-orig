<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperDeliveryAddress
 */
class DeliveryAddress extends Model
{
  use SoftDeletes;

  protected $fillable = [
    'delivery_info_id',
    'address',
    'title',
    'latitude',
    'longitude',
    'selected',
    'entrance',
    'intercom',
    'floor',
    'flat',
    'city',
  ];

  public function deliveryInfo(): HasOne
  {
    return $this->hasOne(ClientDeliveryInfo::class,'id','delivery_info_id');
  }

  public function scopeAuth($query): void
  {
    $query->whereRelation('deliveryInfo','client_id',\Auth::id());
  }


}
