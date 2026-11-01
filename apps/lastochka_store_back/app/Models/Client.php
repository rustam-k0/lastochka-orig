<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;


class Client extends Authenticatable
{
  use SoftDeletes, HasApiTokens;

  protected $hidden = ['password'];

  protected $with = [
    'deliveryInfo'
  ];

  public function deliveryInfo(): ?HasOne
  {
    return $this->hasOne(ClientDeliveryInfo::class);
  }

}
