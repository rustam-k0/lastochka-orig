<?php

namespace App\Models;

use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperShop
 */
class Shop extends Model
{
  use SoftDeletes;

  protected $fillable =[
    'title',
    'id_1c'
  ];
}
