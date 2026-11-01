<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin IdeHelperImg
 */
class Img extends Model
{
  const table = "img";
  protected $table = self::table;

  protected $fillable = ["path", "origin_name", "alt", "size", "type"];

  public function links(): HasMany
  {
    return $this->hasMany(ImageLink::class)->orderByDesc('updated_at');
  }
}
