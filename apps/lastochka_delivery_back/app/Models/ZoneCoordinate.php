<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ZoneCoordinate extends Model
{
    use SoftDeletes;

    protected $fillable = ['shop_zone_id', 'latitude', 'longitude', 'position'];
}
