<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @mixin IdeHelperImg
 */
class Img extends Model
{
  const table = "img";
  protected $table = self::table;

  protected $fillable = ["path", "origin_name", "alt", "size", "type"];
}
