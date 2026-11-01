<?php

namespace App\Models;

use App\Models\Scopes\NotFinished;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperDelivery
 */
class Delivery extends Model
{
  use  ModelTools, SoftDeletes;

  const MAX_ORDERS_COUNT = 10;

  protected $fillable = [
    'started_at',
    'finished_at',
    'user_id'
  ];

  protected static function booted(): void
  {
    static::addGlobalScope(new NotFinished());
  }

  public function deliveryOrders(): HasMany
  {
    return $this->hasMany(DeliveryOrder::class);
  }

  public function scopeAuth($query)
  {
    $query->where('user_id', \Auth::id());
  }

}
