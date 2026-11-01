<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperClientDeliveryInfo
 */
class ClientDeliveryInfo extends Model
{
  use ModelTools, SoftDeletes;

  protected $fillable = [
    'client_id',
    'type_id',
  ];

  public function addresses(): ?HasMany
  {
    return $this->hasMany(DeliveryAddress::class, 'delivery_info_id', 'id');
  }

  public function selectedAddress()
  {
    return $this->hasOne(DeliveryAddress::class, 'delivery_info_id', 'id')
      ->whereSelected(true);

  }

  public function scopeAuth($query)
  {
    $query->where('client_id', \Auth::id());
  }


}
