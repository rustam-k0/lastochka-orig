<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperZoneCoordinate
 */
class ZoneCoordinate extends Model
{
  use HasFactory, SoftDeletes;

  protected $fillable = [
    'shop_zone_id',
    'latitude',
    'longitude',
    'position',
  ];
}
