<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperClientDeliveryInfo
 */
class ClientDeliveryInfo extends Model
{
  use SoftDeletes;

  public function selectedAddress()
  {
    return $this->hasOne(DeliveryAddress::class, 'delivery_info_id', 'id')
      ->whereSelected(true);

  }
}
